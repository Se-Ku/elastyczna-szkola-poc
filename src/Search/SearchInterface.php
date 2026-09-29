<?php

declare(strict_types=1);

namespace App\Search;

use App\DTO\SearchQuery;
use App\DTO\SearchResult;

/**
 * Interfejs dla "dostawców" wyszukiwania
 */
interface SearchInterface
{
    public function search(SearchQuery $query): SearchResult;
}
