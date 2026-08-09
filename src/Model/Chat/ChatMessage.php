<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Chat;

use MageOS\AiBase\Api\Data\ChatMessageInterface;
use MageOS\AiBase\Api\Data\MessageRole;
use MageOS\AiBase\Api\Data\ToolCallInterface;

class ChatMessage implements ChatMessageInterface
{
    /**
     * @var ToolCallInterface[]
     */
    private readonly array $toolCalls;

    /**
     * @param MessageRole $role
     * @param string $content
     * @param array<mixed> $toolCalls Assistant turns only, validated below
     * @param ToolCallInterface|null $answeredToolCall Tool-result turns only
     */
    public function __construct(
        private readonly MessageRole $role,
        private readonly string $content = '',
        array $toolCalls = [],
        private readonly ?ToolCallInterface $answeredToolCall = null,
    ) {
        $this->toolCalls = $this->assertToolCalls($toolCalls);
    }

    /**
     * Reject a caller-supplied entry that is not a tool call.
     *
     * The array is built by the consuming module, so this is the only place the promise made
     * by the property type is actually enforced.
     *
     * @param array<mixed> $toolCalls
     * @return ToolCallInterface[]
     */
    private function assertToolCalls(array $toolCalls): array
    {
        $validated = [];
        foreach ($toolCalls as $toolCall) {
            if (!$toolCall instanceof ToolCallInterface) {
                throw new \InvalidArgumentException(sprintf(
                    'Tool calls must implement %s, got %s',
                    ToolCallInterface::class,
                    get_debug_type($toolCall),
                ));
            }
            $validated[] = $toolCall;
        }

        return $validated;
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
        return $this->toolCalls;
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
