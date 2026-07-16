<?php

declare(strict_types=1);

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;
use MageOS\AiBase\Api\ModelListProviderInterface;
use MageOS\AiBase\Model\ModelList\HttpFetcher;

class OpenAi implements AiServiceConfigurationInterface, ModelListProviderInterface
{
    use FieldFactoryTrait;
    use ModelListTrait;

    /**
     * OpenAI model listing endpoint.
     */
    private const MODELS_URL = 'https://api.openai.com/v1/models';

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
        return 'openai';
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'OpenAI';
    }

    /**
     * @inheritdoc
     */
    public function getSupportedModels(): array
    {
        return [
            'gpt-4o'      => 'GPT-4o',
            'gpt-4o-mini' => 'GPT-4o mini',
            'gpt-4-turbo' => 'GPT-4 Turbo',
            'o1'          => 'o1',
            'o1-mini'     => 'o1 mini',
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
