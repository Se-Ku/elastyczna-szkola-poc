<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\SearchQuery;
use App\Search\SearchInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class OfficialNameController extends AbstractController
{
    public function __construct(
        private readonly SearchInterface $searchService,
    ) {
    }

    #[Route('/getOfficialName', name: 'api_get_official_name', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $phrase = trim((string) $request->query->get('phrase', ''));

        // 1. Walidacja obecności parametru
        if ($phrase === '') {
            return $this->json(
                ['error' => 'Parametr "phrase" jest wymagany.'],
                Response::HTTP_BAD_REQUEST // 400
            );
        }

        try {
            // 2. Szukamy tylko pierwszego, najlepiej dopasowanego wyniku
            $result = $this->searchService->search(new SearchQuery(phrase: $phrase, limit: 1));
        } catch (\Throwable $exception) {
            return $this->json(
                ['error' => 'Usługa niedostępna'],
                Response::HTTP_INTERNAL_SERVER_ERROR // 500
            );
        }

        // 3. Obsługa braku wyników
        if ($result->total === 0 || empty($result->items)) {
            return $this->json(
                ['error' => sprintf('Nie znaleziono oficjalnej nazwy dla frazy "%s".', $phrase)],
                Response::HTTP_NOT_FOUND // 404
            );
        }

        // 4. Zwrócenie znalezionej nazwy oficjalnej
        $officialName = $result->items[0]['official_name'] ?? null;

        if (!$officialName) {
            return $this->json(
                ['error' => 'Błąd spójności danych: brak pola "name" w wyniku.'],
                Response::HTTP_INTERNAL_SERVER_ERROR // 500
            );
        }

        return $this->json([
            'official_name' => $officialName,
        ], Response::HTTP_OK); // 200
    }
}
