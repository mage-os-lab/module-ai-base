<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model;

use MageOS\AiBase\Api\Data\FieldDescriptorInterface;
use MageOS\AiBase\Model\FieldDescriptor;
use PHPUnit\Framework\TestCase;

final class FieldDescriptorTest extends TestCase
{
    public function test_exposes_required_fields(): void
    {
        $field = new FieldDescriptor(
            name: 'api_key',
            label: 'API Key',
            type: FieldDescriptorInterface::TYPE_PASSWORD,
        );

        self::assertSame('api_key', $field->getName());
        self::assertSame('API Key', $field->getLabel());
        self::assertSame(FieldDescriptorInterface::TYPE_PASSWORD, $field->getType());
        self::assertSame([], $field->getOptions());
        self::assertNull($field->getDefault());
    }

    public function test_select_field_carries_options_and_default(): void
    {
        $field = new FieldDescriptor(
            name: 'model',
            label: 'Model',
            type: FieldDescriptorInterface::TYPE_SELECT,
            options: [
                ['value' => 'a', 'label' => 'Apple'],
                ['value' => 'b', 'label' => 'Banana'],
            ],
            default: 'a',
        );

        self::assertSame('model', $field->getName());
        self::assertSame(FieldDescriptorInterface::TYPE_SELECT, $field->getType());
        self::assertCount(2, $field->getOptions());
        self::assertSame('a', $field->getDefault());
    }
}
