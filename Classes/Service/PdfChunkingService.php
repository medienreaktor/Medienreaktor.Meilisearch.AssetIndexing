<?php
declare(strict_types=1);

namespace Medienreaktor\Meilisearch\AssetIndexing\Service;

use Neos\Flow\Annotations as Flow;
use Psr\Log\LoggerInterface;

/**
 * Encapsulates adaptive PDF chunking logic.
 * Returns array of chunks: text, page_start, page_end, pages[], chunkNumber, sectionCount, adaptiveTarget.
 *
 * @Flow\Scope("singleton")
 */
class PdfChunkingService
{
    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Whether adaptive chunking is enabled globally (injected for convenience)
     * @Flow\InjectConfiguration(package="Medienreaktor.Meilisearch.AssetIndexing", path="chunking.enabled")
     * @var bool
     */
    protected $chunkingEnabled = true;

    /** @Flow\InjectConfiguration(package="Medienreaktor.Meilisearch.AssetIndexing", path="chunking.targets") */
    protected array $targets = [];
    /** @Flow\InjectConfiguration(package="Medienreaktor.Meilisearch.AssetIndexing", path="chunking.heuristics") */
    protected array $heuristics = [];
    /** @Flow\InjectConfiguration(package="Medienreaktor.Meilisearch.AssetIndexing", path="chunking.merge") */
    protected array $mergeCfg = [];
    /** @Flow\InjectConfiguration(package="Medienreaktor.Meilisearch.AssetIndexing", path="chunking.flush") */
    protected array $flushCfg = [];
    /** @Flow\InjectConfiguration(package="Medienreaktor.Meilisearch.AssetIndexing", path="chunking.oversized") */
    protected array $oversizedCfg = [];
    /** @Flow\InjectConfiguration(package="Medienreaktor.Meilisearch.AssetIndexing", path="chunking.overlap") */
    protected array $overlapCfg = [];

    /**
     * Build adaptive PDF chunks or single full-text fallback (applies heading markdown when disabled).
     * @param array<int,string> $pageTexts
     * @return array<int,array<string,mixed>>
     */
    public function buildChunks(array $pageTexts): array
    {
        if (!$this->chunkingEnabled) {
            $pageTexts = $this->transformHeadingsToMarkdownPages($pageTexts);
            $full = implode("\n\n--- page break ---\n\n", $pageTexts);
            return [[ 'text' => $full, 'page_start' => 1, 'page_end' => count($pageTexts), 'pages' => array_keys($pageTexts), 'chunkNumber' => 0, 'sectionCount' => 0, 'adaptiveTarget' => mb_strlen($full) ]];
        }
        return $this->buildAdaptive($pageTexts);
    }

    /** @param array<int,string> $pageTexts */
    private function buildAdaptive(array $pageTexts): array
    {
        $structure = $this->extractStructure($pageTexts);
        if ($structure['paragraphs'] === []) {
            return [[ 'text' => '', 'page_start' => 1, 'page_end' => 1, 'pages' => [1], 'chunkNumber' => 0, 'sectionCount' => 0, 'adaptiveTarget' => 0 ]];
        }
        $sections   = $structure['sections'];
        $paragraphs = $structure['paragraphs'];

        $chunks = [];
        $currentSectionGroup = [];
        $currentChars = 0;
        $chunkNumber = 0;

        $appendSection = function(array $section) use (&$currentSectionGroup, &$currentChars) {
            $currentSectionGroup[] = $section;
            $currentChars += $section['chars'];
        };
        $flushChunk = function(int $chunkNumber) use (&$currentSectionGroup, &$paragraphs, &$chunks, &$currentChars) {
            if ($currentSectionGroup === []) { return; }
            $paraIndices = [];
            $pages = [];
            foreach ($currentSectionGroup as $sec) {
                $paraIndices = array_merge($paraIndices, $sec['paragraphIndices']);
                foreach ($sec['pages'] as $p) { $pages[$p] = true; }
            }
            sort($paraIndices, SORT_NUMERIC);
            $textParts = [];
            foreach ($paraIndices as $pi) {
                $para = $paragraphs[$pi];
                $t = (string)$para['text'];
                if (!str_starts_with($t, '#') && !empty($para['isHeading']) && $para['isHeading']) { $t = '## ' . ltrim($t); }
                $textParts[] = $t;
            }
            $text = implode("\n\n", $textParts);
            $pageNumbers = array_keys($pages); sort($pageNumbers, SORT_NUMERIC);
            $chunks[] = [
                'text' => $text,
                'page_start' => $pageNumbers ? min($pageNumbers) : 1,
                'page_end' => $pageNumbers ? max($pageNumbers) : 1,
                'pages' => $pageNumbers ?: [1],
                'chunkNumber' => $chunkNumber,
                'sectionCount' => count($currentSectionGroup),
                'adaptiveTarget' => $currentChars,
            ];
            $currentSectionGroup = [];
            $currentChars = 0;
        };

        foreach ($sections as $section) {
            $absoluteMax = (int)($this->targets['absoluteMax'] ?? 3800);
            $softCap     = (int)($this->oversizedCfg['softSplitCap'] ?? $absoluteMax);
            $maxTarget   = $absoluteMax;
            if ($section['chars'] > $softCap) {
                foreach ($this->softSplitOversized($section, $paragraphs, $softCap) as $subSection) {
                    $this->greedyAdd($subSection, $appendSection, $flushChunk, $paragraphs, $structure, $chunks, $chunkNumber, $currentSectionGroup, $currentChars, $maxTarget);
                }
            } else {
                $this->greedyAdd($section, $appendSection, $flushChunk, $paragraphs, $structure, $chunks, $chunkNumber, $currentSectionGroup, $currentChars, $maxTarget);
            }
        }
        if ($currentSectionGroup !== []) { $flushChunk($chunkNumber++); }
        return $chunks;
    }

