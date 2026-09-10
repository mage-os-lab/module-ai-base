<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api\Data;

/**
 * Token counts and call count aggregated over a {@see Period}.
 *
 * The shape {@see \MageOS\AiBase\Api\UsageStatsInterface::getTotals()} returns directly, and the
 * shape every {@see UsageBreakdownInterface} carries per group: one grand total or one row per
 * consumer/service/bucket is the same set of numbers, so there is one interface for both rather
 * than two that would only ever say the same thing.
 */
interface UsageTotalsInterface
{
    /**
     * Number of recorded calls the totals cover.
     *
     * @return int
     */
    public function getCalls(): int;

    /**
     * Prompt tokens summed across every covered call.
     *
     * @return int
     */
    public function getInputTokens(): int;

    /**
     * Completion tokens summed across every covered call.
     *
     * @return int
     */
    public function getOutputTokens(): int;

    /**
     * Total tokens summed across every covered call.
     *
     * @return int
     */
    public function getTotalTokens(): int;

    /**
     * Cached prompt tokens summed across every covered call that reported them.
     *
     * Null, not zero, when nothing in the window ever reported a cached count: the same
     * "not reported" versus "reported as zero" distinction
     * {@see \MageOS\AiBase\Api\Data\UsageRecordInterface::getCachedTokens()} draws at the row
     * level, carried through the aggregate rather than lost by it.
     *
     * @return int|null
     */
    public function getCachedTokens(): ?int;

    /**
     * Reasoning tokens summed across every covered call that reported them.
     *
     * @return int|null
     */
    public function getReasoningTokens(): ?int;
}
