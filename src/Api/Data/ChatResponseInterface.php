<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api\Data;

/**
 * What the model returned for one exchange.
 */
interface ChatResponseInterface
{
    /**
     * Assistant text, empty when the model only requested tools.
     *
     * @return string
     */
    public function getText(): string;

    /**
     * Tools the model wants run before it can continue.
     *
     * @return list<ToolCallInterface>
     */
    public function getToolCalls(): array;

    /**
     * Whether the model asked for at least one tool: the exit condition of a tool loop.
     *
     * @return bool
     */
    public function hasToolCalls(): bool;

    /**
     * Token counts for this exchange, or null when the provider reported none.
     *
     * @return TokenUsageInterface|null
     */
    public function getUsage(): ?TokenUsageInterface;

    /**
     * Why the model stopped, normalized across providers, or null when none was reported.
     *
     * FinishReason::Length is the one worth handling in every consumer: the text is a truncated
     * answer rather than a finished one, and nothing else in the response says so.
     *
     * @return FinishReason|null
     */
    public function getFinishReason(): ?FinishReason;

    /**
     * The stop reason in the provider's own wording, or null when it reported none.
     *
     * For logging and support tickets. Branch on getFinishReason() instead: the wording differs
     * per provider for the same event, and moving a workload to another backend must not silently
     * turn a branch false.
     *
     * @return string|null
     */
    public function getRawFinishReason(): ?string;
}
