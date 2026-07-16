<?php

declare(strict_types=1);

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;

class Deepseek implements AiServiceConfigurationInterface
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
        return 'deepseek';
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'DeepSeek';
    }

    /**
     * @inheritdoc
     */
    public function getSupportedModels(): array
    {
        return [
            'deepseek-chat'     => 'DeepSeek V3',
            'deepseek-reasoner' => 'DeepSeek R1',
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
