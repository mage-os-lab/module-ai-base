<?php

declare(strict_types=1);

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;

class Anthropic implements AiServiceConfigurationInterface
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
}
