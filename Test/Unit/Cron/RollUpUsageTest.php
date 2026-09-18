<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Cron;

use MageOS\AiBase\Api\UsageDailyRepositoryInterface;
use MageOS\AiBase\Api\UsageRecordRepositoryInterface;
use MageOS\AiBase\Cron\RollUpUsage;
use MageOS\AiBase\Model\Usage\UsageConfig;
use MageOS\AiBase\Model\Usage\UsageMaintenance;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * @covers \MageOS\AiBase\Cron\RollUpUsage
 *
 * Exercises the cron entry point against a real {@see UsageMaintenance} built from the same kind
 * of in-memory fakes {@see \MageOS\AiBase\Test\Unit\Model\Usage\UsageMaintenanceTest} uses, rather
 * than a mock: {@see UsageMaintenance} carries no interface to fake behind, and it is cheap enough
 * to construct for real that mocking it would only hide what this thin class actually delegates to.
 */
final class RollUpUsageTest extends TestCase
{
    private FakeUsageRecordRepository $usageRecordRepository;
    private FakeUsageDailyRepository $usageDailyRepository;
    private FakeScopeConfig $scopeConfig;
    private FakeLogger $logger;
    private RollUpUsage $subject;

    protected function setUp(): void
    {
        $this->usageRecordRepository = new FakeUsageRecordRepository();
        $this->usageDailyRepository = new FakeUsageDailyRepository();
        $this->scopeConfig = new FakeScopeConfig();
        $this->scopeConfig->setValue('mageos_ai/usage/retention_days', '30');
        $this->scopeConfig->setValue('mageos_ai/usage/daily_retention_days', '730');
        $this->logger = new FakeLogger();

        $usageConfig = new UsageConfig($this->scopeConfig);
        $usageMaintenance = new UsageMaintenance(
            $usageConfig,
            $this->usageRecordRepository,
            $this->usageDailyRepository,
            new FakeTimezone('UTC'),
            new \MageOS\AiBase\Test\Unit\Model\Usage\FakeUsageTransaction(),
        );

        $this->subject = new RollUpUsage($usageMaintenance, $this->logger);
    }

    public function test_it_runs_the_usage_maintenance_routine(): void
    {
        $this->scopeConfig->setFlag('mageos_ai/usage/enabled', true);
        $this->usageRecordRepository->addRow($this->row());

        $this->subject->execute();

        self::assertCount(1, $this->usageDailyRepository->getStoredRows());
    }

    public function test_it_still_runs_when_tracking_is_disabled(): void
    {
        $this->scopeConfig->setFlag('mageos_ai/usage/enabled', false);
        $this->usageRecordRepository->addRow($this->row());

        $this->subject->execute();

        // The toggle governs recording, not housekeeping: an install that switches tracking off
        // still wants what it already gathered rolled up and pruned rather than kept forever.
        self::assertCount(1, $this->usageDailyRepository->getStoredRows());
        self::assertCount(0, $this->usageRecordRepository->getRemainingRows());
        self::assertNotSame([], $this->logger->getRecords());
    }

    public function test_it_logs_a_summary_of_what_the_run_aggregated_and_pruned(): void
    {
        $this->scopeConfig->setFlag('mageos_ai/usage/enabled', true);
        $this->usageRecordRepository->addRow($this->row());

        $this->subject->execute();

        $records = $this->logger->getRecords();
        self::assertCount(1, $records);
        self::assertSame('info', $records[0]['level']);
        self::assertStringContainsString('aggregated 1', $records[0]['message']);
        self::assertStringContainsString('deleted 1', $records[0]['message']);
        self::assertStringContainsString('pruned 0', $records[0]['message']);
    }

    public function test_it_logs_and_rethrows_a_maintenance_failure(): void
    {
        $this->scopeConfig->setFlag('mageos_ai/usage/enabled', true);
        $this->usageRecordRepository->addRow($this->row());
        $failure = new \RuntimeException('aggregate query failed');
        $this->usageRecordRepository->throwOnAggregate($failure);

        try {
            $this->subject->execute();
            self::fail('Expected the maintenance failure to be rethrown.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }

        $records = $this->logger->getRecords();
        self::assertCount(1, $records);
        self::assertSame('error', $records[0]['level']);
        self::assertStringContainsString('aggregate query failed', $records[0]['message']);
    }

    /**
     * @param array<string,int|string|null> $overrides
     * @return array<string,int|string|null>
     */
    private function row(array $overrides = []): array
    {
        return array_merge(
            [
                'created_at' => $this->daysAgo(60),
                'service_id' => '_row1',
                'service_code' => 'anthropic',
                'model' => 'claude-sonnet',
                'consumer' => 'chat',
                'store_id' => 0,
                'input_tokens' => 10,
                'output_tokens' => 5,
                'total_tokens' => 15,
                'cache_read_tokens' => null,
                'reasoning_tokens' => null,
            ],
            $overrides
        );
    }

    private function daysAgo(int $days, string $time = '12:00:00'): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify(sprintf('-%d days', $days))
            ->format('Y-m-d') . ' ' . $time;
    }
}

/**
 * In-memory stand-in for {@see UsageRecordRepositoryInterface}, trimmed to what
 * {@see UsageMaintenance} calls, plus {@see throwOnAggregate()} so
 * {@see RollUpUsageTest::test_it_logs_and_rethrows_a_maintenance_failure()} can force the failure
 * path without reaching for a mock.
 */
final class FakeUsageRecordRepository implements UsageRecordRepositoryInterface
{
    /**
     * @var array<int,array<string,int|string|null>>
     */
    private array $rowsById = [];

