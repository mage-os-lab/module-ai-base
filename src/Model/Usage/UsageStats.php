<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Usage;

use MageOS\AiBase\Api\Data\Granularity;
use MageOS\AiBase\Api\Data\Period;
use MageOS\AiBase\Api\Data\UsageBreakdownInterface;
use MageOS\AiBase\Api\Data\UsageTotalsInterface;
use MageOS\AiBase\Api\UsageDailyRepositoryInterface;
use MageOS\AiBase\Api\UsageRecordRepositoryInterface;
use MageOS\AiBase\Api\UsageStatsInterface;

/**
 * Implementation of {@see UsageStatsInterface}.
 *
 * The hard part this class exists for: a {@see Period} can straddle the boundary between
 * {@see UsageRecordRepositoryInterface} (the raw log) and {@see UsageDailyRepositoryInterface}
 * (the roll-up). Every public method here queries both repositories and merges the rows in PHP,
 * but the aggregation itself always happens in SQL inside those two repositories — this class
 * never sums a raw row directly, only the small pre-aggregated totals each repository call
 * already returns.
 *
 * The boundary between the two tables is {@see UsageRecordRepositoryInterface::getOldestRecordedAt()},
 * not a configured retention window: a store whose cron has not run yet, or whose raw retention
 * was just changed, still gets a correct answer, because the boundary is read from the data that
 * is actually there rather than from what a setting says should be there. Every daily-side query
 * this class makes is capped at that boundary, so a day that is present in both tables (the cron
 * rolled it up earlier today, and new calls have since been logged for the same day) is only ever
 * counted from the raw side.
 */
