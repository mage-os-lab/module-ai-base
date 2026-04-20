<?php

declare(strict_types=1);

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;

final class OpenRouter implements AiServiceConfigurationInterface
{
    use FieldFactoryTrait;

    public function __construct(
        private readonly FieldDescriptorInterfaceFactory $fieldFactory,
    ) {}

    public function getCode(): string
    {
        return 'openrouter';
    }

    public function getName(): string
    {
        return 'OpenRouter';
    }

    public function getSupportedModels(): array
    {
        return [];
    }

    public function getConfigurationFields(): array
    {
        return [
            $this->apiKeyField($this->fieldFactory),
            $this->freeTextModelField($this->fieldFactory),
        ];
    }
}
