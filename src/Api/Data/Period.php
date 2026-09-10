<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api\Data;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * A half-open window `[start, end)` of absolute UTC instants that
 * {@see \MageOS\AiBase\Api\UsageStatsInterface} answers every question over.
 *
 * The named constructors below are what keep "today" or "this month" meaning what an
 * administrator actually typed into a calendar in their own timezone, not what UTC's calendar
 * happens to say at the moment the query runs: each resolves the local day/month/year boundary in
 * the store timezone {@see TimezoneInterface} reports, then converts that boundary to UTC once,
 * here, so every repository this module queries keeps comparing plain UTC timestamps and never
 * calls `DATE()` or `CONVERT_TZ()` (see task 003's schema comments for why that matters).
 *
 * Immutable and constructed only through the named constructors or {@see between()}: there is no
 * public constructor, so a caller can never build a `Period` whose end precedes its start by
 * forgetting which argument goes where.
 *
 * Magento discourages static methods because a plugin cannot intercept them. That is the point
 * here rather than a drawback: a value object's constructors are not an extension point, and a
 * plugin able to hand back a different window than the one an administrator asked for would be a
 * bug, not a customisation. Hence the sniff is switched off for this file alone.
 */
// phpcs:disable Magento2.Functions.StaticFunction
class Period
{
    /**
     * @param \DateTimeImmutable $start Inclusive, in UTC.
     * @param \DateTimeImmutable $end Exclusive, in UTC.
     */
    private function __construct(
        private readonly \DateTimeImmutable $start,
        private readonly \DateTimeImmutable $end,
    ) {
    }

    /**
     * The current local calendar day, start to end, in the store timezone.
     *
     * @param TimezoneInterface $timezone
     * @param \DateTimeImmutable|null $now Deterministic clock for tests. Production callers leave
     *        this null and get the real current instant; a test passes a fixed one so "today"
     *        means the same thing on every run regardless of when the suite happens to execute.
     * @return self
     */
    public static function today(TimezoneInterface $timezone, ?\DateTimeImmutable $now = null): self
    {
        $localStart = self::localNow($timezone, $now)->setTime(0, 0);

        return self::fromLocalWindow($localStart, $localStart->modify('+1 day'));
    }

    /**
     * The current local calendar month, start to end, in the store timezone.
     *
     * @param TimezoneInterface $timezone
     * @param \DateTimeImmutable|null $now {@see today()}
     * @return self
     */
    public static function thisMonth(TimezoneInterface $timezone, ?\DateTimeImmutable $now = null): self
    {
        $localStart = self::localNow($timezone, $now)
            ->modify('first day of this month')
            ->setTime(0, 0);

        return self::fromLocalWindow($localStart, $localStart->modify('+1 month'));
    }

    /**
     * The current local calendar year, start to end, in the store timezone.
     *
     * @param TimezoneInterface $timezone
     * @param \DateTimeImmutable|null $now {@see today()}
     * @return self
     */
    public static function thisYear(TimezoneInterface $timezone, ?\DateTimeImmutable $now = null): self
    {
        $localStart = self::localNow($timezone, $now)
            ->modify('first day of January this year')
            ->setTime(0, 0);

        return self::fromLocalWindow($localStart, $localStart->modify('+1 year'));
    }

    /**
     * An arbitrary window between two already-resolved UTC instants.
     *
     * No timezone resolution happens here: a caller building a custom window (a CLI flag, an
     * admin-picked date range) is expected to have already turned it into absolute instants the
     * same way the named constructors above do.
     *
     * @param \DateTimeImmutable $start Inclusive, in UTC.
     * @param \DateTimeImmutable $end Exclusive, in UTC.
     * @return self
     */
    public static function between(\DateTimeImmutable $start, \DateTimeImmutable $end): self
    {
        return new self($start, $end);
    }

    /**
     * The start of the window.
     *
     * @return \DateTimeImmutable Inclusive, in UTC.
     */
    public function getStart(): \DateTimeImmutable
    {
        return $this->start;
    }

    /**
     * The end of the window.
     *
     * @return \DateTimeImmutable Exclusive, in UTC.
     */
    public function getEnd(): \DateTimeImmutable
    {
        return $this->end;
    }

    /**
     * The current instant in the store timezone, real or the deterministic one a test supplied.
     *
     * @param TimezoneInterface $timezone
     * @param \DateTimeImmutable|null $now
     * @return \DateTimeImmutable
     */
    private static function localNow(TimezoneInterface $timezone, ?\DateTimeImmutable $now): \DateTimeImmutable
    {
        $storeTimezone = new \DateTimeZone($timezone->getConfigTimezone());

        return $now instanceof \DateTimeImmutable
            ? $now->setTimezone($storeTimezone)
            : new \DateTimeImmutable('now', $storeTimezone);
    }

    /**
     * Converts a local `[start, end)` window, still carrying the store timezone, to UTC instants.
     *
     * @param \DateTimeImmutable $localStart
     * @param \DateTimeImmutable $localEnd
     * @return self
     */
    private static function fromLocalWindow(\DateTimeImmutable $localStart, \DateTimeImmutable $localEnd): self
    {
        $utc = new \DateTimeZone('UTC');

        return new self($localStart->setTimezone($utc), $localEnd->setTimezone($utc));
    }
}
