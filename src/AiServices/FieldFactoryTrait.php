<?php

declare(strict_types=1);

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\FieldDescriptorInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;

trait FieldFactoryTrait
{
    private function apiKeyField(FieldDescriptorInterfaceFactory $factory): FieldDescriptorInterface
    {
        return $factory->create([
            'name'  => 'apikey',
            'label' => 'API Key',
            'type'  => FieldDescriptorInterface::TYPE_PASSWORD,
        ]);
    }

    /**
     * @param array<string, string> $supportedModels
     */
    private function modelField(FieldDescriptorInterfaceFactory $factory, array $supportedModels): FieldDescriptorInterface
    {
        $options = [];
        foreach ($supportedModels as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => (string) $label];
        }
        return $factory->create([
            'name'    => 'model',
            'label'   => 'Model',
            'type'    => FieldDescriptorInterface::TYPE_SELECT,
            'options' => $options,
        ]);
    }

    private function baseUrlField(FieldDescriptorInterfaceFactory $factory, string $default): FieldDescriptorInterface
    {
        return $factory->create([
            'name'    => 'base_url',
            'label'   => 'Base URL',
            'type'    => FieldDescriptorInterface::TYPE_TEXT,
            'default' => $default,
        ]);
    }

    private function freeTextModelField(FieldDescriptorInterfaceFactory $factory): FieldDescriptorInterface
    {
        return $factory->create([
            'name'  => 'model',
            'label' => 'Model',
            'type'  => FieldDescriptorInterface::TYPE_TEXT,
        ]);
    }
}
