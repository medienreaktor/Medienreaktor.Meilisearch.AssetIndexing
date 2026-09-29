<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\AssetIndexing\Search;

use Neos\Flow\Annotations as Flow;

/**
 * One page of a PDF on which the search term was found. `uri` opens the PDF on that
 * page with the term marked (empty if the asset could not be resolved).
 */
#[Flow\Proxy(false)]
final class PdfOccurrence
{
    public function __construct(
        public readonly int $page,
        public readonly string $snippet,
        public readonly string $uri,
    ) {
    }
}