    /**
     * Parse raw page texts into paragraphs and logical sections with basic per-page statistics.
     * @param array<int,string> $pageTexts
     * @return array{paragraphs:array<int,array<string,mixed>>,sections:array<int,array<string,mixed>>,perPageStats:array<int,array<string,int|float>>}
     */
    private function extractStructure(array $pageTexts): array
    {
        $paragraphs = [];
        $sections = [];
        $perPageStats = [];
        $globalOffset = 0;
        $paraIndex = 0;

        $headingRegex   = '/^(?:[0-9]+\.|[A-ZÄÖÜ][A-Za-zÄÖÜäöüß0-9 ,;:\\/()\-]{3,60})$/u';
        $listLineRegex  = '/^\s*(?:[\-\*•]|\d+[.)])\s+/u';

        foreach ($pageTexts as $page => $text) {
            if (!is_string($text)) { $text = ''; }
            $normalized = str_replace(["\r\n", "\r"], "\n", $text);
            $lines = preg_split('/\n/u', $normalized) ?: [];
            $lineCount = 0; $listLineCount = 0; $headingCount = 0; $pageParagraphCount = 0; $pageChars = 0;
            $rawParagraphs = preg_split('/\n{2,}/u', trim($normalized)) ?: [];
            foreach ($rawParagraphs as $raw) {
                $p = trim($raw); if ($p === '') { continue; }
                $isHeading = (bool)preg_match($headingRegex, $p);
                $isList = false;
                $plines = preg_split('/\n/u', $p) ?: [];
                foreach ($plines as $pl) { $lineCount++; if (preg_match($listLineRegex, $pl)) { $listLineCount++; $isList = true; } }
                if ($isHeading) { $headingCount++; }
                $chars = mb_strlen($p);
                $paragraphs[$paraIndex] = [
                    'text' => $p,
                    'chars' => $chars,
                    'page' => (int)$page,
                    'isHeading' => $isHeading,
                    'isList' => $isList,
                    'startOffset' => $globalOffset,
                    'lineCount' => count($plines),
                    'listLineCount' => $isList ? count($plines) : 0,
                ];
                $pageParagraphCount++; $pageChars += $chars; $globalOffset += $chars + 2; $paraIndex++;
            }
            if ($lineCount === 0) { $lineCount = count($lines); }
            $avgLen = $pageParagraphCount > 0 ? $pageChars / $pageParagraphCount : 0.0;
            $perPageStats[$page] = [
                'headingCount' => $headingCount,
                'paragraphCount' => $pageParagraphCount,
                'avgParagraphLen' => $avgLen,
                'listLineCount' => $listLineCount,
                'totalLines' => max(1, $lineCount),
            ];
        }

        $currentSection = [ 'paragraphIndices' => [], 'chars' => 0, 'headings' => [], 'pages' => [] ];
        $lastPage = null;
        foreach ($paragraphs as $idx => $p) {
            $page = $p['page'];
            $forceNew = false;
            if ($lastPage !== null && $page !== $lastPage) { $forceNew = true; }
            if ($p['isHeading']) { $forceNew = true; }
            if ($forceNew && $currentSection['paragraphIndices'] !== []) {
                $sections[] = $currentSection;
                $currentSection = [ 'paragraphIndices' => [], 'chars' => 0, 'headings' => [], 'pages' => [] ];
            }
            $currentSection['paragraphIndices'][] = $idx;
            $currentSection['chars'] += $p['chars'];
            if ($p['isHeading']) { $currentSection['headings'][] = $p['text']; }
            $currentSection['pages'][$page] = $page;
            $lastPage = $page;
        }
        if ($currentSection['paragraphIndices'] !== []) { $sections[] = $currentSection; }

        $merged = [];
        $smallSectionThreshold = (int)($this->mergeCfg['smallSectionThreshold'] ?? 200);
        for ($i = 0; $i < count($sections); $i++) {
            $sec = $sections[$i];
            if ($sec['chars'] < $smallSectionThreshold && ($i + 1) < count($sections)) {
                $next = $sections[$i+1];
                $sec['paragraphIndices'] = array_merge($sec['paragraphIndices'], $next['paragraphIndices']);
                $sec['chars'] += $next['chars'];
                foreach ($next['pages'] as $p) { $sec['pages'][$p] = $p; }
                $i++;
            }
            $merged[] = $sec;
        }
        $sections = $merged;

        return [
            'paragraphs' => $paragraphs,
            'sections' => $sections,
            'perPageStats' => $perPageStats,
        ];
    }

