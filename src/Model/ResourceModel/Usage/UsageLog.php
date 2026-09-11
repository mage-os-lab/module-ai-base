<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\ResourceModel\Usage;

use MageOS\AiBase\Api\UsageDailyRepositoryInterface;
use MageOS\AiBase\Api\UsageRecordRepositoryInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Context;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * SQL for `mageos_ai_usage_log`. Every statement the repository needs lives here; nothing else in
 * the module should name this class or its adapter directly.
 *
 * `created_at` is a `TIMESTAMP` column carrying a genuine time-of-day, unlike the daily roll-up's
 * plain `DATE` column. Every range argument here is therefore an absolute UTC instant, and this
 * class never calls `DATE()` or `CONVERT_TZ()`: day-boundary conversion is the caller's problem,
 * exactly as task 003's schema comments require, because the MySQL timezone tables are not
 * guaranteed to be loaded on a customer install.
 *
 * {@see seriesRange()} is the one method here that resolves calendar boundaries at all, and it
 * does so the same way {@see \MageOS\AiBase\Model\Usage\UsageMaintenance} (task 011) resolves its
 * own day windows: through {@see TimezoneInterface} and plain `\DateTimeImmutable` arithmetic in
 * PHP, one half-open UTC window per bucket, never in SQL.
 */
class UsageLog extends AbstractDb implements UsageLogResourceInterface
{
    use UsageQuerySupport;

    /**
     * Timezone every bucket boundary {@see seriesRange()} computes is resolved against before
     * being converted to the UTC instants `created_at` is compared with.
     */
    private const UTC_TIMEZONE = 'UTC';

    /**
     * Allowed values of {@see seriesRange()}'s `$granularity` argument.
     *
     * Reuses {@see UsageDailyRepositoryInterface}'s constants rather than declaring parallel ones,
     * per {@see \MageOS\AiBase\Api\UsageRecordRepositoryInterface::seriesRange()}'s own docblock.
     *
     * @var string[]
     */
    private const ALLOWED_GRANULARITIES = [
        UsageDailyRepositoryInterface::GRANULARITY_DAY,
        UsageDailyRepositoryInterface::GRANULARITY_MONTH,
    ];

    /**
     * @param Context $context
     * @param TimezoneInterface $timezone Source of the store's configured timezone that
     *        {@see seriesRange()} resolves every bucket boundary against.
     * @param string|null $connectionName
     */
    public function __construct(
        Context $context,
        private readonly TimezoneInterface $timezone,
        $connectionName = null
    ) {
        parent::__construct($context, $connectionName);
    }
    /**
     * Physical table name, kept as a constant so every method below names it the same way rather
     * than repeating the literal.
     */
    private const TABLE = 'mageos_ai_usage_log';

    /**
     * Primary key column.
     */
    private const COLUMN_ENTITY_ID = 'entity_id';

    /**
     * Timestamp column the database assigns on insert.
     */
    private const COLUMN_CREATED_AT = 'created_at';

    /**
     * Store the call was made in. Never null in the table (see `db_schema.xml`), so filtering on
     * it is a plain equality and 0 is the admin store rather than "unknown".
     */
    private const COLUMN_STORE_ID = 'store_id';

    /**
     * Grouping-key column also usable as a
     * {@see UsageRecordRepositoryInterface::GROUP_BY_CONSUMER} value.
     */
    private const COLUMN_CONSUMER = 'consumer';

    /**
     * The five columns {@see aggregateRange()} groups a window by, in the order every returned
     * row carries them.
     *
     * @var string[]
     */
    private const AGGREGATE_GROUP_COLUMNS = ['service_id', 'service_code', 'model', 'consumer', 'store_id'];

    /**
     * Allowed values of {@see groupRange()}'s `$groupBy` argument.
     *
     * `$groupBy` becomes a raw column name in a `GROUP BY`/`SELECT` clause, so it is validated
     * against this fixed allowlist rather than trusted as-is, the same reasoning that keeps any
     * caller-supplied identifier out of a query unescaped.
     *
     * @var string[]
     */
    private const ALLOWED_GROUP_BY_COLUMNS = [
        UsageRecordRepositoryInterface::GROUP_BY_CONSUMER,
        UsageRecordRepositoryInterface::GROUP_BY_SERVICE,
    ];

    /**
     * @inheritdoc
     */
    protected function _construct(): void
    {
        $this->_init(self::TABLE, self::COLUMN_ENTITY_ID);
    }

