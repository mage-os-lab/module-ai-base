<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Usage;

use MageOS\AiBase\Api\UsageDailyRepositoryInterface;
use MageOS\AiBase\Api\UsageRecordRepositoryInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * Housekeeping for the usage-tracking tables: rolls whole local days of
 * `mageos_ai_usage_log` older than the configured retention window up into
 * `mageos_ai_usage_daily`, deletes exactly the raw rows it rolled up, and then prunes daily rows
 * past their own (longer) retention window.
 *
 * The order is not negotiable: roll up first, delete second. A prune that ran before the
 * aggregate would be data loss; a delete wider than the aggregated window would lose whatever
 * landed in between. This class is the only place that sequence is expressed, which is what keeps
 * a cron or CLI caller from being able to get the order wrong.
 *
 * Day boundaries are computed here in PHP, in the store timezone, from
 * {@see TimezoneInterface::getConfigTimezone()} plus plain `\DateTimeImmutable`/`\DateTimeZone`
 * arithmetic, never with SQL's `DATE()` or `CONVERT_TZ()`: that is what keeps DST correct and
 * keeps the module working on an install whose MySQL has no timezone tables loaded (see task
 * 003's schema comments).
 */
class UsageMaintenance
{
    /**
     * Timezone every window and comparison below is computed against; nothing here reads UTC.
     */
    private const UTC_TIMEZONE = 'UTC';

    /**
     * @param UsageConfig $usageConfig Tells this class whether tracking is enabled at all and how
     *        many days of raw/daily history to keep.
     * @param UsageRecordRepositoryInterface $usageRecordRepository The raw log this class reads
     *        aggregates from and deletes rolled-up rows out of.
     * @param UsageDailyRepositoryInterface $usageDailyRepository The daily roll-up this class
     *        writes aggregates into and prunes once they are older than the daily retention.
     * @param TimezoneInterface $timezone Source of the store's configured timezone, so every day
     *        boundary below is a local calendar day rather than a UTC one.
     * @param UsageTransactionInterface $transaction Makes each day's aggregate-then-delete a
     *        single committed step; see {@see rollUpAndDeleteRawRows()} for what a half-finished
     *        one would cost.
     */
    public function __construct(
        private readonly UsageConfig $usageConfig,
        private readonly UsageRecordRepositoryInterface $usageRecordRepository,
        private readonly UsageDailyRepositoryInterface $usageDailyRepository,
        private readonly TimezoneInterface $timezone,
        private readonly UsageTransactionInterface $transaction,
    ) {
    }

    /**
     * Runs the whole roll-up/prune sequence and reports what it did.
     *
     * A no-op, reporting all zeros, when tracking is disabled: a store that switched tracking off
     * still has whatever history it collected while it was on, and this class must not touch it
     * without the administrator's toggle saying so.
     *
     * @return UsageMaintenanceResult
     */
    public function run(): UsageMaintenanceResult
    {
        // Deliberately not gated on the tracking toggle. Switching tracking off stops new rows
        // being recorded; it does not mean the rows already recorded should sit in the raw table
        // untouched forever. An install that tries the feature and turns it off would otherwise
        // keep whatever it had gathered at full detail indefinitely, never compacted into the
        // daily aggregates and never pruned — which is the opposite of what turning it off
        // implies. With nothing new arriving this is a cheap no-op once the backlog has drained.
        [$aggregatedRows, $deletedRows] = $this->rollUpAndDeleteRawRows();
        $prunedDailyRows = $this->pruneDailyRows();

        return new UsageMaintenanceResult($aggregatedRows, $deletedRows, $prunedDailyRows);
    }

    /**
     * Rolls up every whole local day older than the retention window, one day at a time, deleting
     * each day's raw rows immediately after its aggregate is safely written. Day-sized chunks
     * rather than one pass over the whole backlog is what keeps a store with months of history
     * from building one enormous aggregate result set in memory.
     *
     * Each day's aggregate and the delete behind it commit together. They have to: the aggregate
     * is written as a *replacement* for the day (`insertOnDuplicate` overwrites the row rather
     * than adding to it), so if the delete only got halfway, the next run would re-aggregate the
     * surviving rows alone and overwrite the complete total with a partial one. The tokens behind
     * the rows that were deleted would be gone from every dashboard, grid and report for good.
     *
     * @return array{0: int, 1: int} Rows aggregated, rows deleted.
     */
    private function rollUpAndDeleteRawRows(): array
    {
        $oldestRecordedAt = $this->usageRecordRepository->getOldestRecordedAt();
        if ($oldestRecordedAt === null) {
            return [0, 0];
        }

        $localTimezone = $this->localTimezone();
        $aggregatedRows = 0;
        $deletedRows = 0;

        foreach ($this->localDaysToRollUp($oldestRecordedAt, $localTimezone) as $localDay) {
            [$windowStart, $windowEnd] = $this->utcWindowForLocalDay($localDay, $localTimezone);
            $aggregateRows = $this->usageRecordRepository->aggregateRange(
                $windowStart,
                $windowEnd,
                $localDay->format('Y-m-d')
            );
            if ($aggregateRows === []) {
                continue;
            }

            $deletedRows += $this->transaction->run(function () use ($aggregateRows, $windowEnd): int {
                $this->usageDailyRepository->saveAggregates($aggregateRows);

                return $this->usageRecordRepository->deleteOlderThan($windowEnd);
            });
            $aggregatedRows += array_sum(array_map(
                fn (array $row): int => (int) $row['calls'],
                $aggregateRows
            ));
        }

        return [$aggregatedRows, $deletedRows];
    }

    /**
     * Deletes daily rows past the (longer) daily retention window.
     *
     * Independent of whatever the raw roll-up just did: a store can have a stale daily backlog
     * even on a run where nothing new was rolled up.
     *
     * @return int Daily rows pruned.
     */
    private function pruneDailyRows(): int
    {
        $cutoff = (new \DateTimeImmutable('now', $this->localTimezone()))
            ->modify(sprintf('-%d days', $this->usageConfig->getDailyRetentionDays()));

        return $this->usageDailyRepository->deleteOlderThan($cutoff);
    }

    /**
     * Every whole local day strictly older than the retention window, oldest first, starting from
     * the oldest raw row on record. Never includes today: the retention window is always at least
     * one day, so its boundary always falls before today, and re-rolling a partial day would be
     * silently corrected by the next run's upsert only if the raw rows behind it still existed,
     * which they will not once this class deletes them.
     *
     * @param \DateTimeImmutable $oldestRecordedAt
     * @param \DateTimeZone $localTimezone
     * @return array<int,\DateTimeImmutable>
     */
    private function localDaysToRollUp(
        \DateTimeImmutable $oldestRecordedAt,
        \DateTimeZone $localTimezone
    ): array {
        $cursor = new \DateTimeImmutable(
            $oldestRecordedAt->setTimezone($localTimezone)->format('Y-m-d'),
            $localTimezone
        );
        $boundary = new \DateTimeImmutable(
            (new \DateTimeImmutable('now', $localTimezone))
                ->modify(sprintf('-%d days', $this->usageConfig->getRetentionDays()))
                ->format('Y-m-d'),
            $localTimezone
        );

        $days = [];
        while ($cursor < $boundary) {
            $days[] = $cursor;
            $cursor = $cursor->modify('+1 day');
        }

        return $days;
    }

    /**
     * Converts one local calendar day into the half-open `[from, to)` UTC instant window
     * {@see UsageRecordRepositoryInterface::aggregateRange()} and
     * {@see UsageRecordRepositoryInterface::deleteOlderThan()} compare `created_at` against.
     *
     * @param \DateTimeImmutable $localDay Any instant on the local day; only its `Y-m-d` portion
     *        is read.
     * @param \DateTimeZone $localTimezone
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function utcWindowForLocalDay(
        \DateTimeImmutable $localDay,
        \DateTimeZone $localTimezone
    ): array {
        $localStart = new \DateTimeImmutable($localDay->format('Y-m-d') . ' 00:00:00', $localTimezone);
        $localEnd = $localStart->modify('+1 day');
        $utcTimezone = new \DateTimeZone(self::UTC_TIMEZONE);

        return [$localStart->setTimezone($utcTimezone), $localEnd->setTimezone($utcTimezone)];
    }

    /**
     * The store's configured timezone.
     *
     * Asked for once per run rather than once per day, so a mid-run DST transition in the config
     * itself cannot shift the boundaries this run already computed.
     *
     * @return \DateTimeZone
     */
    private function localTimezone(): \DateTimeZone
    {
        return new \DateTimeZone((string) $this->timezone->getConfigTimezone());
    }
}
