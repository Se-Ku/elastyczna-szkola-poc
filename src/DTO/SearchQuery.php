<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Reprezentuje żądanie wyszukania frazy (nazwy szkoły)
 */
readonly class SearchQuery
{
    public function __construct(
        public string $phrase,
        public int $limit = 1,
    ) {
    }
}
