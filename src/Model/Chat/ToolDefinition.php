<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Chat;

use MageOS\AiBase\Api\Data\ToolDefinitionInterface;

class ToolDefinition implements ToolDefinitionInterface
{
    /**
     * Schema sent when a tool takes no arguments.
     */
    private const EMPTY_SCHEMA = ['type' => 'object', 'properties' => []];

    /**
     * @param string $name
     * @param string $description
     * @param array<string,mixed> $parameters JSON Schema; defaults to an empty object schema
     */
    public function __construct(
        private readonly string $name,
        private readonly string $description,
        private readonly array $parameters = self::EMPTY_SCHEMA,
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
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * @inheritdoc
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }
}
