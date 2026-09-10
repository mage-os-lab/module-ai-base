<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\ResourceModel\Usage;

/**
 * The five statements {@see \MageOS\AiBase\Model\Usage\UsageDailyRepository} needs from the
 * `mageos_ai_usage_daily` resource model, pulled out as their own contract so the repository can
 * depend on it instead of the concrete resource model.
 *
 * `Magento\Framework\DB\Adapter\AdapterInterface` has over a hundred methods and is not
 * realistically fakeable, which rules out unit-testing the repository against a fake adapter. This
 * interface is the seam that makes the repository unit-testable instead: its unit tests hand it a
 * small in-memory fake implementing this contract, and the SQL itself is proven separately by the
 * integration tests against {@see UsageDaily}, the only class that implements it.
 *
 * Not under `Api/`: this is an internal collaboration between the repository and its resource
 * model, not a contract another module has any business depending on.
 */
interface UsageDailyResourceInterface
{
    /**
     * Inserts or updates a batch of daily aggregate rows in one statement.
     *
     * @param array<int,array<string,int|string|null>> $rows
     * @return void
     */
    public function upsertAggregates(array $rows): void;

    /**
     * Deletes rows whose `usage_date` is strictly before $cutoff's date.
     *
     * @param \DateTimeInterface $cutoff
     * @return int Number of rows deleted
     */
    public function deleteOlderThan(\DateTimeInterface $cutoff): int;

    /**
     * Sums every token count across rows in `[$from, $to)`, optionally narrowed to one consumer.
     *
     * @param \DateTimeInterface $from
     * @param \DateTimeInterface $to
     * @param string|null $consumer
     * @param int|null $storeId Narrow to one store, or `null` for every store
     * @return array<string,int|null>
     */
    public function sumRange(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        ?string $consumer = null,
        ?int $storeId = null
    ): array;

    /**
     * Sums the same window as {@see sumRange()}, grouped by $groupBy.
     *
     * @param \DateTimeInterface $from
     * @param \DateTimeInterface $to
     * @param string $groupBy
     * @param int|null $storeId Narrow to one store, or `null` for every store
     * @return array<int,array<string,int|string|null>>
     */
    public function groupRange(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        string $groupBy,
        ?int $storeId = null
    ): array;

    /**
     * Sums the same window as {@see sumRange()}, bucketed per $granularity.
     *
     * @param \DateTimeInterface $from
     * @param \DateTimeInterface $to
     * @param string $granularity
     * @param int|null $storeId Narrow to one store, or `null` for every store
     * @return array<int,array<string,int|string|null>>
     */
    public function seriesRange(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        string $granularity,
        ?int $storeId = null
    ): array;

    /**
     * The same window as {@see seriesRange()}, split a second time by a grouping column.
     *
     * One row per (bucket, group) pair actually present — unlike {@see seriesRange()}, a bucket
     * with no rows contributes nothing here, and a caller wanting a dense series fills the gaps
     * itself from the bucket list it already knows.
     *
     * @param \DateTimeInterface $from Inclusive
     * @param \DateTimeInterface $to Exclusive
     * @param string $granularity One of the GRANULARITY_* constants
     * @param string $groupBy One of the GROUP_BY_* constants
     * @param int|null $storeId Narrow to one store, or `null` for every store
     * @return array<int,array<string,int|string|null>> Rows carrying `period`, the grouping column
     *         and the aggregate totals
     */
    public function seriesRangeGrouped(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        string $granularity,
        string $groupBy,
        ?int $storeId = null
    ): array;
}
