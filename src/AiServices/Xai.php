<?php

declare(strict_types=1);

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;

class Xai implements AiServiceConfigurationInterface
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
        return 'xai';
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'xAI';
    }

    /**
     * @inheritdoc
     */
    public function getSupportedModels(): array
    {
        return [
            'grok-2'      => 'Grok 2',
            'grok-2-mini' => 'Grok 2 mini',
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
