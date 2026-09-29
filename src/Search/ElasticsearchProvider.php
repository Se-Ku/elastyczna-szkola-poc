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
                            // 1. Precyzyjne (3.0)
                            [
                                'match' => [
                                    'official_name' => [
                                        'query' => $cleanedInput,
                                        'fuzziness' => 'AUTO',
                                        'boost' => 3.0,
                                    ],
                                ],
                            ],
                            // 2. Stempel - Odmiana przez przypadki (2.5)
                            [
                                'match' => [
                                    'official_name.stemmed' => [
                                        'query' => $cleanedInput,
                                        'boost' => 2.5,
                                    ],
                                ],
                            ],
                            // 3. Edge N-Gram - Koło ratunkowe dla urwanych słów i innych form (np. elektronik -> elektronicznych) (1.5)
                            [
                                'match' => [
                                    'official_name.prefix' => [
                                        'query' => $cleanedInput,
                                        'boost' => 1.5,
                                    ],
                                ],
                            ],
                            // 4. Akronimy (5.0)
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
