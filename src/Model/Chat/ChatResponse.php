<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Chat;

use MageOS\AiBase\Api\Data\ChatResponseInterface;
use MageOS\AiBase\Api\Data\FinishReason;
use MageOS\AiBase\Api\Data\TokenUsageInterface;
use MageOS\AiBase\Api\Data\ToolCallInterface;

class ChatResponse implements ChatResponseInterface
{
    /**
     * @param string $text
     * @param ToolCallInterface[] $toolCalls
     * @param TokenUsageInterface|null $usage
     * @param FinishReason|null $finishReason
     * @param string|null $rawFinishReason Stop reason in the provider's own wording
     */
    public function __construct(
        private readonly string $text = '',
        private readonly array $toolCalls = [],
        private readonly ?TokenUsageInterface $usage = null,
        private readonly ?FinishReason $finishReason = null,
        private readonly ?string $rawFinishReason = null,
    ) {
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
    public function getToolCalls(): array
    {
        return array_values($this->toolCalls);
    }

    /**
     * @inheritdoc
     */
    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
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
    public function getFinishReason(): ?FinishReason
    {
        return $this->finishReason;
    }

    /**
     * @inheritdoc
     */
    public function getRawFinishReason(): ?string
    {
        return $this->rawFinishReason;
    }
}
