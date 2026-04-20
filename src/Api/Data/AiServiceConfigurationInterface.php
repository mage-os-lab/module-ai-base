<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api\Data;

interface AiServiceConfigurationInterface
{
    public function getCode(): string;
    public function getName(): string;

    /**
     * @return FieldDescriptorInterface[]
     */
    public function getConfigurationFields(): array;

    /**
     * @return array<string, string> value => label; empty array for services with no model list
     */
    public function getSupportedModels(): array;
}
