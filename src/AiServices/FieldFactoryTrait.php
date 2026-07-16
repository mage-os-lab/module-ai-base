<?php

declare(strict_types=1);

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\FieldDescriptorInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;

trait FieldFactoryTrait
{
    /**
     * Build the standard API key password field.
     *
     * @param FieldDescriptorInterfaceFactory $factory
     * @return FieldDescriptorInterface
     */
    private function apiKeyField(FieldDescriptorInterfaceFactory $factory): FieldDescriptorInterface
    {
        return $factory->create([
            'name'  => 'api_key',
            'label' => 'API Key',
            'type'  => FieldDescriptorInterface::TYPE_PASSWORD,
        ]);
    }

    /**
     * Build the standard model select field from a supported-models map.
     *
     * @param FieldDescriptorInterfaceFactory $factory
     * @param array $supportedModels Map of model value => label
     * @return FieldDescriptorInterface
     */
    private function modelField(
        FieldDescriptorInterfaceFactory $factory,
        array $supportedModels
    ): FieldDescriptorInterface {
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

    /**
     * Build the standard base URL text field.
     *
     * @param FieldDescriptorInterfaceFactory $factory
     * @param string $default
     * @return FieldDescriptorInterface
     */
    private function baseUrlField(FieldDescriptorInterfaceFactory $factory, string $default): FieldDescriptorInterface
    {
        return $factory->create([
            'name'    => 'base_url',
            'label'   => 'Base URL',
            'type'    => FieldDescriptorInterface::TYPE_TEXT,
            'default' => $default,
        ]);
    }

    /**
     * Build a free-text model field for services without a curated model list.
     *
     * @param FieldDescriptorInterfaceFactory $factory
     * @return FieldDescriptorInterface
     */
    private function freeTextModelField(FieldDescriptorInterfaceFactory $factory): FieldDescriptorInterface
    {
        return $factory->create([
            'name'  => 'model',
            'label' => 'Model',
            'type'  => FieldDescriptorInterface::TYPE_TEXT,
        ]);
    }
}
