<?php
declare(strict_types=1);

namespace Medienreaktor\Meilisearch\AssetIndexing\Service;

use Neos\Flow\Annotations as Flow;
use Psr\Log\LoggerInterface;
use Smalot\PdfParser\Parser as PdfParser;
use Spatie\PdfToText\Pdf as PdfToText;

/**
 * Extracts per-page PDF text (with fallback) for downstream chunking.
 *
 * @Flow\Scope("singleton")
 */
class PdfPageExtractor
{
    private const PDF_TEXT_GLOBAL_OPTIONS = ['-enc UTF-8', '-layout', '-nopgbrk'];

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Extract per-page text using spatie/pdftotext and fallback to Smalot if all pages empty.
     * @param mixed $resource
     * @return array<int,string>
     */
    public function extractPages($resource): array
    {
        $tmpPath = $resource->createTemporaryLocalCopy();
        if (!is_string($tmpPath) || !file_exists($tmpPath)) { return []; }

        $pageCount = $this->detectPdfPageCount($tmpPath);
        if ($pageCount < 1) { $pageCount = 1; }

        $pageTexts = [];
        $this->logger->debug(sprintf('Extracting PDF pages via spatie/pdftotext (%d pages) %s', $pageCount, $resource->getFilename()));
        for ($i = 1; $i <= $pageCount; $i++) {
            try {
                $options = array_merge(self::PDF_TEXT_GLOBAL_OPTIONS, ["-f $i", "-l $i"]);
                $text = (new PdfToText())
                    ->setPdf($tmpPath)
                    ->setOptions($options)
                    ->text();
            } catch (\Throwable $e) {
                $this->logger->debug(sprintf('pdftotext page %d failed for %s: %s', $i, $resource->getFilename(), $e->getMessage()));
                $text = '';
            }
            $pageTexts[$i] = $text;
        }

        $hasContent = false; foreach ($pageTexts as $t) { if (trim($t) !== '') { $hasContent = true; break; } }
        if (!$hasContent) {
            $this->logger->debug(sprintf('Fallback to Smalot parser for %s', $resource->getFilename()));
            try {
                $content = @file_get_contents($tmpPath);
                if ($content !== false) {
                    $parser = new PdfParser();
                    $pdf    = $parser->parseContent($content);
                    $pages  = $pdf->getPages();
                    $pageTexts = [];
                    $i = 1;
                    foreach ($pages as $p) {
                        try { $pageTexts[$i++] = $p->getText(); }
                        catch (\Throwable $e) { $pageTexts[$i-1] = ''; }
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->debug(sprintf('Smalot fallback failed for %s: %s', $resource->getFilename(), $e->getMessage()));
            }
        }

        if ($pageTexts === []) { $pageTexts = [1 => '']; }
        return $pageTexts;
    }

    /**
     * Detect page count using Smalot parser.
     * @param string $path
     * @return int
     */
    private function detectPdfPageCount(string $path): int
    {
        try {
            $content = @file_get_contents($path);
            if ($content === false || $content === '') { return 0; }
            $parser = new PdfParser();
            $pdf    = $parser->parseContent($content);
            return max(0, count($pdf->getPages()));
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Fallback: extract entire PDF text (single string) if explicit call needed.
     * @param mixed $resource
     * @return string
     */
    public function extractWholeText($resource): string
    {
        try {
            $stream = $resource->getStream();
            if (!is_resource($stream)) { return ''; }
            $content = stream_get_contents($stream);
            if ($content === false || $content === '') { return ''; }
            $parser = new PdfParser();
            $pdf    = $parser->parseContent($content);
            return $pdf->getText();
        } catch (\Throwable $e) {
            return '';
        }
    }
}
