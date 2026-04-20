<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api\Data;

interface FieldDescriptorInterface
{
    public const TYPE_TEXT     = 'text';
    public const TYPE_PASSWORD = 'password';
    public const TYPE_SELECT   = 'select';

    public function getName(): string;
    public function getLabel(): string;
    public function getType(): string;

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function getOptions(): array;

    public function getDefault(): ?string;
}
