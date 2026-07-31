<?php

declare(strict_types=1);

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;
use MageOS\AiBase\Api\ModelListProviderInterface;
use MageOS\AiBase\Model\ModelList\HttpFetcher;

class LmStudio implements AiServiceConfigurationInterface, ModelListProviderInterface
{
    use FieldFactoryTrait;
    use ModelListTrait;

    /**
     * Default base URL of a local LM Studio instance.
     */
    private const DEFAULT_BASE_URL = 'http://localhost:1234';

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
        return 'lmstudio';
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'LM Studio';
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
            $this->baseUrlField($this->fieldFactory, self::DEFAULT_BASE_URL),
            $this->freeTextModelField($this->fieldFactory),
        ];
    }

    /**
     * @inheritdoc
     */
    public function fetchModels(array $configuration): array
    {
        $baseUrl = $this->resolveBaseUrl($configuration, self::DEFAULT_BASE_URL);
        $response = $this->modelListFetcher->getJson($baseUrl . '/v1/models');

        return $this->parseDataModelList($response);
    }
}
