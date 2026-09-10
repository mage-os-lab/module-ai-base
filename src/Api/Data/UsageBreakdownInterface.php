<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api\Data;

/**
 * One row of a grouped answer from {@see \MageOS\AiBase\Api\UsageStatsInterface}: one consumer,
 * one service row, or one time-series bucket, paired with its own {@see UsageTotalsInterface}.
 *
 * The same shape serves all three groupings deliberately: a consumer name, a service row id and a
 * `Y-m-d`/`Y-m` period label are all just the group's own label as far as a caller reading
 * {@see getGroupValue()} needs to know, and duplicating this interface three times over would only
 * buy three places for the totals shape to drift.
 */
interface UsageBreakdownInterface
{
    /**
     * The value identifying this group: a consumer name, a
     * {@see \MageOS\AiBase\Api\Data\UsageRecordInterface::getServiceId()}, or a time-series period
     * label, depending on which {@see \MageOS\AiBase\Api\UsageStatsInterface} method produced this
     * row.
     *
     * @return string
     */
    public function getGroupValue(): string;

    /**
     * The aggregated token counts for this group.
     *
     * @return UsageTotalsInterface
     */
    public function getTotals(): UsageTotalsInterface;
}
