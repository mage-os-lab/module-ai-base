<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api\Data;

interface AiServiceConfigurationInterface
{
    /**
     * Machine code identifying this AI backend.
     *
     * @return string
     */
    public function getCode(): string;

    /**
     * Human-readable display name shown in the admin form.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Field descriptors rendered in the admin configuration form.
     *
     * @return FieldDescriptorInterface[]
     */
    public function getConfigurationFields(): array;

    /**
     * Curated model list for this backend.
     *
     * @return array<string, string> value => label; empty array for services with no model list
     */
    public function getSupportedModels(): array;
}
