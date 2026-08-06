<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Chat;

use MageOS\AiBase\Api\Data\StreamChunkInterface;
use MageOS\AiBase\Api\Data\StreamChunkType;
use MageOS\AiBase\Api\Data\TokenUsageInterface;
use MageOS\AiBase\Api\Data\ToolCallInterface;

class StreamChunk implements StreamChunkInterface
{
    /**
     * @param StreamChunkType $type
     * @param string $text
     * @param ToolCallInterface|null $toolCall
     * @param TokenUsageInterface|null $usage
     */
    public function __construct(
        private readonly StreamChunkType $type,
        private readonly string $text = '',
        private readonly ?ToolCallInterface $toolCall = null,
        private readonly ?TokenUsageInterface $usage = null,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getType(): StreamChunkType
    {
        return $this->type;
    }

    /**
     * @inheritdoc
     */
    public function getText(): string
    {
        return $this->text;
    }

    /**
     * @inheritdoc
     */
    public function getToolCall(): ?ToolCallInterface
    {
        return $this->toolCall;
    }

    /**
     * @inheritdoc
     */
    public function getUsage(): ?TokenUsageInterface
    {
        return $this->usage;
    }

    /**
     * @inheritdoc
     */
    public function getData(): array
    {
        return match ($this->type) {
            StreamChunkType::Text, StreamChunkType::Thinking => ['text' => $this->text],
            StreamChunkType::ToolCall => [
                'id' => $this->toolCall?->getId() ?? '',
                'name' => $this->toolCall?->getName() ?? '',
                'input' => $this->toolCall?->getArguments() ?? [],
            ],
            StreamChunkType::Usage => [
                'prompt_tokens' => $this->usage?->getPromptTokens(),
                'completion_tokens' => $this->usage?->getCompletionTokens(),
                'total_tokens' => $this->usage?->getTotalTokens(),
            ],
        };
    }
}
