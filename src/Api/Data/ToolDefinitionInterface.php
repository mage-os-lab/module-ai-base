<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api\Data;

/**
 * A tool offered to the model, described well enough for it to decide when to call it.
 */
interface ToolDefinitionInterface
{
    /**
     * Name the model uses to call this tool.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * What the tool does, written for the model rather than for a developer.
     *
     * @return string
     */
    public function getDescription(): string;

    /**
     * JSON Schema describing the tool's arguments.
     *
     * @return array<string, mixed>
     */
    public function getParameters(): array;
}
