# Changelog

All notable changes to the Neos 9 line of this package (`neos9` branch) are documented
in this file. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [3.0.0] - 2026-09-29

### Added

- **Frontend search over pages and PDFs** (`Search\SearchService`, Fusion helper
  `AssetIndexing.Search`). One relevance-ranked list, one hit per PDF with the pages
  the term was found on and deep links into the PDF. A Meilisearch outage is returned
  as `SearchUnavailable` and logged instead of looking like an empty result. Moved
  here from a project package.

### Changed

- **BREAKING: requires `medienreaktor/meilisearch` ^3.0**, whose `filterableAttributes`
  is a map. This package now adds `__isAsset: true` instead of a list entry, which
  could overwrite another package's attribute depending on load order.

### Fixed

- Reindexing or removing an asset deletes all of its documents. The lookup was a search
  limited to 20 hits, while a PDF has one document per chunk and dimension, so stale
  chunks stayed in the index.
