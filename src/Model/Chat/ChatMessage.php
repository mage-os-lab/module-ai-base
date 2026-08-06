<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Chat;

use MageOS\AiBase\Api\Data\ChatMessageInterface;
use MageOS\AiBase\Api\Data\MessageRole;
use MageOS\AiBase\Api\Data\ToolCallInterface;

class ChatMessage implements ChatMessageInterface
{
    /**
     * @param MessageRole $role
     * @param string $content
     * @param ToolCallInterface[] $toolCalls Assistant turns only
     * @param ToolCallInterface|null $answeredToolCall Tool-result turns only
     */
    public function __construct(
        private readonly MessageRole $role,
        private readonly string $content = '',
        private readonly array $toolCalls = [],
        private readonly ?ToolCallInterface $answeredToolCall = null,
    ) {
        foreach ($this->toolCalls as $toolCall) {
            if (!$toolCall instanceof ToolCallInterface) {
                throw new \InvalidArgumentException(sprintf(
                    'Tool calls must implement %s, got %s',
                    ToolCallInterface::class,
                    get_debug_type($toolCall),
                ));
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function getRole(): MessageRole
    {
        return $this->role;
    }

    /**
     * @inheritdoc
     */
    public function getContent(): string
    {
        return $this->content;
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
    public function getAnsweredToolCall(): ?ToolCallInterface
    {
        return $this->answeredToolCall;
    }

    /**
     * @inheritdoc
     */
    public function getToolCallId(): ?string
    {
        return $this->answeredToolCall?->getId();
    }
}
