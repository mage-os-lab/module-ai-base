<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api\Data;

/**
 * Token counts reported by a provider for one exchange.
 *
 * Every count is nullable: providers differ in what they report, and streaming responses only
 * carry the completion count once the stream ends.
 */
interface TokenUsageInterface
{
    /**
     * Tokens consumed by the prompt.
     *
     * @return int|null
     */
    public function getPromptTokens(): ?int;

    /**
     * Tokens produced in the completion.
     *
     * @return int|null
     */
    public function getCompletionTokens(): ?int;

    /**
     * Total tokens, falling back to prompt plus completion when the provider reported no total.
     *
     * @return int|null
     */
    public function getTotalTokens(): ?int;
}
