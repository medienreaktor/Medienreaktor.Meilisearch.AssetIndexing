<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\AssetIndexing\Search;

use Neos\Flow\Annotations as Flow;

/**
 * A page found by the search. `snippet` is highlighted HTML.
 */
#[Flow\Proxy(false)]
final class NodeHit
{
    public const TYPE = 'node';

    public readonly string $type;

    public function __construct(
        public readonly string $title,
        public readonly string $uri,
        public readonly string $snippet,
    ) {
        $this->type = self::TYPE;
    }
}
