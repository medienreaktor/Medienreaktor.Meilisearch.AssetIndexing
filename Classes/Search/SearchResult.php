<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\AssetIndexing\Search;

use Neos\Flow\Annotations as Flow;

/**
 * One page of search results. Pages and PDFs are mixed and ranked by relevance.
 */
#[Flow\Proxy(false)]
final class SearchResult
{
    public const TYPE = 'result';

    public readonly string $type;

    /**
     * @param list<NodeHit|PdfHit> $hits
     */
    public function __construct(
        public readonly int $totalHits,
        public readonly int $totalPages,
        public readonly array $hits,
    ) {
        $this->type = self::TYPE;
    }

    public static function empty(): self
    {
        return new self(0, 0, []);
    }
}