    /** @param array $section @param array<int,array<string,mixed>> $paragraphs */
    private function softSplitOversized(array $section, array $paragraphs, int $maxTarget): array
    {
        $result = [];
        $current = [ 'paragraphIndices' => [], 'chars' => 0, 'headings' => [], 'pages' => [] ];
        foreach ($section['paragraphIndices'] as $pi) {
            $p = $paragraphs[$pi];
            if ($current['chars'] > 0 && ($current['chars'] + $p['chars']) > $maxTarget) {
                $result[] = $current;
                $current = [ 'paragraphIndices' => [], 'chars' => 0, 'headings' => [], 'pages' => [] ];
            }
            $current['paragraphIndices'][] = $pi;
            $current['chars'] += $p['chars'];
            if (!empty($p['isHeading'])) { $current['headings'][] = $p['text']; }
            $current['pages'][$p['page']] = $p['page'];
        }
        if ($current['paragraphIndices'] !== []) { $result[] = $current; }
        return $result;
    }

    /**
     * Compute adaptive target size (and optional overlap) from accumulated section group statistics.
     * @param array<int,array<string,mixed>> $currentSectionGroup
     * @param array<int,array<string,mixed>> $paragraphs
     * @param array<string,mixed> $structure
     * @return array{0:int,1:int}
     */
    private function computeAdaptive(array $currentSectionGroup, array $paragraphs, array $structure, int $currentChars): array
    {
        $baseline        = (int)($this->targets['baseline'] ?? 2400);
        $denseMin        = (int)($this->targets['denseMin'] ?? 1400);
        $denseMax        = (int)($this->targets['denseMax'] ?? 1700);
        $narrativeMin    = (int)($this->targets['narrativeMin'] ?? 3200);
        $narrativeMax    = (int)($this->targets['narrativeMax'] ?? 3600);
        $absoluteMin     = (int)($this->targets['absoluteMin'] ?? 900);
        $absoluteMax     = (int)($this->targets['absoluteMax'] ?? 3800);

        $headingDensityHigh    = (float)($this->heuristics['headingDensityHigh'] ?? 1.2);
        $paragraphDensityHigh  = (float)($this->heuristics['paragraphDensityHigh'] ?? 2.0);
        $listDensityHigh       = (float)($this->heuristics['listDensityHigh'] ?? 0.35);
        $longParagraphLen      = (int)($this->heuristics['longParagraphLen'] ?? 450);
        $longParagraphLenBoost = (int)($this->heuristics['longParagraphLenMaxBoost'] ?? 400);

        if ($currentSectionGroup === []) { return [$baseline, 0]; }

        $paraCount = 0; $headingCount = 0; $chars = 0; $listLines = 0; $totalLines = 0;
        foreach ($currentSectionGroup as $sec) {
            $chars += $sec['chars'];
            foreach ($sec['paragraphIndices'] as $pi) {
                $para = $paragraphs[$pi];
                $paraCount++;
                if (!empty($para['isHeading'])) { $headingCount++; }
                $listLines += $para['listLineCount'];
                $totalLines += max(1, $para['lineCount']);
            }
        }
        $chars = max(1, $chars);
        $kChars = $chars / 1000.0;
        $headingDensity   = $headingCount / $kChars;
        $paragraphDensity = $paraCount / $kChars;
        $listDensity      = $totalLines > 0 ? $listLines / $totalLines : 0.0;
        $avgParagraphLen  = $paraCount > 0 ? $chars / $paraCount : 0.0;

        $target = (float)$baseline;
        if ($headingDensity > $headingDensityHigh || $paragraphDensity > $paragraphDensityHigh || $listDensity > $listDensityHigh) {
            $severity = 0.0;
            if ($headingDensity > $headingDensityHigh) {
                $severity = max($severity, min(1.0, ($headingDensity - $headingDensityHigh) / max(0.0001, $headingDensityHigh)));
            }
            if ($paragraphDensity > $paragraphDensityHigh) {
                $severity = max($severity, min(1.0, ($paragraphDensity - $paragraphDensityHigh) / max(0.0001, $paragraphDensityHigh)));
            }
            if ($listDensity > $listDensityHigh) {
                $severity = max($severity, min(1.0, ($listDensity - $listDensityHigh) / max(0.0001, $listDensityHigh)));
            }
            $target = $denseMax - ($denseMax - $denseMin) * $severity;
        } elseif ($headingDensity < ($headingDensityHigh * 0.333) && $avgParagraphLen > $longParagraphLen) {
            $factor = min(1.0, ($avgParagraphLen - $longParagraphLen) / max(1, $longParagraphLenBoost));
            $target = $narrativeMin + ($narrativeMax - $narrativeMin) * $factor;
        }

        $target = (int)max($absoluteMin, min($absoluteMax, round($target)));

        $overlap = 0;
        $overlapEnabled = (bool)($this->overlapCfg['enabled'] ?? false);
        if ($overlapEnabled) {
            $smallPercent = (float)($this->overlapCfg['smallTargetPercent'] ?? 0.08);
            $largePercent = (float)($this->overlapCfg['largeTargetPercent'] ?? 0.12);
            $minOverlap   = (int)($this->overlapCfg['minChars'] ?? 300);
            if ($target <= $denseMax) { $overlap = max($minOverlap, (int)round($target * $smallPercent)); }
            else { $overlap = max($minOverlap, (int)round($target * $largePercent)); }
        }
        return [$target, $overlap];
    }

