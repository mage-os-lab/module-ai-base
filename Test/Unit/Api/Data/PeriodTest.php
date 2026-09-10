<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Api\Data;

use MageOS\AiBase\Api\Data\Period;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MageOS\AiBase\Api\Data\Period
 *
 * {@see FakeTimezone} only implements {@see TimezoneInterface::getConfigTimezone()}, the one
 * method {@see Period} calls, matching the convention
 * {@see \MageOS\AiBase\Test\Unit\Model\Usage\UsageMaintenanceTest}'s own fake of the same interface
 * already established in this suite.
 */
final class PeriodTest extends TestCase
{
    public function test_it_resolves_period_boundaries_in_the_store_timezone(): void
    {
        $now = new \DateTimeImmutable('2026-03-15 02:30:00', new \DateTimeZone('UTC'));

        $period = Period::today(new FakeTimezone('Pacific/Kiritimati'), $now);

        self::assertSame('2026-03-14 10:00:00', $period->getStart()->format('Y-m-d H:i:s'));
        self::assertSame('2026-03-15 10:00:00', $period->getEnd()->format('Y-m-d H:i:s'));
    }

    public function test_today_spans_the_local_calendar_day_as_utc_instants(): void
    {
        $now = new \DateTimeImmutable('2026-06-10 23:30:00', new \DateTimeZone('UTC'));

        $period = Period::today(new FakeTimezone('UTC'), $now);

        self::assertSame('2026-06-10 00:00:00', $period->getStart()->format('Y-m-d H:i:s'));
        self::assertSame('2026-06-11 00:00:00', $period->getEnd()->format('Y-m-d H:i:s'));
    }

    public function test_this_month_spans_the_local_calendar_month_as_utc_instants(): void
    {
        $now = new \DateTimeImmutable('2026-06-10 12:00:00', new \DateTimeZone('UTC'));

        $period = Period::thisMonth(new FakeTimezone('UTC'), $now);

        self::assertSame('2026-06-01 00:00:00', $period->getStart()->format('Y-m-d H:i:s'));
        self::assertSame('2026-07-01 00:00:00', $period->getEnd()->format('Y-m-d H:i:s'));
    }

    public function test_this_year_spans_the_local_calendar_year_as_utc_instants(): void
    {
        $now = new \DateTimeImmutable('2026-06-10 12:00:00', new \DateTimeZone('UTC'));

        $period = Period::thisYear(new FakeTimezone('UTC'), $now);

        self::assertSame('2026-01-01 00:00:00', $period->getStart()->format('Y-m-d H:i:s'));
        self::assertSame('2027-01-01 00:00:00', $period->getEnd()->format('Y-m-d H:i:s'));
    }

    public function test_between_uses_the_given_instants_without_any_timezone_resolution(): void
    {
        $start = new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'));
        $end = new \DateTimeImmutable('2026-02-01 00:00:00', new \DateTimeZone('UTC'));

        $period = Period::between($start, $end);

        self::assertSame($start, $period->getStart());
        self::assertSame($end, $period->getEnd());
    }
}

/**
 * In-memory stand-in for {@see TimezoneInterface} that only implements
 * {@see TimezoneInterface::getConfigTimezone()}, the one method {@see Period} calls. Every other
 * method throws, so a test that accidentally depends on one fails loudly instead of silently
 * returning a meaningless default.
 */
final class FakeTimezone implements TimezoneInterface
{
    public function __construct(private readonly string $timezoneName)
    {
    }

    public function getConfigTimezone($scopeType = null, $scopeCode = null)
    {
        return $this->timezoneName;
    }

    public function getDefaultTimezonePath()
    {
        throw new \LogicException('Not needed by PeriodTest.');
    }

    public function getDefaultTimezone()
    {
        throw new \LogicException('Not needed by PeriodTest.');
    }

    public function getDateFormat($type = \IntlDateFormatter::SHORT)
    {
        throw new \LogicException('Not needed by PeriodTest.');
    }

    public function getDateFormatWithLongYear()
    {
        throw new \LogicException('Not needed by PeriodTest.');
    }

    public function getTimeFormat($type = null)
    {
        throw new \LogicException('Not needed by PeriodTest.');
    }

    public function getDateTimeFormat($type)
    {
        throw new \LogicException('Not needed by PeriodTest.');
    }

    public function date($date = null, $locale = null, $useTimezone = true, $includeTime = true)
    {
        throw new \LogicException('Not needed by PeriodTest.');
    }

    public function scopeDate($scope = null, $date = null, $includeTime = false)
    {
        throw new \LogicException('Not needed by PeriodTest.');
    }

    public function scopeTimeStamp($scope = null)
    {
        throw new \LogicException('Not needed by PeriodTest.');
    }

    public function formatDate($date = null, $format = \IntlDateFormatter::SHORT, $showTime = false)
    {
        throw new \LogicException('Not needed by PeriodTest.');
    }

    public function isScopeDateInInterval($scope, $dateFrom = null, $dateTo = null)
    {
        throw new \LogicException('Not needed by PeriodTest.');
    }

    public function formatDateTime(
        $date,
        $dateType = \IntlDateFormatter::SHORT,
        $timeType = \IntlDateFormatter::SHORT,
        $locale = null,
        $timezone = null,
        $pattern = null
    ) {
        throw new \LogicException('Not needed by PeriodTest.');
    }

    public function convertConfigTimeToUtc($date, $format = 'Y-m-d H:i:s')
    {
        throw new \LogicException('Not needed by PeriodTest.');
    }
}
