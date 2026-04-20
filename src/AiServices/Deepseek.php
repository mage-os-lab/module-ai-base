<?php

declare(strict_types=1);

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;

final class Deepseek implements AiServiceConfigurationInterface
{
    use FieldFactoryTrait;

    public function __construct(
        private readonly FieldDescriptorInterfaceFactory $fieldFactory,
    ) {}

    public function getCode(): string
    {
        return 'deepseek';
    }

    public function getName(): string
    {
        return 'DeepSeek';
    }

    public function getSupportedModels(): array
    {
        return [
            'deepseek-chat'     => 'DeepSeek V3',
            'deepseek-reasoner' => 'DeepSeek R1',
        ];
    }

    public function getConfigurationFields(): array
    {
        return [
            $this->apiKeyField($this->fieldFactory),
            $this->modelField($this->fieldFactory, $this->getSupportedModels()),
        ];
    }
}
