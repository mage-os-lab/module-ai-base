<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Client;

use MageOS\AiBase\AiServices\Opper;
use Symfony\AI\Platform\Bridge\Generic\Factory;
use Symfony\AI\Platform\Bridge\Generic\FallbackModelCatalog;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\Platform;
use Symfony\Contracts\HttpClient\HttpClientInterface;

// The registry checks class existence. Leave the adapter undefined when its optional bridge is absent.
if (class_exists(Factory::class)) {
    /**
     * Adapt the optional generic bridge to Opper without changing provider argument contracts.
     */
    class OpperFactory
    {
        /**
         * Preserve shared HTTP-client and catalogue injection while fixing Opper's endpoint paths.
         *
         * @param string $baseUrl
         * @param string $apiKey
         * @param HttpClientInterface|null $httpClient
         * @param ModelCatalogInterface|null $modelCatalog
         * @return Platform
         */
        // phpcs:ignore Magento2.Functions.StaticFunction -- Symfony bridge factories require a static entry point.
        public static function createPlatform(
            string $baseUrl,
            #[\SensitiveParameter] string $apiKey,
            ?HttpClientInterface $httpClient = null,
            ?ModelCatalogInterface $modelCatalog = null,
        ): Platform {
            return Factory::createPlatform(
                baseUrl: $baseUrl,
                apiKey: $apiKey,
                httpClient: $httpClient,
                modelCatalog: $modelCatalog ?? new FallbackModelCatalog(),
                completionsPath: Opper::COMPLETIONS_PATH,
                embeddingsPath: Opper::EMBEDDINGS_PATH,
                name: 'opper',
            );
        }
    }
}
