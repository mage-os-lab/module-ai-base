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

    /**
     * Tokens served from the provider's prompt cache rather than freshly processed.
     *
     * This is a subset of {@see getPromptTokens()}, not an addition to it: providers that report
     * a cache hit still include the cached portion in the prompt count, and callers that also add
     * this value into a total would double-count those tokens.
     *
     * @return int|null
     */
    public function getCachedTokens(): ?int;

    /**
     * Tokens the model spent on internal reasoning before producing the completion text.
     *
     * This is a subset of {@see getCompletionTokens()}, not an addition to it, for every provider
     * that reports it: the reasoning tokens are already counted in the completion total.
     *
     * @return int|null
     */
    public function getReasoningTokens(): ?int;
}
