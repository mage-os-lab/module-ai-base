<?php

declare(strict_types=1);

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;
use MageOS\AiBase\Api\ModelListProviderInterface;
use MageOS\AiBase\Model\ModelList\HttpFetcher;

class Xai implements AiServiceConfigurationInterface, ModelListProviderInterface
{
    use FieldFactoryTrait;
    use ModelListTrait;

    /**
     * xAI model listing endpoint (OpenAI-compatible).
     */
    private const MODELS_URL = 'https://api.x.ai/v1/models';

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
        return 'xai';
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'xAI (Grok)';
    }

    /**
     * @inheritdoc
     */
    public function getSupportedModels(): array
    {
        return [
            'grok-2'      => 'Grok 2',
            'grok-2-mini' => 'Grok 2 mini',
        ];
    }

    /**
     * @inheritdoc
     */
    public function getConfigurationFields(): array
    {
        return [
            $this->apiKeyField($this->fieldFactory),
            $this->modelField($this->fieldFactory, $this->getSupportedModels()),
        ];
    }

    /**
     * @inheritdoc
     */
    public function fetchModels(array $configuration): array
    {
        $response = $this->modelListFetcher->getJson(self::MODELS_URL, [
            'Authorization' => 'Bearer ' . (string) ($configuration['api_key'] ?? ''),
        ]);

        return $this->parseDataModelList($response);
    }
}