    private int $nextId = 1;

    private ?\Throwable $aggregateFailure = null;

    /**
     * @param array<string,int|string|null> $row
     */
    public function addRow(array $row): void
    {
        $this->rowsById[$this->nextId++] = $row;
    }

    public function throwOnAggregate(\Throwable $exception): void
    {
        $this->aggregateFailure = $exception;
    }

    public function save(\MageOS\AiBase\Api\Data\UsageRecordInterface $record): void
    {
        throw new \LogicException('Not needed by RollUpUsageTest.');
    }

    /**
     * @return array<int,array<string,int|string|null>>
     */
    public function getRemainingRows(): array
    {
        return array_values($this->rowsById);
    }

    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface
    {
        throw new \LogicException('Not needed by RollUpUsageTest.');
    }

    public function deleteOlderThan(\DateTimeInterface $cutoff): int
    {
        $cutoffString = $cutoff->format('Y-m-d H:i:s');
        $idsToDelete = array_keys(array_filter(
            $this->rowsById,
            fn (array $row): bool => (string) $row['created_at'] < $cutoffString
        ));

        foreach ($idsToDelete as $id) {
            unset($this->rowsById[$id]);
        }

        return count($idsToDelete);
    }

    public function getOldestRecordedAt(): ?\DateTimeImmutable
    {
        if ($this->rowsById === []) {
            return null;
        }

        $oldest = min(array_map(fn (array $row): string => (string) $row['created_at'], $this->rowsById));

        return new \DateTimeImmutable($oldest, new \DateTimeZone('UTC'));
    }

    public function aggregateRange(\DateTimeInterface $from, \DateTimeInterface $to, string $usageDate): array
    {
        if ($this->aggregateFailure !== null) {
            throw $this->aggregateFailure;
        }

        $groups = [];
        foreach ($this->rowsInWindow($from, $to) as $row) {
            $key = implode('|', [$row['service_id'], $row['model'], $row['consumer'], $row['store_id']]);
            $groups[$key][] = $row;
        }

        return array_values(array_map(
            fn (array $rows): array => array_merge(
                [
                    'usage_date' => $usageDate,
                    'service_id' => $rows[0]['service_id'],
                    'service_code' => $rows[0]['service_code'],
                    'model' => $rows[0]['model'],
                    'consumer' => $rows[0]['consumer'],
                    'store_id' => $rows[0]['store_id'],
                ],
                $this->totals($rows)
            ),
            $groups
        ));
    }

    public function sumRange(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        ?string $consumer = null,
        ?int $storeId = null
    ): array
    {
        throw new \LogicException('Not needed by RollUpUsageTest.');
    }

    public function groupRange(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        string $groupBy,
        ?int $storeId = null
    ): array
    {
        throw new \LogicException('Not needed by RollUpUsageTest.');
    }

    public function getDistinctConsumers(): array
    {
        throw new \LogicException('Not needed by RollUpUsageTest.');
    }

    public function seriesRange(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        string $granularity,
        ?int $storeId = null
    ): array
    {
        throw new \LogicException('Not needed by RollUpUsageTest.');
    }

    /**
     * @return array<int,array<string,int|string|null>>
     */
    private function rowsInWindow(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $fromString = $from->format('Y-m-d H:i:s');
        $toString = $to->format('Y-m-d H:i:s');

        return array_values(array_filter(
            $this->rowsById,
            fn (array $row): bool => (string) $row['created_at'] >= $fromString
                && (string) $row['created_at'] < $toString
        ));
    }

    /**
     * @param array<int,array<string,int|string|null>> $rows
     * @return array<string,int|null>
     */
    private function totals(array $rows): array
    {
        $totals = [
            'calls' => 0,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'cache_read_tokens' => null,
            'reasoning_tokens' => null,
        ];

        foreach ($rows as $row) {
            $totals['calls']++;
            $totals['input_tokens'] += (int) $row['input_tokens'];
            $totals['output_tokens'] += (int) $row['output_tokens'];
            $totals['total_tokens'] += (int) $row['total_tokens'];
            if ($row['cache_read_tokens'] !== null) {
                $totals['cache_read_tokens'] = ($totals['cache_read_tokens'] ?? 0) + (int) $row['cache_read_tokens'];
            }
            if ($row['reasoning_tokens'] !== null) {
                $totals['reasoning_tokens'] = ($totals['reasoning_tokens'] ?? 0) + (int) $row['reasoning_tokens'];
            }
        }

        return $totals;
    }

