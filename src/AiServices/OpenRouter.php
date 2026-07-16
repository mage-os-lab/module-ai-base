<?php

declare(strict_types=1);

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;
use MageOS\AiBase\Api\ModelListProviderInterface;
use MageOS\AiBase\Model\ModelList\HttpFetcher;

class OpenRouter implements AiServiceConfigurationInterface, ModelListProviderInterface
{
    use FieldFactoryTrait;
    use ModelListTrait;

    /**
     * OpenRouter model listing endpoint (public; auth optional).
     */
    private const MODELS_URL = 'https://openrouter.ai/api/v1/models';

    /**
     * @param FieldDescriptorInterfaceFactory $fieldFactory
     * @param HttpFetcher $modelListFetcher
     */
    public function __construct(
        private readonly FieldDescriptorInterfaceFactory $fieldFactory,
        private readonly HttpFetcher $modelListFetcher,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getCode(): string
    {
        return 'openrouter';
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'OpenRouter';
    }

    /**
     * @inheritdoc
     */
    public function getSupportedModels(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getConfigurationFields(): array
    {
        return [
            $this->apiKeyField($this->fieldFactory),
            $this->freeTextModelField($this->fieldFactory),
        ];
    }

    /**
     * @inheritdoc
     */
    public function fetchModels(array $configuration): array
    {
        $headers = [];
        $apiKey = (string) ($configuration['api_key'] ?? '');
        if ($apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }
        $response = $this->modelListFetcher->getJson(self::MODELS_URL, $headers);

        return $this->parseDataModelList($response, 'name');
    }
}
