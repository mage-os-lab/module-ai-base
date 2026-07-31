<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model;

use MageOS\AiBase\Api\Data\FieldDescriptorInterface;

class FieldDescriptor implements FieldDescriptorInterface
{
    /**
     * @param string $name
     * @param string $label
     * @param string $type
     * @param array $options
     * @param string|null $default
     * @param bool $encrypted
     */
    public function __construct(
        private readonly string $name,
        private readonly string $label,
        private readonly string $type,
        private readonly array $options = [],
        private readonly ?string $default = null,
        private readonly bool $encrypted = false,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @inheritdoc
     */
    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * @inheritdoc
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @inheritdoc
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * @inheritdoc
     */
    public function getDefault(): ?string
    {
        return $this->default;
    }

    /**
     * @inheritdoc
     */
    public function isEncrypted(): bool
    {
        return $this->encrypted;
    }
}