    /**
     * Not exercised by this test's subject; present so the fake satisfies the interface.
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
        return [];
    }
}

/**
 * In-memory stand-in for {@see UsageDailyRepositoryInterface}, trimmed to what
 * {@see UsageMaintenance} calls.
 */
final class FakeUsageDailyRepository implements UsageDailyRepositoryInterface
{
    /**
     * @var array<string,array<string,int|string|null>>
     */
    private array $rowsByKey = [];

    public function saveAggregates(array $rows): void
    {
        foreach ($rows as $row) {
            $key = implode('|', [
                $row['usage_date'],
                $row['service_id'],
                $row['model'],
                $row['consumer'],
                $row['store_id'],
            ]);
            $this->rowsByKey[$key] = $row;
        }
    }

    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface
    {
        throw new \LogicException('Not needed by RollUpUsageTest.');
    }

    public function deleteOlderThan(\DateTimeInterface $cutoff): int
    {
        $cutoffDate = $cutoff->format('Y-m-d');
        $keysToDelete = array_keys(array_filter(
            $this->rowsByKey,
            fn (array $row): bool => (string) $row['usage_date'] < $cutoffDate
        ));

        foreach ($keysToDelete as $key) {
            unset($this->rowsByKey[$key]);
        }

        return count($keysToDelete);
    }

    public function sumRange(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        ?string $consumer = null,
        ?int $storeId = null
    ): array
    {
        throw new \LogicException('Not needed by RollUpUsageTest.');
    }

    public function groupRange(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        string $groupBy,
        ?int $storeId = null
    ): array
    {
        throw new \LogicException('Not needed by RollUpUsageTest.');
    }

    public function seriesRange(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        string $granularity,
        ?int $storeId = null
    ): array
    {
        throw new \LogicException('Not needed by RollUpUsageTest.');
    }

    /**
     * @return array<int,array<string,int|string|null>>
     */
    public function getStoredRows(): array
    {
        return array_values($this->rowsByKey);
    }
    /**
     * Not exercised by this test's subject; present so the fake satisfies the interface.
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
        return [];
    }
}

/**
 * In-memory stand-in for {@see ScopeConfigInterface}, holding plain values and flags a test sets
 * through {@see setValue()} / {@see setFlag()} rather than through a `->method()->willReturn()`
 * mock chain.
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
 * {@see getConfigTimezone()}, the one method {@see UsageMaintenance} calls. Every other method
 * throws, so a test that accidentally depends on one fails loudly instead of silently returning a
 * meaningless default.
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
        throw new \LogicException('Not needed by RollUpUsageTest.');
    }

    public function getDefaultTimezone()
    {
        throw new \LogicException('Not needed by RollUpUsageTest.');
    }

    public function getDateFormat($type = \IntlDateFormatter::SHORT)
    {
        throw new \LogicException('Not needed by RollUpUsageTest.');
    }

    public function getDateFormatWithLongYear()
    {
        throw new \LogicException('Not needed by RollUpUsageTest.');
    }

    public function getTimeFormat($type = null)
    {
        throw new \LogicException('Not needed by RollUpUsageTest.');
    }

    public function getDateTimeFormat($type)
    {
        throw new \LogicException('Not needed by RollUpUsageTest.');
    }

    public function date($date = null, $locale = null, $useTimezone = true, $includeTime = true)
    {
        throw new \LogicException('Not needed by RollUpUsageTest.');
    }

    public function scopeDate($scope = null, $date = null, $includeTime = false)
    {
        throw new \LogicException('Not needed by RollUpUsageTest.');
    }

    public function scopeTimeStamp($scope = null)
    {
        throw new \LogicException('Not needed by RollUpUsageTest.');
    }

    public function formatDate($date = null, $format = \IntlDateFormatter::SHORT, $showTime = false)
    {
        throw new \LogicException('Not needed by RollUpUsageTest.');
    }

    public function isScopeDateInInterval($scope, $dateFrom = null, $dateTo = null)
    {
        throw new \LogicException('Not needed by RollUpUsageTest.');
    }

    public function formatDateTime(
        $date,
        $dateType = \IntlDateFormatter::SHORT,
        $timeType = \IntlDateFormatter::SHORT,
        $locale = null,
        $timezone = null,
        $pattern = null
    ) {
        throw new \LogicException('Not needed by RollUpUsageTest.');
    }

    public function convertConfigTimeToUtc($date, $format = 'Y-m-d H:i:s')
    {
        throw new \LogicException('Not needed by RollUpUsageTest.');
    }
}

/**
 * In-memory stand-in for {@see \Psr\Log\LoggerInterface}, kept next to the test that uses it.
 */
final class FakeLogger extends AbstractLogger
{
    /**
     * @var list<array{level: mixed, message: string, context: array<mixed>}>
     */
    private array $records = [];

    /**
     * @inheritdoc
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * @return list<array{level: mixed, message: string, context: array<mixed>}>
     */
    public function getRecords(): array
    {
        return $this->records;
    }
}
