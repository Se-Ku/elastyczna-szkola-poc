<?php

declare(strict_types=1);

namespace App\Factory;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;

/**
 * Inicjuje klienta Elasticsearch.
 * Ze względu na niewielkie skomplikowanie, fabryka może być przerostem formy nad treścią.
 * Zaletą tego podejścia jest zwiększenie przejrzystości services.yaml.
 */
readonly class ElasticsearchClientFactory
{
    public function __construct(
        private string $elasticsearchUrl,
    ) {
    }

    public function createClient(): Client
    {
        return ClientBuilder::create()
            ->setHosts([$this->elasticsearchUrl])
            // TODO: Produkcyjnie ustawimy tutaj dostępy
            // ->setApiKey('TWÓJ_API_KEY')
            ->build();
    }
}
