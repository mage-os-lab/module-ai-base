<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\ResourceModel\Usage;

/**
 * The hand-written statements {@see \MageOS\AiBase\Model\Usage\UsageRecordRepository} needs from
 * the `mageos_ai_usage_log` resource model, pulled out as their own contract so the repository can
 * depend on it instead of the concrete resource model.
 *
 * `Magento\Framework\DB\Adapter\AdapterInterface` has over a hundred methods and is not
 * realistically fakeable, which rules out unit-testing the repository against a fake adapter. This
 * interface is the seam that makes the repository unit-testable instead: its unit tests hand it a
 * small in-memory fake implementing this contract, and the SQL itself is proven separately by the
 * integration tests against {@see UsageLog}, the only class that implements it.
 *
 * Not under `Api/`: this is an internal collaboration between the repository and its resource
 * model, not a contract another module has any business depending on.
 */
interface UsageLogResourceInterface
{
    /**
     * Inserts one row and returns the id the database assigned it.
     *
     * $row carries every column {@see \MageOS\AiBase\Model\Usage\UsageRecordRepository::save()}
     * maps off a {@see \MageOS\AiBase\Api\Data\UsageRecordInterface} except `entity_id` and
     * `created_at`, both of which the database assigns.
     *
     * @param array<string,int|string|null> $row
     * @return int
     */
    public function insert(array $row): int;

    /**
     * Deletes at most $limit rows strictly older than $cutoff in one statement.
     *
     * The building block {@see \MageOS\AiBase\Model\Usage\UsageRecordRepository::deleteOlderThan()}
     * loops over rather than one unbounded `DELETE`: a store that ran tracking for months has a
     * table the prune has to chew through without holding a lock for minutes.
     *
     * @param \DateTimeInterface $cutoff
     * @param int $limit
     * @return int Number of rows this single statement deleted, always `<= $limit`.
     */
    public function deleteBatch(\DateTimeInterface $cutoff, int $limit): int;

    /**
     * Timestamp of the oldest recorded row, or null when the table is empty.
     *
     * @return \DateTimeImmutable|null
     */
    public function getOldestRecordedAt(): ?\DateTimeImmutable;

    /**
     * Aggregates every row in `[$from, $to)`, one row per (`service_id`, `service_code`, `model`,
     * `consumer`, `store_id`) grouping key.
     *
     * @param \DateTimeInterface $from
     * @param \DateTimeInterface $to
     * @param string $usageDate Copied verbatim onto every returned row's `usage_date` key.
     * @return array<int,array<string,int|string|null>>
     */
    public function aggregateRange(\DateTimeInterface $from, \DateTimeInterface $to, string $usageDate): array;

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
     * Every distinct value of the `consumer` column currently stored.
     *
     * @return string[]
     */
    public function getDistinctConsumers(): array;

    /**
     * Sums every token count in `[$from, $to)`, bucketed per store-timezone day or month.
     *
     * See {@see \MageOS\AiBase\Api\UsageRecordRepositoryInterface::seriesRange()} for the full
     * contract this mirrors, including why every bucket is returned rather than only the ones
     * with data.
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
