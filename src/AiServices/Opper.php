<?php

declare(strict_types=1);

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;
use MageOS\AiBase\Api\JsonFetcherInterface;
use MageOS\AiBase\Api\ModelListProviderInterface;

/**
 * Opper's hosted gateway, using its v3 OpenAI-compatible chat endpoint.
 */
class Opper extends AbstractAiService implements ModelListProviderInterface
{
    use ModelListTrait;

    /**
     * The factory adapter uses Opper's chat route rather than the generic bridge's /v1 default.
     */
    public const COMPLETIONS_PATH = '/v3/compat/chat/completions';

    /**
     * Keep the embedding route with the provider even though the bundled client exposes only chat.
     */
    public const EMBEDDINGS_PATH = '/v3/compat/embeddings';

    /**
     * Keep the host fixed so the gateway needs only a key and a model in the admin form.
     */
    private const BASE_URL = 'https://api.opper.ai';

    /**
     * OpenAI-compatible model listing endpoint, scoped to the authenticated API key.
     */
    private const MODELS_URL = 'https://api.opper.ai/v3/compat/models';

    /**
     * @param FieldDescriptorInterfaceFactory $fieldFactory
     * @param JsonFetcherInterface $modelListFetcher
     */
    public function __construct(
        FieldDescriptorInterfaceFactory $fieldFactory,
        private readonly JsonFetcherInterface $modelListFetcher,
    ) {
        parent::__construct($fieldFactory);
    }

    /**
     * @inheritdoc
     */
    public function getCode(): string
    {
        return 'opper';
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'Opper';
    }

    /**
     * The live catalogue includes org-specific routes, so do not curate a static list.
     *
     * @return array<string,string>
     */
    public function getSupportedModels(): array
    {
        return [];
    }

    /**
     * The fixed gateway host and stored API key; the factory adapter handles endpoint paths.
     *
     * @param array<string,mixed> $configuration
     * @return list<mixed>
     */
    public function getPlatformArguments(array $configuration): array
    {
        return [self::BASE_URL, $this->resolveApiKey($configuration)];
    }

    /**
     * Fetch the key-scoped models, pools and deployed routes, excluding embedding-only entries.
     *
     * @param array<string,mixed> $configuration
     * @return array<string,string>
     */
    public function fetchModels(array $configuration): array
    {
        $response = $this->modelListFetcher->getJson(self::MODELS_URL, [
            'Authorization' => 'Bearer ' . $this->resolveApiKey($configuration),
        ]);

        if (isset($response['data']) && is_array($response['data'])) {
            $response['data'] = array_filter($response['data'], static function (mixed $entry): bool {
                if (!is_array($entry)) {
                    return false;
                }
                $metadata = $entry['opper'] ?? null;

                return !is_array($metadata) || ($metadata['type'] ?? null) !== 'embedding';
            });
        }

        return $this->parseDataModelList($response);
    }
}
