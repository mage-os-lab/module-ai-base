<?php

declare(strict_types=1);

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;

class LmStudio implements AiServiceConfigurationInterface
{
    use FieldFactoryTrait;

    public function __construct(
        private readonly FieldDescriptorInterfaceFactory $fieldFactory,
    ) {}

    public function getCode(): string
    {
        return 'lmstudio';
    }

    public function getName(): string
    {
        return 'LM Studio';
    }

    public function getSupportedModels(): array
    {
        return [];
    }

    public function getConfigurationFields(): array
    {
        return [
            $this->baseUrlField($this->fieldFactory, 'http://localhost:1234'),
            $this->freeTextModelField($this->fieldFactory),
        ];
    }
}
