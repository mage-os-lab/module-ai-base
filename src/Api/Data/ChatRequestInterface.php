<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api\Data;

/**
 * A conversation to send, plus the tools the model may call.
 *
 * Immutable: a tool loop appends to the conversation on every iteration, and mutating in place
 * would let one iteration's bookkeeping leak into a request the caller still holds.
 */
interface ChatRequestInterface
{
    /**
     * The conversation so far, oldest first.
     *
     * @return list<ChatMessageInterface>
     */
    public function getMessages(): array;

    /**
     * Tools offered to the model; empty when the model should only answer.
     *
     * @return list<ToolDefinitionInterface>
     */
    public function getTools(): array;

    /**
     * Copy with one more message appended.
     *
     * @param ChatMessageInterface $message
     * @return ChatRequestInterface
     */
    public function withMessage(ChatMessageInterface $message): ChatRequestInterface;

    /**
     * Copy with a tool's result appended, bound to the call that produced it.
     *
     * Pairing the result to its call id is the step a hand-built tool loop most easily gets
     * wrong, and the mistake stays invisible until the provider rejects the next request.
     *
     * @param ToolCallInterface $toolCall
     * @param string $result
     * @return ChatRequestInterface
     */
    public function withToolResult(ToolCallInterface $toolCall, string $result): ChatRequestInterface;
}
