# Medienreaktor.Meilisearch.AssetIndexing

Robust asset indexing for Neos CMS + Meilisearch focusing on high quality PDF text extraction and adaptive, RAG‑friendly chunk generation.

> Note: Determining *which* assets (and for which dimension combinations) are indexed relies on **Flowpack.EntityUsage** (package `flowpack/neos-asset-usage`). Only assets referenced via EntityUsage (service `neos_cr`) are considered. Ensure the asset is actually used (referenced in content) before starting an index run.
> If usages seem outdated, refresh them with:
> ```bash
> ./flow assetusage:update
> ```
> (See https://github.com/Flowpack/Flowpack.Neos.AssetUsage )

## 1. Overview
This package augments a unified Meilisearch index with media asset documents (e.g. PDFs, images). PDF files are split into semantically aware chunks for improved semantic search and retrieval‑augmented generation (RAG) scenarios. Non‑PDF assets are indexed as single descriptive documents.

## 2. Key Features
- Unified index: Assets appear alongside Node documents using shared supertype markers.
- Adaptive PDF chunking (structure + density driven, 1.4k–3.6k char targets, capped 900–3800).
- Automatic section detection (page + heading boundaries, forward‑merge of tiny sections).
- Dynamic target sizing based on heading / paragraph / list densities & average paragraph length.
- Optional single‑document mode (chunking can be disabled via configuration).
- Per‑chunk deep link URIs (`asset://<id>#page=<firstPage>`).
- Tag label extraction appended to searchable text as a dedicated section.
- Dimension awareness (hash per dimension combination) – mirrors content dimension model.
- Clean identifier scheme supporting stable chunk suffixes.

## 3. How It Works
1. Asset usages (via Flowpack.EntityUsage) drive which dimension variants are indexed.  
2. For each distinct dimensions hash:  
   - Non‑PDF -> one document.  
   - PDF -> page text extraction (spatie/pdftotext, fallback Smalot), structural analysis, adaptive greedy chunk build.  
3. Documents are batched into Meilisearch through an injected `IndexInterface` abstraction.  
4. Tag labels (if supported by the asset implementation) are appended to the chunk text and stored separately in `tags`.

## 4. Requirements
- Neos / Flow environment with Flowpack.EntityUsage.  
- Meilisearch instance (v1+ recommended).  
- `pdftotext` binary (poppler) available for `spatie/pdf-to-text`.  
- PHP extensions: `mbstring`, `json`, `iconv`.  

## 5. Installation
```bash
composer require medienreaktor/meilisearch-asset-indexing
```
(Adjust vendor/name to actual package name if different.)

Ensure the package is loaded (Flow will auto-detect via composer). Run cache flush if necessary:
```bash
./flow flow:cache:flush
```
After installation verify `flowpack/neos-asset-usage` is active (hard requirement). If missing:
```bash
composer require flowpack/neos-asset-usage
```
Then clear caches if needed:
```bash
./flow flow:cache:flush
```

## 5.1 CLI Commands
Flow CLI commands provided by `AssetIndexCommandController`:

Full reindex (purge + rebuild by default):
```bash
./flow assetindex:indexAll
```
Run with purge (additive):
```bash
./flow assetindex:indexAll --purgeDocuments true
```
Shorthand (same as above without purge):
```bash
./flow assetindex:indexassets
```
Reindex a single asset (delete existing docs first):
```bash
./flow assetindex:reindexasset --assetIdentifier <uuid>
```
Remove a single asset from index (all dimension variants):
```bash
./flow assetindex:removeasset --assetIdentifier <uuid>
```
Purge all asset documents only:
```bash
./flow assetindex:purgeindexeddocuments
```

### 5.2 Refreshing Asset Usages
If you added or removed references and they are not reflected yet, run the usage update provided by Flowpack.Neos.AssetUsage before (re)indexing:
```bash
./flow assetusage:update
```
This regenerates the underlying usage table that drives which assets + dimension combinations are indexed. Repository: https://github.com/Flowpack/Flowpack.Neos.AssetUsage

## 6. Configuration
Add (or merge) into your `Configuration/Settings.yaml`:
```yaml
Medienreaktor:
  Meilisearch:
    AssetIndexing:
      chunking:
        enabled: true          # false => each PDF as one full-text document
        targets:
          baseline: 2400        # Neutral baseline target chars
          denseMin: 1400        # Dense content lower bound
          denseMax: 1700        # Dense content upper bound
          narrativeMin: 3200    # Narrative content lower bound
          narrativeMax: 3600    # Narrative content upper bound
          absoluteMin: 900      # Hard floor
          absoluteMax: 3800     # Hard cap / soft split threshold
        heuristics:
          headingDensityHigh: 1.2     # headings / (chars/1000)
          paragraphDensityHigh: 2.0   # paragraphs / (chars/1000)
          listDensityHigh: 0.35       # list lines ratio
          longParagraphLen: 450       # avg paragraph length threshold
          longParagraphLenMaxBoost: 400 # extra chars span toward narrativeMax
        merge:
          smallSectionThreshold: 200  # forward-merge tiny sections
        flush:
          minFillFactor: 0.3          # need 30% of target before early flush allowed
        oversized:
          softSplitCap: 3800          # paragraph split when section exceeds this
        overlap:
          enabled: false              # reserved (not applied yet)
          smallTargetPercent: 0.08
          largeTargetPercent: 0.12
          minChars: 300
      mediaTypeMapping:
        application/pdf: 'Vendor.Site:PdfAsset'
      allowedMediaTypePrefixes:
        - application/pdf
        - image/
```
If `chunking.enabled = false`, PDFs are indexed as a single document with full concatenated text (page breaks separated by a marker).

## 7. PDF Adaptive Chunking
The advanced strategy performs a structure + density analysis:
- Paragraph segmentation by blank lines per page.
- Heading detection (regex heuristics: short lines, numbering, capital patterns, punctuation constraints).
- Section formation: new section at heading or page boundary. Very small sections (< 200 chars) forward-merged.
- Metrics over the current candidate chunk:  
  - `headingDensity = headings / (chars / 1000)`  
  - `paragraphDensity = paragraphs / (chars / 1000)`  
  - `listDensity = listLines / totalLines`  
  - `avgParagraphLen = chars / paragraphs`  
- Target size logic:  
  - Dense (headings > 1.2 / paragraphDensity > 2.0 / listDensity > 0.35) -> 1400–1700 chars (scaled).  
  - Flowing narrative (low heading density + long paragraphs) -> 3200–3600 chars.  
  - Otherwise baseline 2400 chars (clamped 900–3800).  
- Overlap heuristic reserved (currently skipped by default for performance; could be reintroduced).
- Oversized sections (> 3800) soft-split at paragraph boundaries.

Result: Balanced chunks preserving logical context without oversizing list or heading-dense regions.

### 7.1 Tunable Parameters (Advanced)
All important adaptive behaviors are externalized (see section 6). These defaults are tuned for RAG usage with medium-to-large context models (e.g. GPT‑4.1‑mini, future GPT‑5 / reasoning models) over highly technical product documentation:

| Group | Key | Purpose | Rationale |
|-------|-----|---------|-----------|
| targets | baseline | Default chunk goal | Balanced context retention vs. token cost |
| targets | denseMin/denseMax | Window for list/heading-heavy pages | Keeps granular fact tables small |
| targets | narrativeMin/narrativeMax | Window for long flowing paragraphs | Reduces fragmentation of conceptual explanations |
| targets | absoluteMin/absoluteMax | Hard clamps | Guards extremes & model context efficiency |
| heuristics | headingDensityHigh | Trigger threshold for dense mode | >1.2 headings / 1k chars indicates structural segmentation |
| heuristics | paragraphDensityHigh | Alternative dense trigger | High paragraph churn = finer chunks |
| heuristics | listDensityHigh | Dense lists trigger | Prevents bloated list chunks |
| heuristics | longParagraphLen | Narrative trigger start | Sustained long paragraphs => can grow safely |
| heuristics | longParagraphLenMaxBoost | Scale factor toward upper narrative bound | Prevent premature flush |
| merge | smallSectionThreshold | Merge micro sections | Avoid tiny orphan fragments |
| flush | minFillFactor | Prevent ultra-short early flushes | Enforces meaningful chunk mass |
| oversized | softSplitCap | Paragraph split limit | Protects model from giant outliers |
| overlap | * | (Future) sliding-window support | Disabled until evidence justifies token overhead |

Guidance:
- Increase `baseline` cautiously ( >2600 ) only if you often see under-filled model responses that would benefit from more context.
- Lower `denseMin` (e.g. 1200) for extremely dense spec sheets to tighten focus.
- Raise `narrativeMax` (e.g. 4000) only if using very large context windows (>128k tokens) and retrieval latency is acceptable.
- If you observe too many micro-chunks: decrease `flush.minFillFactor` to 0.25 OR increase `smallSectionThreshold` slightly.
- If chunks feel too big in mixed content: reduce `baseline` (e.g. 2200) and/or lower narrative bounds.

## 8. Document Schema (Indexed Fields)
Common fields (non-exhaustive):
- `id` – unique (includes chunk suffix for PDF chunks).
- `__identifier` – base asset id (without chunk suffix).
- `__dimensions`, `__dimensionsHash` – dimension metadata.
- `__nodeType`, `__nodeTypeAndSupertypes` – pseudo type + classification (`Neos.Media:Asset`).
- `__uri` – deep link (PDF includes page anchor).
- `__markdown` – primary textual payload (no strict markdown semantics).
- `__fulltext` – array wrapping text for hybrid search compatibility.
- `__pageStart`, `__pageEnd`, `__pages` – PDF chunk page span metadata.
- `__chunkNumber` – zero-based for PDFs.
- `headings` (PDF chunks) – heading strings present in the chunk.
- `__sectionCount` – number of structural sections aggregated in the chunk.
- `__adaptiveTarget` – final accumulated char size for diagnostic insight.
- `tags` – array of tag labels.

## 9. Identifier Scheme
```
asset_<assetPersistenceId>_<dimensionsHash>[_c<chunkNumber>]
```
Examples:
- Single (image): `asset_123abc_f2e91a`
- First PDF chunk: `asset_123abc_f2e91a_c0`
- Second PDF chunk: `asset_123abc_f2e91a_c1`

## 10. Tag Integration
If an asset exposes `getTags()`, each tag label is extracted (via `getLabel()`), de-duplicated, sorted, and appended under a `### Tags` section at the end of the chunk/base text. Also stored as the `tags` array field.

## 11. Reindexing & Maintenance
Public methods (class `AssetIndexer`):

- `indexAll(bool $purgeDocuments = true)` – New universal entry (optionally skip purge).
- `reindexAssetByIdentifier($assetId)` – Remove + reindex a single asset.
- `removeAsset(...)` / `removeAssetByIdentifier($id)` – Delete all documents of an asset.
- `purgeAllAssetDocuments()` – Delete all asset documents (prefix `asset_`).

CLI mapping see section 5.1.

> Important: Without EntityUsage records (Flowpack.EntityUsage) no dimensions are resolved and an asset will not be indexed. If results are empty, first verify references and the usage table.

## 12. Extensibility Hooks
- `mediaTypeMapping` – Map MIME prefixes to custom pseudo node types.
- `allowedMediaTypePrefixes` – Restrict indexing to an allow-list (e.g. only `application/pdf`).
- Replace `IndexInterface` implementation to adjust search backend specifics (bulk ops, filtering, etc.).
- Extend heading detection or density logic by subclassing (ensure service override in Objects.yaml).

## 13. Performance Notes
- Page count currently derived with Smalot parse; each page then extracted individually via `pdftotext` (O(pages)). For very large PDFs, consider batching ranges or caching the Smalot parse object.
- Iterative purge loop can be replaced by filter-based deletion if `IndexInterface` adds native support.
- Overlap duplication is intentionally omitted to limit index volume; introduce only if retrieval quality demands.

## 14. Roadmap Ideas (Adaptive Config)
- Activate optional overlap with semantic sentence boundary trimming.
- Pluggable heading classifiers (ML driven) before regex fallback.
- Page-level caching of extracted structure keyed by PDF checksum.
- Adaptive re-chunking feedback loop (query success signals).

---
### Feedback
Contributions, issue reports, and suggestions welcome. Provide reproducible examples (asset type, pages, excerpt) for chunking quality discussions.
