<?php

declare(strict_types=1);

namespace MageOS\AiBase\AiServices;

use Magento\Framework\Exception\LocalizedException;
use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;
use MageOS\AiBase\Api\ModelListProviderInterface;
use MageOS\AiBase\Model\ModelList\HttpFetcher;

class Ollama implements AiServiceConfigurationInterface, ModelListProviderInterface
{
    use FieldFactoryTrait;
    use ModelListTrait;

    /**
     * Default base URL of a local Ollama instance.
     */
    private const DEFAULT_BASE_URL = 'http://localhost:11434';

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
        return 'ollama';
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'Ollama';
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
        $response = $this->modelListFetcher->getJson($baseUrl . '/api/tags');

        if (!isset($response['models']) || !is_array($response['models'])) {
            throw new LocalizedException(
                __('Unexpected model list response from %1: missing "models" list.', $this->getName())
            );
        }

        $models = [];
        foreach ($response['models'] as $entry) {
            if (!is_array($entry) || !isset($entry['name']) || !is_string($entry['name']) || $entry['name'] === '') {
                continue;
            }
            $models[$entry['name']] = $entry['name'];
        }

        return $models;
    }
}
