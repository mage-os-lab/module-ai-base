<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\ResourceModel\Usage;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;

/**
 * The connection accessor, the store filter and the row-shaping helpers both usage resource
 * models need, in one place instead of two copies.
 *
 * {@see UsageLog} and {@see UsageDaily} answer different questions over different columns, but
 * they hand back the same row shape — the six aggregate keys, ints where the caller was promised
 * an int and a real `null` where nothing reported a value — so everything about shaping a row was
 * identical between them, verbatim, until this trait. A trait rather than a shared base class
 * because both already extend `AbstractDb`, and rather than a collaborator because
 * {@see connection()} and {@see withStoreFilter()} need the resource model's own `$this`.
 *
 * `self::COLUMN_STORE_ID` is the using class's own constant: each resource model names its own
 * store column, and a constant referenced through `self::` inside a trait resolves against the
 * class that uses it.
 */
trait UsageQuerySupport
{
    /**
     * The connection {@see \Magento\Framework\Model\ResourceModel\AbstractResource::getConnection()}
     * declares as possibly `false` (no connection configured), narrowed to the object every method
     * above actually needs so none of them has to repeat this check.
     *
     * @return AdapterInterface
     */
    private function connection(): AdapterInterface
    {
        $connection = $this->getConnection();
        if (!$connection instanceof AdapterInterface) {
            throw new \RuntimeException('No database connection configured for ' . self::TABLE . '.');
        }

        return $connection;
    }
    /**
     * Narrows a window to one store, or leaves it across all of them.
     *
     * `null` is deliberately "every store" rather than "the admin store": a total that silently
     * dropped the storefronts would be wrong in a way nobody would see, whereas a merchant asking
     * for one store always says which.
     *
     * @param Select $select
     * @param int|null $storeId
     * @return Select
     */
    private function withStoreFilter(Select $select, ?int $storeId): Select
    {
        if ($storeId === null) {
            return $select;
        }

        return $select->where(self::COLUMN_STORE_ID . ' = ?', $storeId);
    }
    /**
     * Narrows one element of `fetchAll()`'s result (declared `mixed` by PHPStan's `array_map`
     * contravariance check, though the adapter only ever returns rows of one row each) to an
     * array, so {@see aggregateRange()} and {@see groupRange()} can index into it safely.
     *
     * @param mixed $row
     * @return array<array-key,mixed>
     */
    private function toRow(mixed $row): array
    {
        return is_array($row) ? $row : [];
    }
    /**
     * Casts a fetched totals row from the PDO strings/nulls it arrives as into the int/null shape
     * every totals method promises, defaulting an empty row (no matching data at all) to zeroed
     * counts rather than to null.
     *
     * @param array<array-key,mixed> $row
     * @return array<string,int|null>
     */
    private function castTotals(array $row): array
    {
        return [
            'calls' => $this->toInt($row['calls'] ?? 0),
            'input_tokens' => $this->toInt($row['input_tokens'] ?? 0),
            'output_tokens' => $this->toInt($row['output_tokens'] ?? 0),
            'total_tokens' => $this->toInt($row['total_tokens'] ?? 0),
            'cached_tokens' => $this->toNullableInt($row['cached_tokens'] ?? null),
            'reasoning_tokens' => $this->toNullableInt($row['reasoning_tokens'] ?? null),
        ];
    }
    /**
     * One fetched row of {@see groupRange()}.
     *
     * Carries its label column ($labelKey, the grouping column) plus {@see castTotals()}'s six
     * counts.
     *
     * @param array<array-key,mixed> $row
     * @param string $labelKey
     * @return array<string,int|string|null>
     */
    private function labeledTotals(array $row, string $labelKey): array
    {
        return array_merge(
            [$labelKey => $this->toStringValue($row[$labelKey] ?? null)],
            $this->castTotals($row)
        );
    }
    /**
     * Narrows a value fetched from the adapter (declared `mixed` by PDO) to an `int`, treating
     * anything non-numeric as `0` rather than raising, since a totals column is never anything
     * else once it reaches PHP.
     *
     * @param mixed $value
     * @return int
     */
    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
    /**
     * Same narrowing as {@see toInt()}, but preserves `null` for the two nullable token columns.
     *
     * Unlike {@see toInt()}, "not reported" stays `null` here rather than becoming a misleading
     * `0`.
     *
     * @param mixed $value
     * @return int|null
     */
    private function toNullableInt(mixed $value): ?int
    {
        return $value === null ? null : $this->toInt($value);
    }
    /**
     * Same narrowing as {@see toInt()}, but to a string, for every grouping-key and label column.
     *
     * @param mixed $value
     * @return string
     */
    private function toStringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