    /**
     * @inheritdoc
     */
    public function insert(array $row): int
    {
        $this->connection()->insert($this->getMainTable(), $row);

        // AdapterInterface does not declare lastInsertId(), but every adapter Magento ships
        // provides it (inherited from Zend_Db_Adapter_Abstract); Magento's own
        // Model\ResourceModel\Db\AbstractDb::save() relies on the exact same call.
        // @phpstan-ignore method.notFound
        return $this->toInt($this->connection()->lastInsertId($this->getMainTable()));
    }

    /**
     * @inheritdoc
     */
    public function deleteBatch(\DateTimeInterface $cutoff, int $limit): int
    {
        $connection = $this->connection();

        $idsToDelete = $connection->fetchCol(
            $connection->select()
                ->from($this->getMainTable(), [self::COLUMN_ENTITY_ID])
                ->where(self::COLUMN_CREATED_AT . ' < ?', $cutoff->format('Y-m-d H:i:s'))
                ->limit($limit)
        );

        if ($idsToDelete === []) {
            return 0;
        }

        return (int) $connection->delete(
            $this->getMainTable(),
            [self::COLUMN_ENTITY_ID . ' IN (?)' => $idsToDelete]
        );
    }

    /**
     * @inheritdoc
     */
    public function getOldestRecordedAt(): ?\DateTimeImmutable
    {
        $oldest = $this->connection()->fetchOne(
            $this->connection()->select()->from(
                $this->getMainTable(),
                ['oldest' => new \Zend_Db_Expr('MIN(' . self::COLUMN_CREATED_AT . ')')]
            )
        );

        // fetchOne() is declared to always return string, but MIN() over an empty table still
        // returns one row with a genuinely null column, which is what this guard is for.
        // @phpstan-ignore function.alreadyNarrowedType
        return is_string($oldest) ? new \DateTimeImmutable($oldest) : null;
    }

    /**
     * @inheritdoc
     */
    public function aggregateRange(\DateTimeInterface $from, \DateTimeInterface $to, string $usageDate): array
    {
        // Never store-filtered. This feeds the roll-up, and `mageos_ai_usage_daily` carries
        // `store_id` in its own grouping key — narrowing here would quietly drop every store but
        // one out of the aggregates and there would be nothing left to notice it by.
        $select = $this->windowSelect($from, $to)
            ->columns(array_merge(self::AGGREGATE_GROUP_COLUMNS, $this->countAndTotalColumns()))
            ->group(self::AGGREGATE_GROUP_COLUMNS);

        $rows = array_map(
            fn (mixed $rawRow): array => $this->aggregateRow($this->toRow($rawRow), $usageDate),
            $this->connection()->fetchAll($select)
        );

        return array_values($rows);
    }

    /**
     * @inheritdoc
     */
    public function sumRange(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        ?string $consumer = null,
        ?int $storeId = null
    ): array {
        $select = $this->windowSelect($from, $to, $storeId)->columns($this->countAndTotalColumns());

        if ($consumer !== null) {
            $select->where(self::COLUMN_CONSUMER . ' = ?', $consumer);
        }

        $row = $this->connection()->fetchRow($select);

        return $this->castTotals(is_array($row) ? $row : []);
    }

    /**
     * @inheritdoc
     */
    public function groupRange(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        string $groupBy,
        ?int $storeId = null
    ): array {
        $this->assertAllowedGroupByColumn($groupBy);

        $select = $this->windowSelect($from, $to, $storeId)
            ->columns(array_merge([$groupBy], $this->countAndTotalColumns()))
            ->group($groupBy)
            ->order('total_tokens DESC');

        $rows = array_map(
            fn (mixed $rawRow): array => $this->labeledTotals($this->toRow($rawRow), $groupBy),
            $this->connection()->fetchAll($select)
        );

        return array_values($rows);
    }

    /**
     * @inheritdoc
     */
    public function seriesRange(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        string $granularity,
        ?int $storeId = null
    ): array {
        $this->assertAllowedGranularity($granularity);

        return array_map(
            fn (array $bucket): array => $this->bucketRow(
                $bucket['start'],
                $bucket['end'],
                $bucket['label'],
                $storeId
            ),
            $this->localBucketBoundaries($from, $to, $granularity)
        );
    }

    /**
     * @inheritdoc
     */
    public function getDistinctConsumers(): array
    {
        $select = $this->connection()->select()
            ->from($this->getMainTable(), [self::COLUMN_CONSUMER])
            ->distinct(true)
            ->order(self::COLUMN_CONSUMER . ' ASC');

        return array_map(
            fn (mixed $value): string => $this->toStringValue($value),
            $this->connection()->fetchCol($select)
        );
    }