class UsageStats implements UsageStatsInterface
{
    /**
     * @param UsageRecordRepositoryInterface $rawUsageRepository The raw `mageos_ai_usage_log` side.
     * @param UsageDailyRepositoryInterface $dailyUsageRepository The aggregated
     *        `mageos_ai_usage_daily` side.
     * @param \Magento\Framework\Stdlib\DateTime\TimezoneInterface $timezone Store timezone the daily
     *        table's window bounds are resolved in; see {@see localBound()}.
     */
    public function __construct(
        private readonly UsageRecordRepositoryInterface $rawUsageRepository,
        private readonly UsageDailyRepositoryInterface $dailyUsageRepository,
        private readonly \Magento\Framework\Stdlib\DateTime\TimezoneInterface $timezone,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getTotals(Period $period, ?int $storeId = null): UsageTotalsInterface
    {
        return $this->rowsToTotals([
            $this->rawUsageRepository->sumRange($period->getStart(), $period->getEnd(), null, $storeId),
            $this->dailyUsageRepository->sumRange(
                $this->localBound($period->getStart()),
                $this->localBound($this->dailyWindowEnd($period)),
                null,
                $storeId
            ),
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getByConsumer(Period $period, ?int $storeId = null): array
    {
        return $this->mergedBreakdown($period, UsageRecordRepositoryInterface::GROUP_BY_CONSUMER, $storeId);
    }

    /**
     * @inheritdoc
     */
    public function getByService(Period $period, ?int $storeId = null): array
    {
        return $this->mergedBreakdown($period, UsageRecordRepositoryInterface::GROUP_BY_SERVICE, $storeId);
    }

    /**
     * @inheritdoc
     */
    public function getTimeSeries(
        Period $period,
        Granularity $granularity,
        ?int $storeId = null
    ): array {
        $dailyBuckets = $this->dailyUsageRepository->seriesRange(
            $this->localBound($period->getStart()),
            $this->localBound($this->dailyWindowEnd($period)),
            $granularity->toDailyRepositoryGranularity(),
            $storeId
        );

        $rowsByPeriodLabel = $this->groupRowsByKey(
            'period',
            [...$dailyBuckets, ...$this->rawSeriesBuckets($period, $granularity, $storeId)]
        );

        return array_map(
            fn (string $label): UsageBreakdownInterface => new UsageBreakdown(
                $label,
                $this->rowsToTotals($rowsByPeriodLabel[$label] ?? [])
            ),
            $this->periodLabels($period, $granularity)
        );
    }

    /**
     * Shared implementation of {@see getByConsumer()} and {@see getByService()}: query both
     * repositories' `groupRange()`, merge the rows landing on the same group value, and sort the
     * result by total tokens descending.
     *
     * @param Period $period
     * @param string $groupBy {@see UsageRecordRepositoryInterface::GROUP_BY_CONSUMER} or
     *        {@see UsageRecordRepositoryInterface::GROUP_BY_SERVICE}.
     * @param int|null $storeId Narrow to one store, or `null` for every store
     * @return UsageBreakdownInterface[]
     */
    private function mergedBreakdown(Period $period, string $groupBy, ?int $storeId): array
    {
        $rowsByGroupValue = $this->groupRowsByKey(
            $groupBy,
            [
                ...$this->rawUsageRepository->groupRange($period->getStart(), $period->getEnd(), $groupBy, $storeId),
                ...$this->dailyUsageRepository->groupRange(
                    $this->localBound($period->getStart()),
                    $this->localBound($this->dailyWindowEnd($period)),
                    $groupBy,
                    $storeId
                ),
            ]
        );

        $breakdowns = array_map(
            fn (string $groupValue, array $rows): UsageBreakdownInterface => new UsageBreakdown(
                $groupValue,
                $this->rowsToTotals($rows)
            ),
            array_keys($rowsByGroupValue),
            array_values($rowsByGroupValue)
        );

        usort($breakdowns, $this->byTotalTokensDescending(...));

        return $breakdowns;
    }

    /**
     * Builds the raw side of a time series through
     * {@see UsageRecordRepositoryInterface::seriesRange()} (task 021), bounded to the (small, by
     * design: the raw retention window, 30 days by default) portion of the period the raw table
     * actually covers.
     *
     * That bounding happens here rather than inside the repository: the repository issues one
     * query per bucket, which is only acceptable because this method never asks it to cover more
     * than the raw table's own retention window, never a whole reporting period.
     *
     * @param Period $period
     * @param Granularity $granularity
     * @param int|null $storeId Narrow to one store, or `null` for every store
     * @return array<int,array<string,int|string|null>>
     */
    private function rawSeriesBuckets(Period $period, Granularity $granularity, ?int $storeId): array
    {
        $oldestRaw = $this->rawUsageRepository->getOldestRecordedAt();
        if ($oldestRaw === null) {
            return [];
        }

        $rawWindowStart = $oldestRaw > $period->getStart() ? $oldestRaw : $period->getStart();
        if ($rawWindowStart >= $period->getEnd()) {
            return [];
        }

        return $this->rawUsageRepository->seriesRange(
            $rawWindowStart,
            $period->getEnd(),
            $granularity->toDailyRepositoryGranularity(),
            $storeId
        );
    }

    /**
     * Every calendar-bucket boundary between $from and $to, at $granularity.
     *
     * @param \DateTimeImmutable $from
     * @param \DateTimeImmutable $to
     * @param Granularity $granularity
     * @return array<int,array{start:\DateTimeImmutable,end:\DateTimeImmutable,label:string}>
     */
    private function bucketBoundaries(\DateTimeImmutable $from, \DateTimeImmutable $to, Granularity $granularity): array
    {
        $step = $granularity->toStepInterval();
        $labelFormat = $granularity->toPeriodLabelFormat();
        $cursor = $granularity === Granularity::Month
            ? $from->modify('first day of this month')->setTime(0, 0)
            : $from->setTime(0, 0);

        $buckets = [];
        while ($cursor < $to) {
            $bucketEnd = $cursor->add($step);
            $bucketEnd = $bucketEnd < $to ? $bucketEnd : $to;
            $buckets[] = ['start' => $cursor, 'end' => $bucketEnd, 'label' => $cursor->format($labelFormat)];
            $cursor = $bucketEnd;
        }

        return $buckets;
    }

    /**
     * Every bucket label a period touches at a granularity, in order, enumerated in the store's
     * own timezone — what
     * {@see getTimeSeries()} maps over so a bucket with no data still appears, zeroed, rather than
     * being skipped.
     *
     * Local, because both repositories bucket locally: the daily table groups its already-local
     * `usage_date`, and the raw resource model resolves each local day's bounds before querying.
     * Walking UTC instants here would name a bucket neither of them can fill — in Europe/Amsterdam
     * a year-to-date window starts at 23:00 on 31 December, so the first label would be the
     * previous December.
     *
     * @param Period $period
     * @param Granularity $granularity
     * @return string[]
     */
    private function periodLabels(Period $period, Granularity $granularity): array
    {
        return array_map(
            static fn (array $bucket): string => $bucket['label'],
            $this->bucketBoundaries(
                $this->localBound($period->getStart()),
                $this->localBound($period->getEnd()),
                $granularity
            )
        );
    }

    /**
     * A window bound restated in the store's own timezone.
     *
     * `mageos_ai_usage_daily.usage_date` holds the *store-local* calendar date the roll-up
     * computed, while a {@see Period} bound is a UTC instant. The daily repository turns its
     * arguments into a date with `format('Y-m-d')`, so handing it a UTC instant asks for the wrong
     * day wherever the store is not on UTC: in Europe/Amsterdam the local first of January is
     * 23:00 UTC on the thirty-first of December, and a year-to-date window would open a day early
     * and pull in the previous December's bucket. Converting first makes the formatted date the
     * one the column actually stores. The raw table is unaffected — it stores a real timestamp and
     * is queried on instants.
     *
     * @param \DateTimeImmutable $instant
     * @return \DateTimeImmutable
     */
    private function localBound(\DateTimeImmutable $instant): \DateTimeImmutable
    {
        return $instant->setTimezone(new \DateTimeZone($this->timezone->getConfigTimezone()));
    }

    /**
     * The exclusive end of the daily table's half of a window.
     *
     * Never later than the oldest row still in the raw table: that row's whole calendar day is
     * answered from the raw side exclusively (see this class's own docblock), so the daily side
     * must stop strictly before it to avoid counting it twice. Truncating that bound down to its
     * calendar date, which is what the daily repository does with it, is exactly right there.
     *
     * When the period's own end is the binding constraint it is rounded *up* to the next local
     * midnight instead, because the same truncation would silently drop a whole day. A period
     * ending mid-day covers part of that day, the daily table stores whole days and the raw table
     * no longer holds the rows to be more precise, so the choice is between counting the whole day
     * or none of it. {@see \MageOS\AiBase\Block\Adminhtml\Usage\Dashboard::previousPeriod()}
     * is the caller that builds such a period, and its comparison is far less wrong carrying a
     * partial day's surplus than missing a whole day's total.
     *
     * @param Period $period
     * @return \DateTimeImmutable
     */
    private function dailyWindowEnd(Period $period): \DateTimeImmutable
    {
        $oldestRaw = $this->rawUsageRepository->getOldestRecordedAt();
        if ($oldestRaw !== null && $oldestRaw < $period->getEnd()) {
            return $oldestRaw;
        }

        return $this->wholeLocalDaysEnd($period->getEnd());
    }

    /**
     * $end rounded up to the next local midnight unless it already is one.
     *
     * @param \DateTimeImmutable $end
     * @return \DateTimeImmutable
     */
    private function wholeLocalDaysEnd(\DateTimeImmutable $end): \DateTimeImmutable
    {
        $local = $this->localBound($end);
        $midnight = $local->setTime(0, 0);

        return $local->getTimestamp() === $midnight->getTimestamp() ? $local : $midnight->modify('+1 day');
    }

    /**
     * Groups a list of repository rows by the value under $key, preserving each row's full shape.
     *
     * @param string $key
     * @param array<int,array<string,int|string|null>> $rows
     * @return array<string,array<int,array<string,int|string|null>>>
     */
    private function groupRowsByKey(string $key, array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(string) $row[$key]][] = $row;
        }

        return $grouped;
    }

    /**
     * Sums a list of repository rows into one {@see UsageTotalsInterface}.
     *
     * An empty list is a period with no recorded usage at all, which is why {@see zeroRow()} is
     * always the fold's starting point: the result is a zeroed {@see UsageTotalsInterface} rather
     * than one built from a missing array key.
     *
     * @param array<int,array<string,int|string|null>> $rows
     * @return UsageTotalsInterface
     */
    private function rowsToTotals(array $rows): UsageTotalsInterface
    {
        $summed = array_reduce($rows, $this->addRowPair(...), $this->zeroRow());

        return new UsageTotals(
            calls: (int) $summed['calls'],
            inputTokens: (int) $summed['input_tokens'],
            outputTokens: (int) $summed['output_tokens'],
            totalTokens: (int) $summed['total_tokens'],
            cachedTokens: $this->toNullableInt($summed['cached_tokens']),
            reasoningTokens: $this->toNullableInt($summed['reasoning_tokens']),
        );
    }

    /**
     * Adds two repository rows' token counts together.
     *
     * Keeps the nullable columns null when neither side ever reported them.
     *
     * @param array<string,int|string|null> $left
     * @param array<string,int|string|null> $right
     * @return array<string,int|string|null>
     */
    private function addRowPair(array $left, array $right): array
    {
        return [
            'calls' => (int) $left['calls'] + (int) $right['calls'],
            'input_tokens' => (int) $left['input_tokens'] + (int) $right['input_tokens'],
            'output_tokens' => (int) $left['output_tokens'] + (int) $right['output_tokens'],
            'total_tokens' => (int) $left['total_tokens'] + (int) $right['total_tokens'],
            'cached_tokens' => $this->addNullable(
                $this->toNullableInt($left['cached_tokens']),
                $this->toNullableInt($right['cached_tokens'])
            ),
            'reasoning_tokens' => $this->addNullable(
                $this->toNullableInt($left['reasoning_tokens']),
                $this->toNullableInt($right['reasoning_tokens'])
            ),
        ];
    }

    /**
     * Casts a repository row's column to an int, keeping a null column null.
     *
     * @param int|string|null $value
     * @return int|null
     */
    private function toNullableInt(int|string|null $value): ?int
    {
        return $value !== null ? (int) $value : null;
    }

    /**
     * Adds two nullable counts, staying null only when both sides are: the same
     * "not reported" versus "reported as zero" distinction {@see UsageTotalsInterface::getCachedTokens()}
     * documents.
     *
     * @param int|null $left
     * @param int|null $right
     * @return int|null
     */
    private function addNullable(?int $left, ?int $right): ?int
    {
        if ($left === null && $right === null) {
            return null;
        }

        return ($left ?? 0) + ($right ?? 0);
    }

    /**
     * A zeroed repository row, the identity element {@see rowsToTotals()} folds every sum onto.
     *
     * @return array<string,int|string|null>
     */
    private function zeroRow(): array
    {
        return [
            'calls' => 0,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'cached_tokens' => null,
            'reasoning_tokens' => null,
        ];
    }

    /**
     * `usort()` comparator ordering breakdown rows by total tokens, highest first.
     *
     * @param UsageBreakdownInterface $left
     * @param UsageBreakdownInterface $right
     * @return int
     */
    private function byTotalTokensDescending(UsageBreakdownInterface $left, UsageBreakdownInterface $right): int
    {
        return $right->getTotals()->getTotalTokens() <=> $left->getTotals()->getTotalTokens();
    }

    /**
     * @inheritdoc
     */
    public function getTimeSeriesByConsumer(
        Period $period,
        Granularity $granularity,
        int $limit,
        ?int $storeId = null
    ): array {
        return $this->groupedTimeSeries(
            $period,
            $granularity,
            UsageRecordRepositoryInterface::GROUP_BY_CONSUMER,
            $limit,
            $storeId
        );
    }

    /**
     * @inheritdoc
     */
    public function getTimeSeriesByService(
        Period $period,
        Granularity $granularity,
        int $limit,
        ?int $storeId = null
    ): array {
        return $this->groupedTimeSeries(
            $period,
            $granularity,
            UsageRecordRepositoryInterface::GROUP_BY_SERVICE,
            $limit,
            $storeId
        );
    }

    /**
     * One dense series per group value, over the same raw/daily union the totals use.
     *
     * @param Period $period
     * @param Granularity $granularity
     * @param string $groupBy
     * @param int $limit
     * @param int|null $storeId Narrow to one store, or `null` for every store
     * @return array<string,UsageBreakdownInterface[]>
     */
    private function groupedTimeSeries(
        Period $period,
        Granularity $granularity,
        string $groupBy,
        int $limit,
        ?int $storeId
    ): array {
        $labels = $this->periodLabels($period, $granularity);
        $rows = [
            ...$this->dailyUsageRepository->seriesRangeGrouped(
                $this->localBound($period->getStart()),
                $this->localBound($this->dailyWindowEnd($period)),
                $granularity->toDailyRepositoryGranularity(),
                $groupBy,
                $storeId
            ),
            ...$this->rawSeriesGroupedRows($period, $granularity, $groupBy, $storeId),
        ];

        $ranked = $this->rankedGroupValues($period, $groupBy, $limit, $storeId);
        $byGroupAndLabel = [];
        foreach ($rows as $row) {
            $groupValue = $this->toRowString($row, $groupBy);
            $series = in_array($groupValue, $ranked, true) ? $groupValue : UsageStatsInterface::SERIES_OTHER;
            $byGroupAndLabel[$series][$this->toRowString($row, 'period')][] = $row;
        }

        $seriesKeys = $ranked;
        if (isset($byGroupAndLabel[UsageStatsInterface::SERIES_OTHER])) {
            $seriesKeys[] = UsageStatsInterface::SERIES_OTHER;
        }

        $series = [];
        foreach ($seriesKeys as $key) {
            $series[$key] = array_map(
                fn (string $label): UsageBreakdownInterface => new UsageBreakdown(
                    $label,
                    $this->rowsToTotals($byGroupAndLabel[$key][$label] ?? [])
                ),
                $labels
            );
        }

        return $series;
    }

    /**
     * The busiest group values over the period, most tokens first, capped at `$limit`.
     *
     * Ranked over the whole period rather than per bucket, so a series keeps its identity for the
     * length of the chart: ranking per bucket would let a consumer drop in and out of "Other" from
     * one day to the next, and a line that changes what it means partway along is worse than no
     * line.
     *
     * @param Period $period
     * @param string $groupBy
     * @param int $limit
     * @param int|null $storeId Narrow to one store, or `null` for every store
     * @return string[]
     */
    private function rankedGroupValues(Period $period, string $groupBy, int $limit, ?int $storeId): array
    {
        if ($limit < 1) {
            return [];
        }

        $breakdown = $groupBy === UsageRecordRepositoryInterface::GROUP_BY_SERVICE
            ? $this->getByService($period, $storeId)
            : $this->getByConsumer($period, $storeId);

        return array_map(
            static fn (UsageBreakdownInterface $entry): string => $entry->getGroupValue(),
            array_slice($breakdown, 0, $limit)
        );
    }

    /**
     * The raw table's half of a grouped series.
     *
     * Over the same window {@see rawSeriesBuckets()} uses, so the two halves never overlap.
     *
     * @param Period $period
     * @param Granularity $granularity
     * @param string $groupBy
     * @param int|null $storeId Narrow to one store, or `null` for every store
     * @return array<int,array<string,int|string|null>>
     */
    private function rawSeriesGroupedRows(
        Period $period,
        Granularity $granularity,
        string $groupBy,
        ?int $storeId
    ): array {
        $oldestRaw = $this->rawUsageRepository->getOldestRecordedAt();
        if ($oldestRaw === null) {
            return [];
        }

        $rawWindowStart = $oldestRaw > $period->getStart() ? $oldestRaw : $period->getStart();
        if ($rawWindowStart >= $period->getEnd()) {
            return [];
        }

        return $this->rawUsageRepository->seriesRangeGrouped(
            $rawWindowStart,
            $period->getEnd(),
            $granularity->toDailyRepositoryGranularity(),
            $groupBy,
            $storeId
        );
    }

    /**
     * A repository row's value under a key, as a string.
     *
     * @param array<string,int|string|null> $row
     * @param string $key
     * @return string
     */
    private function toRowString(array $row, string $key): string
    {
        return (string) ($row[$key] ?? '');
    }
}
