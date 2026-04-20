<?php

declare(strict_types=1);

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;

final class Anthropic implements AiServiceConfigurationInterface
{
    use FieldFactoryTrait;

    public function __construct(
        private readonly FieldDescriptorInterfaceFactory $fieldFactory,
    ) {}

    public function getCode(): string
    {
        return 'anthropic';
    }

    public function getName(): string
    {
        return 'Anthropic';
    }

    public function getSupportedModels(): array
    {
        return [
            'claude-opus-4-7'           => 'Claude Opus 4.7',
            'claude-sonnet-4-6'         => 'Claude Sonnet 4.6',
            'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5',
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