    /**
     * Base select over the half-open `[$from, $to)` window shared by every range query.
     *
     * Written once here so the boundary logic itself only exists in one place.
     *
     * @param \DateTimeInterface $from
     * @param \DateTimeInterface $to
     * @param int|null $storeId Narrow to one store, or `null` for every store
     * @return Select
     */
    private function windowSelect(\DateTimeInterface $from, \DateTimeInterface $to, ?int $storeId = null): Select
    {
        return $this->withStoreFilter($this->connection()->select()
            ->from($this->getMainTable(), [])
            ->where(self::COLUMN_CREATED_AT . ' >= ?', $from->format('Y-m-d H:i:s'))
            ->where(self::COLUMN_CREATED_AT . ' < ?', $to->format('Y-m-d H:i:s')), $storeId);
    }

    /**
     * The six aggregate columns every range query selects, aliased to plain column names.
     *
     * `calls` counts rows rather than summing a column: unlike the daily roll-up, one raw-log row
     * is always exactly one call. The four call/token columns coalesce a `NULL` sum (no matching
     * row) to `0`, matching the "always an int, zero when nothing matched" promise on
     * {@see UsageRecordRepositoryInterface::sumRange()}. `cached_tokens` and `reasoning_tokens`
     * are left to sum to a genuine `NULL` when nothing reported them, since MySQL's `SUM()`
     * already ignores `NULL` inputs and only returns `NULL` itself when every input was `NULL`.
     *
     * @return array<string,\Zend_Db_Expr>
     */
    private function countAndTotalColumns(): array
    {
        return [
            'calls' => new \Zend_Db_Expr('COUNT(*)'),
            'input_tokens' => new \Zend_Db_Expr('COALESCE(SUM(input_tokens), 0)'),
            'output_tokens' => new \Zend_Db_Expr('COALESCE(SUM(output_tokens), 0)'),
            'total_tokens' => new \Zend_Db_Expr('COALESCE(SUM(total_tokens), 0)'),
            'cached_tokens' => new \Zend_Db_Expr('SUM(cached_tokens)'),
            'reasoning_tokens' => new \Zend_Db_Expr('SUM(reasoning_tokens)'),
        ];
    }

    /**
     * One fetched row of {@see aggregateRange()}: its five grouping-key columns plus
     * {@see castTotals()}'s six counts, labelled with the caller-supplied $usageDate.
     *
     * @param array<array-key,mixed> $row
     * @param string $usageDate
     * @return array<string,int|string|null>
     */
    private function aggregateRow(array $row, string $usageDate): array
    {
        $labels = [
            'usage_date' => $usageDate,
            'service_id' => $this->toStringValue($row['service_id'] ?? null),
            'service_code' => $this->toStringValue($row['service_code'] ?? null),
            'model' => $this->toStringValue($row['model'] ?? null),
            'consumer' => $this->toStringValue($row['consumer'] ?? null),
            'store_id' => $this->toInt($row['store_id'] ?? 0),
        ];

        return array_merge($labels, $this->castTotals($row));
    }

