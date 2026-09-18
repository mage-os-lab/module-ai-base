<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Console\Command;

use MageOS\AiBase\Api\Data\Granularity;
use MageOS\AiBase\Api\Data\Period;
use MageOS\AiBase\Api\Data\UsageBreakdownInterface;
use MageOS\AiBase\Api\Data\UsageTotalsInterface;
use MageOS\AiBase\Api\UsageStatsInterface;
use MageOS\AiBase\Console\Command\UsageReport;
use MageOS\AiBase\Model\Usage\UsageBreakdown;
use MageOS\AiBase\Model\Usage\UsageConfig;
use MageOS\AiBase\Model\Usage\UsageTotals;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \MageOS\AiBase\Console\Command\UsageReport
 *
 * Exercises the command against {@see FakeUsageStats}, an in-memory stand-in for
 * {@see UsageStatsInterface}, and a real {@see UsageConfig} backed by {@see FakeScopeConfig}, per
 * this codebase's fakes-over-mocks convention. {@see FakeTimezone} pins the store timezone so the
 * named periods resolve deterministically regardless of where the suite runs, and the fixed `$now`
 * passed to the command plays the same role {@see Period}'s own named constructors give their
 * `$now` argument.
 */
final class UsageReportTest extends TestCase
{
    private const NOW = '2026-06-15 12:00:00';

    private FakeUsageStats $usageStats;
    private FakeScopeConfig $scopeConfig;

    protected function setUp(): void
    {
        $this->usageStats = new FakeUsageStats();
        $this->scopeConfig = new FakeScopeConfig();
        $this->scopeConfig->setFlag('mageos_ai/usage/enabled', true);
    }

    public function test_it_prints_totals_for_the_default_month_period(): void
    {
        $this->usageStats->setTotals(new UsageTotals(10, 100, 200, 300, null, null));

        $exitCode = $this->commandTester()->execute([]);

        self::assertSame(0, $exitCode);
        self::assertEquals(
            Period::thisMonth(new FakeTimezone('UTC'), new \DateTimeImmutable(self::NOW)),
            $this->usageStats->getLastRequestedPeriod()
        );
    }

    public function test_it_prints_totals_for_the_period_named_on_the_command_line(): void
    {
        $this->usageStats->setTotals(new UsageTotals(1, 1, 1, 1, null, null));

        $exitCode = $this->commandTester()->execute(['--period' => 'today']);

        self::assertSame(0, $exitCode);
        self::assertEquals(
            Period::today(new FakeTimezone('UTC'), new \DateTimeImmutable(self::NOW)),
            $this->usageStats->getLastRequestedPeriod()
        );
    }

    public function test_it_rejects_an_unknown_period_with_a_non_zero_exit_code(): void
    {
        $exitCode = $this->commandTester()->execute(['--period' => 'fortnight']);

        self::assertNotSame(0, $exitCode);
    }

    public function test_it_filters_the_report_to_a_single_consumer(): void
    {
        $this->usageStats->setTotals(new UsageTotals(6, 600, 0, 600, null, null));
        $this->usageStats->setByConsumer([
            new UsageBreakdown('widget-picker', new UsageTotals(5, 500, 0, 500, null, null)),
            new UsageBreakdown('docs-search', new UsageTotals(1, 100, 0, 100, null, null)),
        ]);

        $tester = $this->commandTester();
        $tester->execute(['--consumer' => 'docs-search']);
        $display = $tester->getDisplay();

        self::assertStringContainsString('docs-search', $display);
        self::assertStringNotContainsString('widget-picker', $display);
        self::assertStringContainsString('100', $display);
    }

    public function test_it_prints_a_consumer_breakdown_ordered_by_tokens_descending(): void
    {
        $this->usageStats->setTotals(new UsageTotals(6, 600, 0, 600, null, null));
        $this->usageStats->setByConsumer([
            new UsageBreakdown('widget-picker', new UsageTotals(5, 500, 0, 500, null, null)),
            new UsageBreakdown('docs-search', new UsageTotals(1, 100, 0, 100, null, null)),
        ]);

        $tester = $this->commandTester();
        $tester->execute([]);
        $display = $tester->getDisplay();

        self::assertStringContainsString('widget-picker', $display);
        self::assertStringContainsString('docs-search', $display);
        self::assertLessThan(
            strpos($display, 'docs-search'),
            strpos($display, 'widget-picker')
        );
    }

    public function test_it_prints_machine_readable_json_when_asked_for_json_format(): void
    {
        $this->usageStats->setTotals(new UsageTotals(6, 600, 0, 600, 50, null, 10));
        $this->usageStats->setByConsumer([
            new UsageBreakdown('widget-picker', new UsageTotals(6, 600, 0, 600, 50, null, 10)),
        ]);

        $tester = $this->commandTester();
        $tester->execute(['--format' => 'json']);
        $payload = json_decode($tester->getDisplay(), true);

        self::assertIsArray($payload);
        self::assertSame(600, $payload['totals']['total_tokens']);
        self::assertSame(50, $payload['totals']['cache_read_tokens']);
        self::assertSame(10, $payload['totals']['cache_write_tokens']);
        self::assertNull($payload['totals']['reasoning_tokens']);
        self::assertSame('widget-picker', $payload['by_consumer'][0]['consumer']);
        self::assertSame(600, $payload['by_consumer'][0]['total_tokens']);
    }

