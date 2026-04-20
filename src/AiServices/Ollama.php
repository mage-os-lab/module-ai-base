<?php

declare(strict_types=1);

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;

final class Ollama implements AiServiceConfigurationInterface
{
    use FieldFactoryTrait;

    public function __construct(
        private readonly FieldDescriptorInterfaceFactory $fieldFactory,
    ) {}

    public function getCode(): string
    {
        return 'ollama';
    }

    public function getName(): string
    {
        return 'Ollama';
    }

    public function getSupportedModels(): array
    {
        return [];
    }

    public function getConfigurationFields(): array
    {
        return [
            $this->baseUrlField($this->fieldFactory, 'http://localhost:11434'),
            $this->freeTextModelField($this->fieldFactory),
        ];
    }
}
