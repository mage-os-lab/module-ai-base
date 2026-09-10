<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\ResourceModel\Usage;

use MageOS\AiBase\Api\UsageDailyRepositoryInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * SQL for `mageos_ai_usage_daily`. Every statement the repository needs lives here; nothing else
 * in the module should name this class or its adapter directly.
 *
 * `usage_date` is a plain `DATE` column holding a local calendar date already computed once by the
 * roll-up (task 011) through `TimezoneInterface`. Because of that, comparing and grouping by it
 * here is not the timezone-conversion concern task 003's schema comments warn against — there is
 * no time-of-day component left to convert — so, unlike the raw log's `created_at`, this class is
 * allowed to `GROUP BY`/`DATE_FORMAT()` this particular column.
 */
class UsageDaily extends AbstractDb implements UsageDailyResourceInterface
{
    use UsageQuerySupport;

    /**
     * Physical table name, kept as a constant so every method below names it the same way rather
     * than repeating the literal.
     */
    private const TABLE = 'mageos_ai_usage_daily';

    /**
     * Primary key column.
     */
    private const COLUMN_ENTITY_ID = 'entity_id';

    /**
     * Local calendar date the roll-up computed the row for.
     */
    private const COLUMN_USAGE_DATE = 'usage_date';

    /**
     * Store the call was made in. Never null in the table (see `db_schema.xml`), so filtering on
     * it is a plain equality and 0 is the admin store rather than "unknown".
     */
    private const COLUMN_STORE_ID = 'store_id';

    /**
     * Grouping-key column also usable as a
     * {@see UsageDailyRepositoryInterface::GROUP_BY_CONSUMER} value.
     */
    private const COLUMN_CONSUMER = 'consumer';

