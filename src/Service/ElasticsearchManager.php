<?php

declare(strict_types=1);

namespace App\Service;

use Elastic\Elasticsearch\Client;

readonly class ElasticsearchManager
{
    public function __construct(
        private Client $client,
        private string $projectDir,
    ) {
    }

    /**
     * Tworzy (pusty) indeks dla nazw szkół w Elasticsearch.
     * Konfiguracja indeksu dobrana w taki sposób, aby rozpoznać różne warianty nazw (potoczne, skrócone, błędne).
     * @param string $indexName
     * @return bool
     */
    public function initializeIndex(string $indexName = 'official_schools'): bool
    {
        $exists = $this->client->indices()->exists(['index' => $indexName]);

        if ($exists->asBool()) {
            $this->client->indices()->delete(['index' => $indexName]);
        }

        $params = [
            'index' => $indexName,
            'body' => [
                'settings' => [
                    'analysis' => [
                        'filter' => [
                            // 1. Zamiana polskich znaków (np. ę -> e, ł -> l)
                            'ascii_folding_filter' => [
                                'type' => 'asciifolding',
                                'preserve_original' => false,
                            ],
                            // 2. Synonimy dla częstych skrótów i liczebników
                            'school_synonyms' => [
                                'type' => 'synonym',
                                'synonyms' => [
                                    'lo, liceum ogolnoksztalcace',
                                    'sp, szkola podstawowa',
                                    'tech, technikum',
                                    'zs, zespol szkol',
                                    '1, i, pierwsze',
                                    '2, ii, drugie',
                                    '3, iii, trzecie',
                                    '4, iv, czwarte',
                                    '5, v, piate',
                                    '6, vi, szoste',
                                    '7, vii, siodme',
                                    '8, viii, osme',
                                    '9, ix, dziewiate',
                                    '10, x, dziesiate',
                                    '11, xi, jedenaste',
                                    '12, xii, dwunaste',
                                    '13, xiii, trzynaste',
                                    '14, xiv, czternaste',
                                    '15, xv, pietnaste',
                                    '16, xvi, szesnaste',
                                    '17, xvii, siedemnaste',
                                    '18, xviii, osiemnaste',
                                    '19, xix, dziewietnaste',
                                    '20, xx, dwudzieste',
                                ]
                            ],
                            // 3. Trigramy (fragmenty 3-znakowe do łapania odmian i literówek)
                            'trigram_filter' => [
                                'type' => 'ngram',
                                'min_gram' => 3,
                                'max_gram' => 3,
                            ],
                        ],
                        'analyzer' => [
                            // Analizator indeksujący: zamienia słowa na trigramy z synonimami
                            'school_index_analyzer' => [
                                'tokenizer' => 'standard',
                                'filter' => [
                                    'lowercase',
                                    'ascii_folding_filter',
                                    'school_synonyms',
                                    'trigram_filter',
                                ],
                            ],
                            // Analizator wyszukujący (bez trigramów, aby zapytanie było dzielone na słowa)
                            'school_search_analyzer' => [
                                'tokenizer' => 'standard',
                                'filter' => [
                                    'lowercase',
                                    'ascii_folding_filter',
                                    'school_synonyms',
                                ],
                            ],
                        ],
                    ],
                ],
                'mappings' => [
                    'properties' => [
                        'official_name' => [
                            'type' => 'text',
                            'analyzer' => 'school_index_analyzer',
                            'search_analyzer' => 'school_search_analyzer',
                            'fields' => [
                                // Podpole do wyszukiwania precyzyjnego (boost za dokładniejsze trafienie)
                                'keyword_match' => [
                                    'type' => 'text',
                                    'analyzer' => 'school_search_analyzer',
                                ],
                            ],
                        ],
                        'acronym' => [
                            'type' => 'text',
                            'analyzer' => 'school_search_analyzer',
                        ],
                    ],
                ],
            ],
        ];

        return $this->client->indices()->create($params)->asBool();
    }

    /**
     * Tworzy nowy indeks w Elasticsearch, zawierający nazwy szkół z pliku data/fixtures/schools.txt.
     * @param string $indexName
     * @return void
     */
    public function seedData(string $indexName = 'official_schools'): void
    {
        $filePath ??= $this->projectDir . '/data/fixtures/schools.txt';

        if (!file_exists($filePath)) {
            throw new \RuntimeException("Plik z danymi nie istnieje: {$filePath}");
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new \RuntimeException("Nie udało się odczytać pliku: {$filePath}");
        }

        $params = ['body' => []];
        $id = 1;

        foreach ($lines as $line) {
            $line = trim($line);

            // Pomijamy puste linie oraz komentarze zaczynające się od #
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));

            if (count($parts) < 4) {
                continue; // Pomijamy niepoprawnie sformatowane linie
            }

            [$name, $altNamesRaw, $city, $type] = $parts;

            $params['body'][] = [
                'index' => [
                    '_index' => $indexName,
                    '_id' => (string)$id++,
                ],
            ];

            /*
             * Realnym przypadkiem jest podanie akronimu, np. "ZSEI" dla "Zespół Szkół Elektronicznych i Informatycznych".
             * W ER trudno wygenerować w locie akronim i po nich indeksować, dlatego robimy to na etapie uzupełniania danych.
             * Nie dla każdej szkoły ma to sens, ale niczemu nie szkodzi.
             * Np. użytkownicy pewnie nie wpiszą "LO5JW" dla "Liceum Ogólnokształcące nr 5 im. Józefa Wybickiego".
             */
            $params['body'][] = [
                'official_name' => $name,
                'city' => $city,
                'type' => $type,
                'acronym' => $this->generateAcronym($name),
            ];
        }

        $this->client->bulk($params);

        // Odświeżenie indeksu, aby dane były natychmiast widoczne
        $this->client->indices()->refresh(['index' => $indexName]);
    }

    /**
     * Tworzy akronimy nazwy szkoły na potrzeby wyszukiwania.
     * Nie ma sztywnych zasad jak tworzyć akronimy. Użytkownicy mogą być bardzo kreatywni.
     * Obsłużymy dwa warianty:
     * - akronim bez "łączników" w nazwie (im, nr, etc.)
     * - jak wyżej, ale dopuszczamy łącznik "i" (np. ZSEI, ZSEiI) ze względu na popularność tego wariantu
     * @param string $name
     * @return array
     */
    private function generateAcronym(string $name): array
    {
        // Zostawiamy litery, cyfry i spacje
        $cleanName = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $name);
        $words = explode(' ', mb_strtolower($cleanName));

        // Wariant1: słowa nietrafiające do akronimu
        $hardStopWords = ['im', 'imienia', 'nr', 'numer'];

        // Wariant 2: słowa dopuszczalne w alternatywnym akronimie
        // Aktualnie dopuszczamy tylko "i"
        $softStopWords = ['i'];

        $acronymWithConjunctions = '';
        $acronymWithoutConjunctions = '';

        foreach ($words as $word) {
            $word = trim($word);

            // Całkowicie pomijamy puste ciągi i ignorowane słowa
            if ($word === '' || in_array($word, $hardStopWords, true)) {
                continue;
            }

            $firstLetter = mb_substr($word, 0, 1);

            // Wariant 1: wersja podstawowa akronimu (bez dodatkowych słów)
            if (!in_array($word, $softStopWords, true)) {
                $acronymWithoutConjunctions .= $firstLetter;
            }

            // Wariant 2: Wersja alternatywna (więcej dopuszczalnych słów)
            $acronymWithConjunctions .= $firstLetter;
        }

        // array_unique odrzuci duplikaty (jeśli w nazwie nie było "i", oba warianty będą identyczne)
        // array_values zapewnia poprawną strukturę JSON dla Elasticsearch (indeksy numeryczne 0, 1)
        return array_values(array_unique([
            $acronymWithConjunctions,
            $acronymWithoutConjunctions
        ]));
    }
}
