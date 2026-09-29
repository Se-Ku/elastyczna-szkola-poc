<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\DTO\SearchQuery;
use App\Search\SearchInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class SearchServiceTest extends KernelTestCase
{
    #[DataProvider('provideAlternativeNames')]
    public function testSearchByAlternativeNameReturnsOfficialName(string $alternativeName, string $expectedOfficialName): void
    {
        self::bootKernel();

        /** @var SearchInterface $searchService */
        $searchService = static::getContainer()->get(SearchInterface::class);

        $query = new SearchQuery(phrase: $alternativeName);
        $result = $searchService->search($query);

        // Pobieramy oficjalne nazwy szkół ze zwróconych wyników
        $foundNames = array_column($result->items, 'official_name');

        $this->assertContains(
            $expectedOfficialName,
            $foundNames,
            sprintf(
                'Wyszukiwanie dla frazy alternatywnej "%s" nie zwróciło oficjalnej nazwy "%s". Znaleziono: [%s]',
                $alternativeName,
                $expectedOfficialName,
                implode(', ', $foundNames)
            )
        );
    }

    /**
     * DataProvider dynamicznie wczytujący plik z danymi i generujący pary [fraza_alternatywna, oficjalna_nazwa]
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function provideAlternativeNames(): iterable
    {
        $filePath = __DIR__ . '/../../data/fixtures/schools.txt';

        if (!file_exists($filePath)) {
            self::fail("Plik z danymi fixture nie istnieje pod ścieżką: {$filePath}");
        }

        // TODO: Powtórzona logika ładowania pliku z ElasticsearchManager
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));
            if (count($parts) < 2) {
                continue;
            }

            $officialName = $parts[0];
            $alternativeNamesRaw = $parts[1];

            $alternativeNames = array_map('trim', explode(',', $alternativeNamesRaw));

            foreach ($alternativeNames as $altName) {
                if ($altName === '') {
                    continue;
                }

                // Etykieta przypisana do klucza ułatwia identyfikację konkretnego przypadku w raporcie PHPUnit
                $dataKey = sprintf('Fraza: "%s" -> Szkoła: "%s"', $altName, $officialName);
                yield $dataKey => [$altName, $officialName];
            }
        }
    }
}
