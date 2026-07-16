<?php

declare(strict_types=1);

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;

class Azure implements AiServiceConfigurationInterface
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
        return 'azure';
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'Azure OpenAI';
    }

    /**
     * @inheritdoc
     */
    public function getSupportedModels(): array
    {
        return [
            'gpt-4o'      => 'GPT-4o',
            'gpt-4o-mini' => 'GPT-4o mini',
            'gpt-4-turbo' => 'GPT-4 Turbo',
            'o1'          => 'o1',
            'o1-mini'     => 'o1 mini',
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