    /**
     * Guards {@see groupRange()}'s caller-supplied `$groupBy` against {@see ALLOWED_GROUP_BY_COLUMNS}.
     *
     * $groupBy is about to become a raw SQL identifier, so it is validated rather than trusted.
     *
     * @param string $groupBy
     * @return void
     */
    private function assertAllowedGroupByColumn(string $groupBy): void
    {
        if (!in_array($groupBy, self::ALLOWED_GROUP_BY_COLUMNS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown grouping column "%s".', $groupBy));
        }
    }

    /**
     * Guards {@see seriesRange()}'s caller-supplied `$granularity` against
     * {@see ALLOWED_GRANULARITIES}.
     *
     * @param string $granularity
     * @return void
     */
    private function assertAllowedGranularity(string $granularity): void
    {
        if (!in_array($granularity, self::ALLOWED_GRANULARITIES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown granularity "%s".', $granularity));
        }
    }

    /**
     * Every store-timezone bucket boundary between $from and $to at $granularity.
     *
     * Each boundary is already converted to the UTC instants {@see bucketRow()} compares
     * `created_at` against. Walks whole local calendar days or months by
     * `\DateTimeImmutable::modify()` in the store's
     * real timezone rather than a fixed offset, which is what keeps a daylight-saving transition
     * day (23 or 25 hours long) exactly one bucket instead of being split or merged.
     *
     * @param \DateTimeInterface $from
     * @param \DateTimeInterface $to
     * @param string $granularity Already validated by {@see assertAllowedGranularity()}.
     * @return array<int,array{start:\DateTimeImmutable,end:\DateTimeImmutable,label:string}>
     */
    private function localBucketBoundaries(\DateTimeInterface $from, \DateTimeInterface $to, string $granularity): array
    {
        $localTimezone = $this->localTimezone();
        $utcTimezone = new \DateTimeZone(self::UTC_TIMEZONE);
        $isMonthly = $granularity === UsageDailyRepositoryInterface::GRANULARITY_MONTH;
        $labelFormat = $isMonthly ? 'Y-m' : 'Y-m-d';
        $stepModifier = $isMonthly ? '+1 month' : '+1 day';

        $localFrom = \DateTimeImmutable::createFromInterface($from)->setTimezone($localTimezone);
        $cursor = $isMonthly
            ? $localFrom->modify('first day of this month')->setTime(0, 0)
            : $localFrom->setTime(0, 0);
        $localTo = \DateTimeImmutable::createFromInterface($to)->setTimezone($localTimezone);

        $buckets = [];
        while ($cursor < $localTo) {
            $bucketEnd = $cursor->modify($stepModifier);
            $buckets[] = [
                'start' => $cursor->setTimezone($utcTimezone),
                'end' => $bucketEnd->setTimezone($utcTimezone),
                'label' => $cursor->format($labelFormat),
            ];
            $cursor = $bucketEnd;
        }

        return $buckets;
    }

    /**
     * @inheritdoc
     *
     * @return array<int,array<string,int|string|null>>
     */
    public function seriesRangeGrouped(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        string $granularity,
        string $groupBy,
        ?int $storeId = null
    ): array {
        $this->assertAllowedGranularity($granularity);
        $this->assertAllowedGroupByColumn($groupBy);

        $rows = array_map(
            fn (array $bucket): array => $this->groupedBucketRows(
                $bucket['start'],
                $bucket['end'],
                $bucket['label'],
                $groupBy,
                $storeId
            ),
            $this->localBucketBoundaries($from, $to, $granularity)
        );

        return array_merge(...array_values($rows));
    }

    /**
     * Every group present within one local bucket, as flat rows carrying the bucket's own label.
     *
     * One statement per bucket, the same shape {@see bucketRow()} uses and for the same reason: a
     * local calendar day has no column to group by on this table, so its bounds are resolved in
     * PHP and applied as an instant range. Grouping *within* that statement means the extra
     * dimension costs no extra queries.
     *
     * @param \DateTimeImmutable $start
     * @param \DateTimeImmutable $end
     * @param string $label
     * @param string $groupBy
     * @param int|null $storeId
     * @return array<int,array<string,int|string|null>>
     */
    private function groupedBucketRows(
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        string $label,
        string $groupBy,
        ?int $storeId
    ): array {
        $select = $this->windowSelect($start, $end, $storeId)
            ->columns(array_merge([$groupBy], $this->countAndTotalColumns()))
            ->group($groupBy);

        return array_values(array_map(
            fn (mixed $rawRow): array => array_merge(
                ['period' => $label],
                $this->labeledTotals($this->toRow($rawRow), $groupBy)
            ),
            $this->connection()->fetchAll($select)
        ));
    }

    /**
     * One {@see seriesRange()} bucket: its `period` label plus {@see countAndTotalColumns()}'s
     * six aggregate columns over `[$start, $end)`, queried exactly like {@see sumRange()} does for
     * a single window.
     *
     * Always returned, even when nothing matched: unlike {@see groupRange()}, which only ever sees
     * groups that exist, every bucket in the requested window gets its own row here, because it is
     * this method that decides which buckets exist in the first place.
     *
     * @param \DateTimeImmutable $start
     * @param \DateTimeImmutable $end
     * @param string $label
     * @param int|null $storeId
     * @return array<string,int|string|null>
     */
    private function bucketRow(
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        string $label,
        ?int $storeId
    ): array {
        $select = $this->windowSelect($start, $end, $storeId)->columns($this->countAndTotalColumns());
        $row = $this->connection()->fetchRow($select);

        return array_merge(['period' => $label], $this->castTotals(is_array($row) ? $row : []));
    }

    /**
     * The store's configured timezone {@see seriesRange()} resolves every bucket boundary against.
     *
     * @return \DateTimeZone
     */
    private function localTimezone(): \DateTimeZone
    {
        return new \DateTimeZone((string) $this->timezone->getConfigTimezone());
    }
}