    public function test_it_prints_failed_calls_and_cache_split_in_the_cli_report(): void
    {
        $this->usageStats->setTotals(new UsageTotals(6, 600, 0, 600, 50, null, 10, 2));

        $tester = $this->commandTester();
        $tester->execute([]);
        $tableDisplay = $tester->getDisplay();

        self::assertStringContainsString('Cache read', $tableDisplay);
        self::assertStringContainsString('Cache write', $tableDisplay);
        self::assertStringContainsString('Failed', $tableDisplay);
        self::assertStringContainsString('2', $tableDisplay);

        $jsonTester = $this->commandTester();
        $jsonTester->execute(['--format' => 'json']);
        $payload = json_decode($jsonTester->getDisplay(), true);

        self::assertIsArray($payload);
        self::assertSame(50, $payload['totals']['cache_read_tokens']);
        self::assertSame(10, $payload['totals']['cache_write_tokens']);
        self::assertSame(2, $payload['totals']['failed_calls']);
    }

    public function test_it_reports_that_no_usage_was_recorded_and_exits_successfully(): void
    {
        $tester = $this->commandTester();
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('No usage recorded', $tester->getDisplay());
    }

    public function test_it_reports_that_tracking_is_disabled_and_exits_successfully(): void
    {
        $this->scopeConfig->setFlag('mageos_ai/usage/enabled', false);

        $tester = $this->commandTester();
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('disabled', $tester->getDisplay());
        self::assertNull($this->usageStats->getLastRequestedPeriod());
    }

    public function test_it_scopes_the_report_to_the_store_named_on_the_command_line(): void
    {
        $this->usageStats->setTotals(new UsageTotals(1, 1, 1, 1, null, null));

        $tester = $this->commandTester();
        $exitCode = $tester->execute(['--store' => '2']);

        self::assertSame(0, $exitCode);
        self::assertSame(2, $this->usageStats->getLastRequestedStoreId());
        self::assertStringContainsString('in store 2', $tester->getDisplay());
    }

    public function test_it_covers_every_store_when_no_store_was_named(): void
    {
        $this->usageStats->setTotals(new UsageTotals(1, 1, 1, 1, null, null));

        $tester = $this->commandTester();
        $tester->execute([]);

        self::assertNull($this->usageStats->getLastRequestedStoreId());
        self::assertStringNotContainsString('in store', $tester->getDisplay());
    }

    public function test_it_reports_the_scoped_store_in_the_json_payload(): void
    {
        $this->usageStats->setTotals(new UsageTotals(6, 600, 0, 600, null, null));

        $tester = $this->commandTester();
        $tester->execute(['--store' => '2', '--format' => 'json']);
        $payload = json_decode($tester->getDisplay(), true);

        self::assertIsArray($payload);
        self::assertSame(2, $payload['store']);
    }

    public function test_it_rejects_a_store_that_is_not_a_number(): void
    {
        $tester = $this->commandTester();
        $exitCode = $tester->execute(['--store' => 'default']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Unknown store "default"', $tester->getDisplay());
        self::assertNull($this->usageStats->getLastRequestedStoreId());
    }

    private function commandTester(): CommandTester
    {
        $command = new UsageReport(
            $this->usageStats,
            new UsageConfig($this->scopeConfig),
            new FakeTimezone('UTC'),
            new \DateTimeImmutable(self::NOW)
        );

        return new CommandTester($command);
    }
}

/**
 * In-memory stand-in for {@see UsageStatsInterface}: records the {@see Period} it was last asked
 * about so a test can assert which named period the command resolved, and returns canned totals
 * and consumer breakdown rows a test sets through {@see setTotals()} / {@see setByConsumer()}
 * rather than a `->method()->willReturn()` mock chain.
 */
final class FakeUsageStats implements UsageStatsInterface
{
    private UsageTotalsInterface $totals;

    /**
     * @var UsageBreakdownInterface[]
     */
    private array $byConsumer = [];

    private ?Period $lastRequestedPeriod = null;

    /**
     * The `$storeId` of the last read, so a test can prove `--store` reaches the read contract
     * rather than only changing what the command prints.
     */
    private ?int $lastRequestedStoreId = null;

    public function __construct()
    {
        $this->totals = new UsageTotals(0, 0, 0, 0, null, null);
    }

    public function setTotals(UsageTotalsInterface $totals): void
    {
        $this->totals = $totals;
    }

    /**
     * @param UsageBreakdownInterface[] $byConsumer
     */
    public function setByConsumer(array $byConsumer): void
    {
        $this->byConsumer = $byConsumer;
    }

    public function getLastRequestedPeriod(): ?Period
    {
        return $this->lastRequestedPeriod;
    }

    public function getLastRequestedStoreId(): ?int
    {
        return $this->lastRequestedStoreId;
    }

