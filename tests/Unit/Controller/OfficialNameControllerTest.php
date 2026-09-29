<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\OfficialNameController;
use App\DTO\SearchQuery;
use App\DTO\SearchResult;
use App\Search\SearchInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sprawdza, czy kontroler (endpoint /getOfficialName) zwraca prawidłową odpowiedź w zależności
 * od żądania i wyniku wyszukiwania nazwy szkoły (SearchInterface).
 */
class OfficialNameControllerTest extends TestCase
{
    private SearchInterface&MockObject $searchServiceMock;
    private OfficialNameController $controller;

    protected function setUp(): void
    {
        // 1. Tworzymy mocka dla interfejsu wyszukiwania
        $this->searchServiceMock = $this->createMock(SearchInterface::class);

        // 2. Inicjalizujemy kontroler ze wstrzykniętym mockiem
        $this->controller = new OfficialNameController($this->searchServiceMock);

        // 3. Wstrzykujemy pusty kontener DI, aby metoda $this->json() z AbstractController działała bez błędów
        $this->controller->setContainer(new Container());
    }

    /**
     * Wariant 1: Brak parametru "phrase" -> Kod HTTP 400 Bad Request
     */
    public function testReturnsBadRequestWhenPhraseIsMissingOrEmpty(): void
    {
        $request = new Request(query: ['phrase' => '   ']);

        // Upewniamy się, że wyszukiwarka w ogóle nie zostanie wywołana
        $this->searchServiceMock->expects($this->never())->method('search');

        $response = ($this->controller)($request);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

        $responseData = json_decode((string) $response->getContent(), true);
        $this->assertSame(['error' => 'Parametr "phrase" jest wymagany.'], $responseData);
    }

    /**
     * Wariant 2: Brak wyników w wyszukiwarce -> Kod HTTP 404 Not Found
     */
    public function testReturnsNotFoundWhenNoSchoolIsMatching(): void
    {
        $phrase = 'NieistniejacaSzkola';
        $request = new Request(query: ['phrase' => $phrase]);

        // Mockujemy odpowiedź z wyszukiwarki: 0 wyników
        $this->searchServiceMock
            ->expects($this->once())
            ->method('search')
            ->with($this->callback(fn (SearchQuery $query) => $query->phrase === $phrase && $query->limit === 1))
            ->willReturn(new SearchResult(total: 0, items: []));

        $response = ($this->controller)($request);

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());

        $responseData = json_decode((string) $response->getContent(), true);
        $this->assertSame(['error' => sprintf('Nie znaleziono oficjalnej nazwy dla frazy "%s".', $phrase)], $responseData);
    }

    /**
     * Wariant 3: Brak pola "name" w wyniku z ES -> Kod HTTP 500 Internal Server Error
     */
    public function testReturnsInternalServerErrorWhenNameFieldIsMissingInResult(): void
    {
        $phrase = 'Staszic';
        $request = new Request(query: ['phrase' => $phrase]);

        // Mockujemy wynik z uszkodzoną strukturą danych (brak klucza 'name')
        $this->searchServiceMock
            ->expects($this->once())
            ->method('search')
            ->willReturn(new SearchResult(total: 1, items: [['city' => 'Warszawa']]));

        $response = ($this->controller)($request);

        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());

        $responseData = json_decode((string) $response->getContent(), true);
        $this->assertSame(['error' => 'Błąd spójności danych: brak pola "name" w wyniku.'], $responseData);
    }

    /**
     * Wariant 4: Poprawne dopasowanie -> Kod HTTP 200 OK
     */
    public function testReturnsOfficialNameOnSuccess(): void
    {
        $phrase = 'Staszic';
        $expectedOfficialName = 'XIV Liceum Ogólnokształcące im. Stanisława Staszica';
        $request = new Request(query: ['phrase' => $phrase]);

        // Mockujemy poprawny wynik wyszukiwania
        $this->searchServiceMock
            ->expects($this->once())
            ->method('search')
            ->willReturn(new SearchResult(
                total: 1,
                items: [['official_name' => $expectedOfficialName]]
            ));

        $response = ($this->controller)($request);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $responseData = json_decode((string) $response->getContent(), true);
        $this->assertSame(['official_name' => $expectedOfficialName], $responseData);
    }

    /**
     * Wariant 5: Wyjątek rzucony przez metodę "search" -> Kod HTTP 500 i komunikat "Usługa niedostępna"
     */
    public function testReturnsInternalServerErrorWhenSearchServiceThrowsException(): void
    {
        $phrase = 'Staszic';
        $request = new Request(query: ['phrase' => $phrase]);

        // Symulujemy rzucenie wyjątku przez usługę wyszukiwania (np. błąd połączenia z ES)
        $this->searchServiceMock
            ->expects($this->once())
            ->method('search')
            ->willThrowException(new \RuntimeException('Connection refused'));

        $response = ($this->controller)($request);

        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());

        $responseData = json_decode((string) $response->getContent(), true);
        $this->assertSame(['error' => 'Usługa niedostępna'], $responseData);
    }
}
