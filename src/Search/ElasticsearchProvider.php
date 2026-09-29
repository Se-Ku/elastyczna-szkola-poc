<?php

declare(strict_types=1);

namespace App\Search;

use App\DTO\SearchQuery;
use App\DTO\SearchResult;
use Elastic\Elasticsearch\Client;

/**
 * Implementuje wyszukiwanie oficjalnych nazw szkół z dedykowanego indeksu Elasticsearch.
 * Dla uproszczenia przykładu większość parametrów jest ustawiona na sztywno.
 */
readonly class ElasticsearchProvider implements SearchInterface
{
    private string $indexName;
    public function __construct(
        private Client $client,
    ) {
        $this->indexName = 'official_schools';
    }

    public function search(SearchQuery $query): SearchResult
    {
        // Opcjonalne: jeśli użytkownik wpisze akronim z kropkami (np. Z.S.E.I.),
        // usuwamy kropki, aby Elasticsearch łatwiej go dopasował.
        $cleanedInput = str_replace('.', '', $query->phrase);

        $params = [
            'index' => $this->indexName,
            'body'  => [
                'size' => $query->limit,
                'query' => [
                    'bool' => [
                        'should' => [
                            // Zapytanie główne: analiza trigramowa z fuzzymatchingiem
                            [
                                'match' => [
                                    'official_name' => [
                                        'query' => $cleanedInput,
                                        'fuzziness' => 'AUTO',
                                        'boost' => 1.0,
                                    ],
                                ],
                            ],
                            // Dodatkowy "boost" za dopasowanie całych słów kluczowych
                            [
                                'match' => [
                                    'official_name.keyword_match' => [
                                        'query' => $cleanedInput,
                                        'boost' => 2.0,
                                    ],
                                ],
                            ],
                            // Wyszukiwanie po akronimie z wysoką wagą
                            [
                                'match' => [
                                    'acronym' => [
                                        'query' => $cleanedInput,
                                        'boost' => 5.0,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->client->search($params);

        $data = $response->asArray();

        $total = $data['hits']['total']['value'] ?? 0;

        // Wyniki można mapować na dedykowane DTO
        $items = array_map(
            fn(array $hit): array => [ 'score' => $hit['_score'], ...$hit['_source']],
            $data['hits']['hits'] ?? []
        );

        return new SearchResult($total, $items);
    }
}