    public function getTotals(Period $period, ?int $storeId = null): UsageTotalsInterface
    {
        $this->lastRequestedPeriod = $period;
        $this->lastRequestedStoreId = $storeId;

        return $this->totals;
    }

    /**
     * @return UsageBreakdownInterface[]
     */
    public function getByConsumer(Period $period, ?int $storeId = null): array
    {
        $this->lastRequestedPeriod = $period;
        $this->lastRequestedStoreId = $storeId;

        return $this->byConsumer;
    }

    /**
     * @return UsageBreakdownInterface[]
     */
    public function getByService(Period $period, ?int $storeId = null): array
    {
        throw new \LogicException('Not needed by UsageReportTest.');
    }

    /**
     * @return UsageBreakdownInterface[]
     */
    public function getTimeSeries(Period $period, Granularity $granularity, ?int $storeId = null): array
    {
        throw new \LogicException('Not needed by UsageReportTest.');
    }
    /**
     * Not exercised by the CLI, which reports totals and breakdowns rather than a trend; present
     * so the fake satisfies the interface.
     *
     * @return array<string,\MageOS\AiBase\Api\Data\UsageBreakdownInterface[]>
     */
    public function getTimeSeriesByConsumer(
        Period $period,
        Granularity $granularity,
        int $limit,
        ?int $storeId = null
    ): array {
        return [];
    }

    /**
     * @return array<string,\MageOS\AiBase\Api\Data\UsageBreakdownInterface[]>
     */
    public function getTimeSeriesByService(
        Period $period,
        Granularity $granularity,
        int $limit,
        ?int $storeId = null
    ): array {
        return [];
    }
}

/**
 * In-memory stand-in for {@see ScopeConfigInterface}, holding plain values and flags a test sets
 * through {@see setValue()} / {@see setFlag()} rather than through a `->method()->willReturn()`
 * mock chain. Matches the fake of the same name already established in
 * {@see \MageOS\AiBase\Test\Unit\Model\Usage\UsageMaintenanceTest}.
 */
final class FakeScopeConfig implements ScopeConfigInterface
{
    /**
     * @var array<string,string>
     */
    private array $values = [];

    /**
     * @var array<string,bool>
     */
    private array $flags = [];

    public function setValue(string $path, string $value): void
    {
        $this->values[$path] = $value;
    }

    public function setFlag(string $path, bool $value): void
    {
        $this->flags[$path] = $value;
    }

    public function getValue($path, $scopeType = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $scopeCode = null)
    {
        return $this->values[$path] ?? null;
    }

    public function isSetFlag($path, $scopeType = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $scopeCode = null)
    {
        return $this->flags[$path] ?? false;
    }
}

/**
 * In-memory stand-in for {@see TimezoneInterface} that only implements
 * {@see getConfigTimezone()}, the one method {@see Period} calls. Every other method throws, so a
 * test that accidentally depends on one fails loudly instead of silently returning a meaningless
 * default. Matches the fake of the same name already established in
 * {@see \MageOS\AiBase\Test\Unit\Api\Data\PeriodTest}.
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
        throw new \LogicException('Not needed by UsageReportTest.');
    }

    public function getDefaultTimezone()
    {
        throw new \LogicException('Not needed by UsageReportTest.');
    }

    public function getDateFormat($type = \IntlDateFormatter::SHORT)
    {
        throw new \LogicException('Not needed by UsageReportTest.');
    }

    public function getDateFormatWithLongYear()
    {
        throw new \LogicException('Not needed by UsageReportTest.');
    }

    public function getTimeFormat($type = null)
    {
        throw new \LogicException('Not needed by UsageReportTest.');
    }

    public function getDateTimeFormat($type)
    {
        throw new \LogicException('Not needed by UsageReportTest.');
    }

    public function date($date = null, $locale = null, $useTimezone = true, $includeTime = true)
    {
        throw new \LogicException('Not needed by UsageReportTest.');
    }

    public function scopeDate($scope = null, $date = null, $includeTime = false)
    {
        throw new \LogicException('Not needed by UsageReportTest.');
    }

    public function scopeTimeStamp($scope = null)
    {
        throw new \LogicException('Not needed by UsageReportTest.');
    }

    public function formatDate($date = null, $format = \IntlDateFormatter::SHORT, $showTime = false)
    {
        throw new \LogicException('Not needed by UsageReportTest.');
    }

    public function isScopeDateInInterval($scope, $dateFrom = null, $dateTo = null)
    {
        throw new \LogicException('Not needed by UsageReportTest.');
    }

    public function formatDateTime(
        $date,
        $dateType = \IntlDateFormatter::SHORT,
        $timeType = \IntlDateFormatter::SHORT,
        $locale = null,
        $timezone = null,
        $pattern = null
    ) {
        throw new \LogicException('Not needed by UsageReportTest.');
    }

    public function convertConfigTimeToUtc($date, $format = 'Y-m-d H:i:s')
    {
        throw new \LogicException('Not needed by UsageReportTest.');
    }
}
