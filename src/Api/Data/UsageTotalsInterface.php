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
     * Cache-read prompt tokens summed across every covered call that reported them.
     *
     * Null, not zero, when nothing in the window ever reported a cache-read count: the same
     * "not reported" versus "reported as zero" distinction
     * {@see \MageOS\AiBase\Api\Data\UsageRecordInterface::getCacheReadTokens()} draws at the row
     * level, carried through the aggregate rather than lost by it.
     *
     * @return int|null
     */
    public function getCacheReadTokens(): ?int;

    /**
     * Cache-write prompt tokens summed across every covered call that reported them.
     *
     * Null, not zero, on the same "not reported" versus "reported as zero" terms as
     * {@see getCacheReadTokens()}.
     *
     * @return int|null
     */
    public function getCacheWriteTokens(): ?int;

    /**
     * Reasoning tokens summed across every covered call that reported them.
     *
     * @return int|null
     */
    public function getReasoningTokens(): ?int;

    /**
     * Number of calls the totals cover that {@see \MageOS\AiBase\Api\Data\UsageRecordInterface::isFailed()}.
     *
     * Counted separately from, not subtracted out of, {@see getCalls()}: a failed call still used a
     * connection and, when the provider reported any usage before failing, still spent tokens, so it
     * belongs in the call count and needs its own visible count rather than being folded into it.
     *
     * @return int
     */
    public function getFailedCalls(): int;
}
