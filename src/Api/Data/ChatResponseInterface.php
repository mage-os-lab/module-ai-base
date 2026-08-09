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
     * Provider's stop reason as it reported it, or null when it reported none.
     *
     * @return string|null
     */
    public function getFinishReason(): ?string;
}
