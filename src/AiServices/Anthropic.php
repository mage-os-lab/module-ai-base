<?php

declare(strict_types=1);

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;
use MageOS\AiBase\Api\ModelListProviderInterface;
use MageOS\AiBase\Model\ModelList\HttpFetcher;

class Anthropic implements AiServiceConfigurationInterface, ModelListProviderInterface
{
    use FieldFactoryTrait;
    use ModelListTrait;

    /**
     * Anthropic model listing endpoint.
     */
    private const MODELS_URL = 'https://api.anthropic.com/v1/models';

    /**
     * API version header value required by the Anthropic API.
     */
    private const API_VERSION = '2023-06-01';

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
        return 'anthropic';
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'Anthropic';
    }

    /**
     * @inheritdoc
     */
    public function getSupportedModels(): array
    {
        return [
            'claude-opus-4-7'           => 'Claude Opus 4.7',
            'claude-sonnet-4-6'         => 'Claude Sonnet 4.6',
            'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5',
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
            'x-api-key' => (string) ($configuration['api_key'] ?? ''),
            'anthropic-version' => self::API_VERSION,
        ]);

        return $this->parseDataModelList($response, 'display_name');
    }
}
