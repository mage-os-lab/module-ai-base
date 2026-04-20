<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model;

use MageOS\AiBase\Api\Data\FieldDescriptorInterface;

final class FieldDescriptor implements FieldDescriptorInterface
{
    public function __construct(
        private readonly string $name,
        private readonly string $label,
        private readonly string $type,
        private readonly array $options = [],
        private readonly ?string $default = null,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function getDefault(): ?string
    {
        return $this->default;
    }
}