    /**
     * Greedily add a section to the current chunk or flush when target threshold logic requires.
     * @param array $section
     * @param \Closure $appendSection
     * @param \Closure $flushChunk
     * @param array<int,array<string,mixed>> $paragraphs
     * @param array<string,mixed> $structure
     * @param array<int,array<string,mixed>> $chunks
     */
    private function greedyAdd(array $section, \Closure $appendSection, \Closure $flushChunk, array $paragraphs, array $structure, array &$chunks, int &$chunkNumber, array &$currentSectionGroup, int &$currentChars, int $maxTarget): void
    {
        [$target, $overlap] = $this->computeAdaptive($currentSectionGroup, $paragraphs, $structure, $currentChars);
        $target = min($maxTarget, max((int)($this->targets['absoluteMin'] ?? 900), $target));
        $minFill = (float)($this->flushCfg['minFillFactor'] ?? 0.3);

        if ($currentSectionGroup === []) { $appendSection($section); return; }
        if (($currentChars + $section['chars']) > $target && $currentChars > ($target * $minFill)) {
            $flushChunk($chunkNumber++);
            $appendSection($section);
            return;
        }
        $appendSection($section);
        if ($currentChars > $target && $section['chars'] > ($target * $minFill)) {
            $flushChunk($chunkNumber++);
        }
    }

    /**
     * Transform heading-like standalone lines into markdown H2 for full-text fallback.
     * @param array<int,string> $pageTexts
     * @return array<int,string>
     */
    private function transformHeadingsToMarkdownPages(array $pageTexts): array
    {
        $headingRegex = '/^(?:[0-9]+\.|[A-ZÄÖÜ][A-Za-zÄÖÜäöüß0-9 ,;:\\/()\-]{3,60})$/u';
        foreach ($pageTexts as $page => $text) {
            $lines = preg_split('/\R/', (string)$text) ?: [];
            foreach ($lines as &$line) {
                $trim = trim($line);
                if ($trim !== '' && !str_starts_with($trim, '#') && preg_match($headingRegex, $trim)) {
                    $line = '## ' . $trim;
                }
            }
            unset($line);
            $pageTexts[$page] = implode("\n", $lines);
        }
        return $pageTexts;
    }
}
