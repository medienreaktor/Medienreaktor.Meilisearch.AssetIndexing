<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\AssetIndexing\Search;

use Neos\Flow\Annotations as Flow;

/**
 * A PDF found by the search, collapsed from its matching chunks into one hit.
 * `uri` deep-links to the best-ranked page; `occurrences` lists every matching page
 * in ascending order.
 */
#[Flow\Proxy(false)]
final class PdfHit
{
    public const TYPE = 'pdf';

    public readonly string $type;

    /**
     * @param list<PdfOccurrence> $occurrences
     */
    public function __construct(
        public readonly string $title,
        public readonly string $filename,
        public readonly int $filesize,
        public readonly string $uri,
        public readonly string $snippet,
        public readonly array $occurrences,
    ) {
        $this->type = self::TYPE;
    }
}
