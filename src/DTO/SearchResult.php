<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Reprezentuje rezultat wyszukiwania (dopasowane szkoły)
 * @see SearchQuery
 */
readonly class SearchResult
{
    /**
     * @param array<int, array<string, mixed>> $items
     */
    public function __construct(
        public int $total,
        public array $items,
    ) {
    }
}
