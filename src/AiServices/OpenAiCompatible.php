<?php

declare(strict_types=1);

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;

/**
 * Any server that speaks the de facto OpenAI Chat Completions wire format on a host of the
 * administrator's own choosing — a self-hosted gateway or aggregator (LiteLLM, an
 * OpenRouter-style proxy, Eden AI), not a specific vendor. Unlike Ollama and LM Studio this has
 * no sensible default host, so the field ships without one and an administrator must supply both
 * the endpoint and, where the server requires it, an API key.
 */
class OpenAiCompatible implements AiServiceConfigurationInterface
{
    use FieldFactoryTrait;

    /**
     * @param FieldDescriptorInterfaceFactory $fieldFactory
     */
    public function __construct(
        private readonly FieldDescriptorInterfaceFactory $fieldFactory,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getCode(): string
    {
        return 'openai_compatible';
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'OpenAI-Compatible';
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
            $this->baseUrlField($this->fieldFactory, '', 'Base URL (will strip /v1)'),
            $this->apiKeyField($this->fieldFactory),
            $this->freeTextModelField($this->fieldFactory),
        ];
    }
}
