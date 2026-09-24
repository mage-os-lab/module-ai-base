<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api\Data;

/**
 * One event from a streamed chat.
 */
interface StreamChunkInterface
{
    /**
     * What kind of event this is.
     *
     * @return StreamChunkType
     */
    public function getType(): StreamChunkType;

    /**
     * Text delta for text and thinking chunks; empty for every other type.
     *
     * @return string
     */
    public function getText(): string;

    /**
     * The tool call, for tool call chunks only.
     *
     * Arguments are empty on a {@see StreamChunkType::ToolCallStart} chunk, since the model has
     * only just opened the call; the completed arguments arrive later on a
     * {@see StreamChunkType::ToolCall} chunk.
     *
     * @return ToolCallInterface|null
     */
    public function getToolCall(): ?ToolCallInterface;

    /**
     * Token counts, for usage chunks only.
     *
     * @return TokenUsageInterface|null
     */
    public function getUsage(): ?TokenUsageInterface;

    /**
     * Flat payload for consumers bridging this to a callback-style stream.
     *
     * @return array<string, mixed>
     */
    public function getData(): array;
}
