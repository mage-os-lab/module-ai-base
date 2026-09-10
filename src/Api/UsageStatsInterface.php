<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api;

use MageOS\AiBase\Api\Data\Granularity;
use MageOS\AiBase\Api\Data\Period;
use MageOS\AiBase\Api\Data\UsageBreakdownInterface;
use MageOS\AiBase\Api\Data\UsageTotalsInterface;

/**
 * The one read contract the admin dashboard, its graphs, and a CLI or another module all go
 * through to answer "how much AI usage happened, by whom, through which service, over time".
 *
 * A {@see Period} can straddle the retention boundary between the raw `mageos_ai_usage_log` table
 * ({@see UsageRecordRepositoryInterface}, task 005) and the aggregated `mageos_ai_usage_daily`
 * table ({@see UsageDailyRepositoryInterface}, task 010): the implementation is responsible for
 * querying whichever table or tables a given window touches and merging the result into one
 * answer, without ever double-counting a call that exists in both because the daily roll-up has
 * not pruned it yet. See {@see \MageOS\AiBase\Model\Usage\UsageStats} for exactly how.
 */
interface UsageStatsInterface
{

    /**
     * Key of the series the grouped time-series methods sum the tail of the field into.
     */
    public const SERIES_OTHER = 'other';

    /**
     * Every read below takes an optional store id.
     *
     * `null` means every store, not the admin store: a figure that silently covered one storefront
     * out of several would be wrong in a way nobody would see, whereas a caller wanting one store
     * always knows which. The raw table records the store a call was made in and the daily table
     * keys its aggregates on it, so both sides of a union narrow the same way.
     */

    /**
     * The grand total for a period: every call, consumer and service row summed into one total.
     *
     * @param Period $period
     * @param int|null $storeId Narrow to one store, or `null` for every store
     * @return UsageTotalsInterface
     */
    public function getTotals(Period $period, ?int $storeId = null): UsageTotalsInterface;

    /**
     * The same period broken down by consumer, ordered by
     * {@see UsageTotalsInterface::getTotalTokens()} descending so the biggest consumer reads off
     * first without the caller sorting again.
     *
     * @param Period $period
     * @param int|null $storeId Narrow to one store, or `null` for every store
     * @return UsageBreakdownInterface[]
     */
    public function getByConsumer(Period $period, ?int $storeId = null): array;

    /**
     * The same period broken down by
     * {@see \MageOS\AiBase\Api\Data\UsageRecordInterface::getServiceId()}, ordered the same way as
     * {@see getByConsumer()}.
     *
     * @param Period $period
     * @param int|null $storeId Narrow to one store, or `null` for every store
     * @return UsageBreakdownInterface[]
     */
    public function getByService(Period $period, ?int $storeId = null): array;

    /**
     * The period bucketed per day or per month, one {@see UsageBreakdownInterface} per bucket in
     * ascending order, {@see UsageBreakdownInterface::getGroupValue()} carrying the bucket's `Y-m-d`
     * or `Y-m` label.
     *
     * Every bucket the period touches is present, even one with no recorded usage at all: a
     * caller feeding this straight into a graph should never have to fill a gap itself.
     *
     * @param Period $period
     * @param Granularity $granularity
     * @param int|null $storeId Narrow to one store, or `null` for every store
     * @return UsageBreakdownInterface[]
     */
    public function getTimeSeries(
        Period $period,
        Granularity $granularity,
        ?int $storeId = null
    ): array;

    /**
     * The period bucketed per day or per month *and* split by consumer: one dense, ordered series
     * per consumer, every series carrying the same bucket labels in the same order as
     * {@see getTimeSeries()} so a caller can plot them on one shared axis without aligning
     * anything itself.
     *
     * Only the busiest `$limit` consumers get a series of their own; everything behind them is
     * summed into a single trailing series keyed {@see self::SERIES_OTHER}. A chart cannot tell
     * apart more series than it has distinguishable colours, and inventing more colours is how a
     * palette stops being legible — so the fold happens here, once, rather than in each caller.
     *
     * @param Period $period
     * @param Granularity $granularity
     * @param int $limit Consumers given their own series, before the fold
     * @param int|null $storeId Narrow to one store, or `null` for every store
     * @return array<string,UsageBreakdownInterface[]> Consumer => its buckets, busiest first
     */
    public function getTimeSeriesByConsumer(
        Period $period,
        Granularity $granularity,
        int $limit,
        ?int $storeId = null
    ): array;

    /**
     * The same thing as {@see getTimeSeriesByConsumer()}, split by service row instead.
     *
     * The two questions are different: a consumer is a feature an administrator can switch off, a
     * service row is an account they are billed on. A store running one feature across two
     * providers, or two features on one, reads only one of them usefully.
     *
     * @param Period $period
     * @param Granularity $granularity
     * @param int $limit Service rows given their own series, before the fold
     * @param int|null $storeId Narrow to one store, or `null` for every store
     * @return array<string,UsageBreakdownInterface[]> Service row id => its buckets, busiest first
     */
    public function getTimeSeriesByService(
        Period $period,
        Granularity $granularity,
        int $limit,
        ?int $storeId = null
    ): array;
}
