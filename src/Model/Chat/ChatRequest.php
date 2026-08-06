<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Chat;

use MageOS\AiBase\Api\Data\ChatMessageInterface;
use MageOS\AiBase\Api\Data\ChatRequestInterface;
use MageOS\AiBase\Api\Data\MessageRole;
use MageOS\AiBase\Api\Data\ToolCallInterface;
use MageOS\AiBase\Api\Data\ToolDefinitionInterface;

class ChatRequest implements ChatRequestInterface
{
    /**
     * @param ChatMessageInterface[] $messages
     * @param ToolDefinitionInterface[] $tools
     */
    public function __construct(
        private readonly array $messages,
        private readonly array $tools = [],
    ) {
        $this->assertAll($this->messages, ChatMessageInterface::class);
        $this->assertAll($this->tools, ToolDefinitionInterface::class);

        if ($this->messages === []) {
            throw new \InvalidArgumentException('A chat request needs at least one message.');
        }
    }

    /**
     * @inheritdoc
     */
    public function getMessages(): array
    {
        return array_values($this->messages);
    }

    /**
     * @inheritdoc
     */
    public function getTools(): array
    {
        return array_values($this->tools);
    }

    /**
     * @inheritdoc
     */
    public function withMessage(ChatMessageInterface $message): ChatRequestInterface
    {
        return new self([...$this->getMessages(), $message], $this->tools);
    }

    /**
     * @inheritdoc
     */
    public function withToolResult(ToolCallInterface $toolCall, string $result): ChatRequestInterface
    {
        return $this->withMessage(new ChatMessage(MessageRole::Tool, $result, [], $toolCall));
    }

    /**
     * Reject a heterogeneous list before it reaches a provider as a malformed payload.
     *
     * @param array $items
     * @param string $expected
     * @return void
     */
    private function assertAll(array $items, string $expected): void
    {
        foreach ($items as $item) {
            if (!$item instanceof $expected) {
                throw new \InvalidArgumentException(sprintf(
                    'Every entry must implement %s, got %s',
                    $expected,
                    get_debug_type($item),
                ));
            }
        }
    }
}
