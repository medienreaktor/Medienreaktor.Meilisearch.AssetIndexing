<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\AssetIndexing\Search;

use Neos\Flow\Annotations as Flow;

/**
 * Meilisearch did not answer the query. The cause is logged; the page tells the
 * visitor that the search is unavailable instead of claiming there are no results.
 */
#[Flow\Proxy(false)]
final class SearchUnavailable
{
    public const TYPE = 'unavailable';

    public readonly string $type;

    public function __construct()
    {
        $this->type = self::TYPE;
    }
}