    /**
     * Columns {@see upsertAggregates()} updates when a batch row collides with an existing one on
     * the unique grouping key.
     *
     * Deliberately excludes `entity_id` and every column in the unique constraint
     * (`usage_date`, `service_id`, `model`, `consumer`, `store_id`): those identify which row is
     * being replaced, so rewriting them on a match would be a no-op at best and a silent
     * cross-group corruption at worst. `service_code` is included even though it is derivable from
     * `service_id`, in case a service row's code ever changes between two roll-up runs of the same
     * day.
     *
     * @var string[]
     */
    private const UPDATE_ON_DUPLICATE_COLUMNS = [
        'service_code',
        'calls',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'cached_tokens',
        'reasoning_tokens',
    ];

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
        UsageDailyRepositoryInterface::GROUP_BY_CONSUMER,
        UsageDailyRepositoryInterface::GROUP_BY_SERVICE,
    ];

    /**
     * Allowed values of {@see seriesRange()}'s `$granularity` argument.
     *
     * @var string[]
     */
    private const ALLOWED_GRANULARITIES = [
        UsageDailyRepositoryInterface::GRANULARITY_DAY,
        UsageDailyRepositoryInterface::GRANULARITY_MONTH,
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
    public function upsertAggregates(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $this->connection()->insertOnDuplicate($this->getMainTable(), $rows, self::UPDATE_ON_DUPLICATE_COLUMNS);
    }

    /**
     * @inheritdoc
     */
    public function deleteOlderThan(\DateTimeInterface $cutoff): int
    {
        return (int) $this->connection()->delete(
            $this->getMainTable(),
            [self::COLUMN_USAGE_DATE . ' < ?' => $cutoff->format('Y-m-d')]
        );
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
        $select = $this->windowSelect($from, $to, $storeId)->columns($this->totalColumns());

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
        $this->assertAllowedValue($groupBy, self::ALLOWED_GROUP_BY_COLUMNS, 'grouping column');

        $select = $this->windowSelect($from, $to, $storeId)
            ->columns(array_merge([$groupBy], $this->totalColumns()))
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
        $this->assertAllowedValue($granularity, self::ALLOWED_GRANULARITIES, 'granularity');
        $periodExpression = $this->periodExpression($granularity);

        $select = $this->windowSelect($from, $to, $storeId)
            ->columns(array_merge(['period' => $periodExpression], $this->totalColumns()))
            ->group($periodExpression)
            ->order('period ASC');

        $rows = array_map(
            fn (mixed $rawRow): array => $this->labeledTotals($this->toRow($rawRow), 'period'),
            $this->connection()->fetchAll($select)
        );

        return array_values($rows);
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
        $this->assertAllowedValue($granularity, self::ALLOWED_GRANULARITIES, 'granularity');
        $this->assertAllowedValue($groupBy, self::ALLOWED_GROUP_BY_COLUMNS, 'grouping column');
        $periodExpression = $this->periodExpression($granularity);

        // One statement for the whole window: this table already carries the bucket as a column,
        // so the second dimension is another GROUP BY rather than another round trip per bucket.
        $select = $this->windowSelect($from, $to, $storeId)
            ->columns(array_merge(['period' => $periodExpression, $groupBy], $this->totalColumns()))
            ->group([$periodExpression, $groupBy])
            ->order('period ASC');

        return array_values(array_map(
            fn (mixed $rawRow): array => array_merge(
                ['period' => $this->toStringValue($this->toRow($rawRow)['period'] ?? null)],
                $this->labeledTotals($this->toRow($rawRow), $groupBy)
            ),
            $this->connection()->fetchAll($select)
        ));
    }

    /**
     * Base select over the half-open `[$from, $to)` window shared by every totals query.
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
            ->where(self::COLUMN_USAGE_DATE . ' >= ?', $from->format('Y-m-d'))
            ->where(self::COLUMN_USAGE_DATE . ' < ?', $to->format('Y-m-d')), $storeId);
    }

    /**
     * The six aggregate columns every totals query selects, aliased to the plain column names.
     *
     * The four call/token columns coalesce a `NULL` sum (no matching row) to `0`, matching the
     * "always an int, zero when nothing matched" promise on
     * {@see UsageDailyRepositoryInterface::sumRange()}. `cached_tokens` and `reasoning_tokens` are
     * left to sum to a genuine `NULL` when nothing reported them, since MySQL's `SUM()` already
     * ignores `NULL` inputs and only returns `NULL` itself when every input was `NULL` — exactly
     * the "stays null, never becomes a misleading zero" rule those two columns follow everywhere
     * else in this module.
     *
     * @return array<string,\Zend_Db_Expr>
     */
    private function totalColumns(): array
    {
        return [
            'calls' => new \Zend_Db_Expr('COALESCE(SUM(calls), 0)'),
            'input_tokens' => new \Zend_Db_Expr('COALESCE(SUM(input_tokens), 0)'),
            'output_tokens' => new \Zend_Db_Expr('COALESCE(SUM(output_tokens), 0)'),
            'total_tokens' => new \Zend_Db_Expr('COALESCE(SUM(total_tokens), 0)'),
            'cached_tokens' => new \Zend_Db_Expr('SUM(cached_tokens)'),
            'reasoning_tokens' => new \Zend_Db_Expr('SUM(reasoning_tokens)'),
        ];
    }

    /**
     * Resolves $granularity to the expression {@see seriesRange()} groups and selects by.
     *
     * A day bucket is the column itself: `usage_date` already is one row per day. A month bucket
     * reformats it with `DATE_FORMAT()`, which is safe here for the reason explained on the class
     * docblock: `usage_date` carries no time-of-day component to convert.
     *
     * @param string $granularity Already validated by {@see assertAllowedValue()}.
     * @return string|\Zend_Db_Expr
     */
    private function periodExpression(string $granularity): string|\Zend_Db_Expr
    {
        return $granularity === UsageDailyRepositoryInterface::GRANULARITY_MONTH
            ? new \Zend_Db_Expr(sprintf("DATE_FORMAT(%s, '%%Y-%%m')", self::COLUMN_USAGE_DATE))
            : self::COLUMN_USAGE_DATE;
    }

    /**
     * Guards a caller-supplied string that is about to become a raw SQL identifier or expression
     * choice against a fixed allowlist, shared by {@see groupRange()}'s `$groupBy` and
     * {@see seriesRange()}'s `$granularity`.
     *
     * @param string $value
     * @param string[] $allowed
     * @param string $label Names what $value was supposed to be, in the exception message.
     * @return void
     */
    private function assertAllowedValue(string $value, array $allowed, string $label): void
    {
        if (!in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown %s "%s".', $label, $value));
        }
    }
}
