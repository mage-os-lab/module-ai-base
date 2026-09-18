<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;

/**
 * Persistence contract for `mageos_ai_usage_daily`, the roll-up the cron writes from the raw
 * usage log before pruning it.
 *
 * This is the whole surface {@see \MageOS\AiBase\Model\Usage\UsageMaintenance} (task 011) and
 * {@see \MageOS\AiBase\Model\Usage\UsageStats} (task 013) build against, which is why it is
 * defined completely here rather than grown method by method as later tasks need something: a
 * later task adding a method here would mean this task's own contract was incomplete.
 *
 * Every SQL statement lives in {@see \MageOS\AiBase\Model\ResourceModel\Usage\UsageDaily}; this
 * class only assembles rows and delegates. That split is what keeps this repository unit-testable
 * against a small fake instead of against `Magento\Framework\DB\Adapter\AdapterInterface`, which
 * has over a hundred methods and is not realistically fakeable.
 */
interface UsageDailyRepositoryInterface
{
    /**
     * Value of {@see groupRange()}'s `$groupBy` argument that groups a window by consumer.
     */
    public const GROUP_BY_CONSUMER = 'consumer';

    /**
     * Value of {@see groupRange()}'s `$groupBy` argument that groups a window by
     * {@see \MageOS\AiBase\Api\Data\UsageRecordInterface::getServiceId()}.
     */
    public const GROUP_BY_SERVICE = 'service_id';

    /**
     * Value of {@see seriesRange()}'s `$granularity` argument that buckets a window per day.
     */
    public const GRANULARITY_DAY = 'day';

    /**
     * Value of {@see seriesRange()}'s `$granularity` argument that buckets a window per month.
     */
    public const GRANULARITY_MONTH = 'month';

    /**
     * Writes a batch of daily aggregate rows in one insert-or-update statement.
     *
     * One statement for the whole batch rather than one round trip per row.
     *
     * A row matching an existing one on (`usage_date`, `service_id`, `model`, `consumer`,
     * `store_id`) — the unique constraint task 003 declared — has its counts *replaced*, never
     * summed: the cron recomputes a whole day from the raw rows on every run, so summing would
     * double the totals the second time it rolls up the same day after a partial failure.
     *
     * Each row is an associative array carrying every non-identity column of
     * `mageos_ai_usage_daily`: `usage_date`, `service_id`, `service_code`, `model`, `consumer`,
     * `store_id`, `calls`, `failed_calls`, `input_tokens`, `output_tokens`, `total_tokens`,
     * `cache_read_tokens`, `cache_write_tokens`, `reasoning_tokens`. Plain arrays rather than a
     * value object because this is exactly the
     * shape {@see \MageOS\AiBase\Api\UsageRecordRepositoryInterface::aggregateRange()} (task 005)
     * produces, and round-tripping it through a DTO here would buy nothing.
     *
     * @param array<int,array<string,int|string|null>> $rows
     * @return void
     */
    public function saveAggregates(array $rows): void;

    /**
     * Lists daily roll-up rows matching a search criteria.
     *
     * For administrative listing and filtering, unlike the stats aggregation methods below, which
     * never hydrate rows.
     *
     * @param SearchCriteriaInterface $searchCriteria
     * @return SearchResultsInterface
     */
    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface;

    /**
     * Deletes daily rows strictly older than $cutoff's date and reports how many were removed.
     *
     * Unlike the raw table's equivalent (task 005), this table holds one row per grouping key per
     * day rather than one row per call, so a store with years of history still has a small table
     * and needs no batching to prune safely.
     *
     * @param \DateTimeInterface $cutoff
     * @return int Number of rows deleted
     */
    public function deleteOlderThan(\DateTimeInterface $cutoff): int;

    /**
     * Totals every token count across daily rows in the half-open window `[$from, $to)`.
     *
     * Optionally narrowed to one consumer. `$from` and `$to` are `DateTimeInterface` for symmetry
     * with the raw repository's window arguments, but only their date portion is read:
     * `usage_date` is already a local calendar date computed once at roll-up time (task 011), so
     * there is no timezone conversion left to do here and this method never calls `DATE()` or
     * `CONVERT_TZ()`.
     *
     * Returns an associative array with keys `calls`, `failed_calls`, `input_tokens`,
     * `output_tokens`, `total_tokens`, `cache_read_tokens`, `cache_write_tokens`,
     * `reasoning_tokens`. `calls`, `failed_calls` and the three plain token counts are always an
     * int, zero when nothing matched. `cache_read_tokens`, `cache_write_tokens` and
     * `reasoning_tokens` stay null when no matching row ever reported them, the same nullable
     * convention the raw table uses, rather than becoming a misleading zero.
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
     * Totals the same window as {@see sumRange()}, grouped by consumer or by service row.
     *
     * $groupBy is one of {@see GROUP_BY_CONSUMER} or {@see GROUP_BY_SERVICE}. Returns a list of
     * associative arrays, each carrying the group's own value under the $groupBy column name plus
     * the same token-count keys as {@see sumRange()}, ordered by `total_tokens` descending so a
     * caller can read off the biggest consumer or service row first without sorting again.
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
     * Totals the same window as {@see sumRange()}, bucketed per day or per month, for the series.
     *
     * $granularity is one of {@see GRANULARITY_DAY} or {@see GRANULARITY_MONTH}. Returns a list of
     * associative arrays ordered by `period` ascending, each carrying `period` (`Y-m-d` for a day
     * bucket, `Y-m` for a month bucket) plus the same token-count keys as {@see sumRange()}. Only
     * periods with at least one matching row are returned; filling the gaps between them with zero
     * is the caller's job (task 013), since this method never hydrates a period it has no data for.
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
