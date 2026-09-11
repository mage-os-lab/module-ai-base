<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Usage;

require_once __DIR__ . '/../../Stubs/UsageDailyCollectionFactoryStub.php';
require_once __DIR__ . '/../../Stubs/SearchResultsInterfaceFactoryStub.php';

use MageOS\AiBase\Api\UsageDailyRepositoryInterface;
use MageOS\AiBase\Model\ResourceModel\Usage\UsageDaily\CollectionFactory;
use MageOS\AiBase\Model\ResourceModel\Usage\UsageDailyResourceInterface;
use MageOS\AiBase\Model\Usage\UsageDailyRepository;
use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchResultsInterfaceFactory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MageOS\AiBase\Model\Usage\UsageDailyRepository
 *
 * Exercises the repository against {@see FakeUsageDailyResource}, an in-memory stand-in for
 * {@see \MageOS\AiBase\Model\ResourceModel\Usage\UsageDaily}. `Magento\Framework\DB\Adapter\
 * AdapterInterface` has over a hundred methods and is not realistically fakeable, which is why the
 * upsert/replace/idempotency behaviour under test here is proven twice: the fake mirrors MySQL's
 * insert-on-duplicate semantics closely enough to drive these tests honestly, and
 * `Test/Integration/Model/Usage/UsageDailyTest.php` proves the same behaviour against a real
 * database, which is the only thing that can actually prove it.
 */
final class UsageDailyRepositoryTest extends TestCase
{
    private FakeUsageDailyResource $resource;
    private UsageDailyRepository $subject;

    protected function setUp(): void
    {
        $this->resource = new FakeUsageDailyResource();
        $this->subject = new UsageDailyRepository(
            $this->resource,
            $this->createMock(CollectionFactory::class),
            $this->createMock(SearchResultsInterfaceFactory::class),
            $this->createMock(CollectionProcessorInterface::class),
        );
    }

    public function test_it_stores_a_daily_aggregate_row(): void
    {
        $this->subject->saveAggregates([$this->aggregateRow()]);

        self::assertSame([$this->aggregateRow()], $this->resource->getStoredRows());
    }

    public function test_it_replaces_the_counts_of_an_existing_row_for_the_same_day_and_grouping_key(): void
    {
        $this->subject->saveAggregates([$this->aggregateRow(['calls' => 3, 'total_tokens' => 300])]);
        $this->subject->saveAggregates([$this->aggregateRow(['calls' => 7, 'total_tokens' => 700])]);

        $stored = $this->resource->getStoredRows();
        self::assertCount(1, $stored);
        self::assertSame(7, $stored[0]['calls']);
        self::assertSame(700, $stored[0]['total_tokens']);
    }

    public function test_it_does_not_double_the_totals_when_the_same_aggregates_are_saved_twice(): void
    {
        $row = $this->aggregateRow(['calls' => 5, 'total_tokens' => 500]);

        $this->subject->saveAggregates([$row]);
        $this->subject->saveAggregates([$row]);

        $stored = $this->resource->getStoredRows();
        self::assertCount(1, $stored);
        self::assertSame(5, $stored[0]['calls']);
        self::assertSame(500, $stored[0]['total_tokens']);
    }

    public function test_it_stores_rows_for_different_consumers_on_the_same_day_separately(): void
    {
        $this->subject->saveAggregates([
            $this->aggregateRow(['consumer' => 'chat']),
            $this->aggregateRow(['consumer' => 'docs_search']),
        ]);

        self::assertCount(2, $this->resource->getStoredRows());
    }

    public function test_it_stores_rows_for_different_service_rows_on_the_same_day_separately(): void
    {
        $this->subject->saveAggregates([
            $this->aggregateRow(['service_id' => '_row1']),
            $this->aggregateRow(['service_id' => '_row2']),
        ]);

        self::assertCount(2, $this->resource->getStoredRows());
    }

    public function test_it_writes_a_batch_of_aggregates_in_a_single_statement(): void
    {
        $this->subject->saveAggregates([
            $this->aggregateRow(['consumer' => 'chat']),
            $this->aggregateRow(['consumer' => 'docs_search']),
            $this->aggregateRow(['consumer' => 'skills']),
        ]);

        self::assertSame(1, $this->resource->getUpsertCallCount());
    }

    public function test_it_deletes_only_daily_rows_older_than_the_given_cutoff(): void
    {
        $this->subject->saveAggregates([
            $this->aggregateRow(['usage_date' => '2026-01-01']),
            $this->aggregateRow(['usage_date' => '2026-06-01', 'consumer' => 'docs_search']),
        ]);

        $deleted = $this->subject->deleteOlderThan(new \DateTimeImmutable('2026-02-01'));

        self::assertSame(1, $deleted);
        self::assertCount(1, $this->resource->getStoredRows());
        self::assertSame('2026-06-01', $this->resource->getStoredRows()[0]['usage_date']);
    }

    public function test_it_totals_a_date_window_optionally_narrowed_to_one_consumer(): void
    {
        $this->subject->saveAggregates([
            $this->aggregateRow(['consumer' => 'chat', 'input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15]),
            $this->aggregateRow(['consumer' => 'docs_search', 'input_tokens' => 100, 'output_tokens' => 50, 'total_tokens' => 150]),
        ]);

        $overall = $this->subject->sumRange(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31'));
        $chatOnly = $this->subject->sumRange(
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
            'chat'
        );

        self::assertSame(165, $overall['total_tokens']);
        self::assertSame(15, $chatOnly['total_tokens']);
    }

    public function test_it_groups_a_date_window_by_consumer_and_by_service_row(): void
    {
        $this->subject->saveAggregates([
            $this->aggregateRow(['consumer' => 'chat', 'service_id' => '_row1', 'total_tokens' => 15]),
            $this->aggregateRow(['consumer' => 'docs_search', 'service_id' => '_row2', 'total_tokens' => 150]),
        ]);

        $byConsumer = $this->subject->groupRange(
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
            UsageDailyRepositoryInterface::GROUP_BY_CONSUMER
        );
        $byService = $this->subject->groupRange(
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
            UsageDailyRepositoryInterface::GROUP_BY_SERVICE
        );

        self::assertSame(['docs_search', 'chat'], array_column($byConsumer, 'consumer'));
        self::assertSame(['_row2', '_row1'], array_column($byService, 'service_id'));
    }

    public function test_it_returns_a_per_day_and_a_per_month_series_across_a_window(): void
    {
        $this->subject->saveAggregates([
            $this->aggregateRow(['usage_date' => '2026-01-05', 'total_tokens' => 10]),
            $this->aggregateRow(['usage_date' => '2026-01-06', 'total_tokens' => 20, 'consumer' => 'docs_search']),
            $this->aggregateRow(['usage_date' => '2026-02-01', 'total_tokens' => 30, 'consumer' => 'skills']),
        ]);

        $daily = $this->subject->seriesRange(
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-03-01'),
            UsageDailyRepositoryInterface::GRANULARITY_DAY
        );
        $monthly = $this->subject->seriesRange(
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-03-01'),
            UsageDailyRepositoryInterface::GRANULARITY_MONTH
        );

        self::assertSame(['2026-01-05', '2026-01-06', '2026-02-01'], array_column($daily, 'period'));
        self::assertSame(['2026-01', '2026-02'], array_column($monthly, 'period'));
        self::assertSame(30, $monthly[0]['total_tokens']);
    }

    /**
     * @param array<string,int|string|null> $overrides
     * @return array<string,int|string|null>
     */
    private function aggregateRow(array $overrides = []): array
    {
        return array_merge(
            [
                'usage_date' => '2026-01-15',
                'service_id' => '_row1',
                'service_code' => 'anthropic',
                'model' => 'claude-sonnet',
                'consumer' => 'chat',
                'store_id' => 0,
                'calls' => 1,
                'input_tokens' => 10,
                'output_tokens' => 5,
                'total_tokens' => 15,
                'cached_tokens' => null,
                'reasoning_tokens' => null,
            ],
            $overrides
        );
    }
}

/**
 * In-memory stand-in for {@see \MageOS\AiBase\Model\ResourceModel\Usage\UsageDaily}, kept next to
 * the test that uses it per this codebase's fakes-over-mocks convention.
 *
 * Mirrors MySQL's insert-on-duplicate-key semantics closely enough to drive
 * {@see UsageDailyRepositoryTest} honestly: a row is keyed by the same
 * (`usage_date`, `service_id`, `model`, `consumer`, `store_id`) tuple the real unique constraint
 * covers, and a second write to the same key replaces rather than sums the stored counts.
 */
final class FakeUsageDailyResource implements UsageDailyResourceInterface
{
    /**
     * @var array<string,array<string,int|string|null>>
     */
    private array $rowsByKey = [];

    /**
     * Number of times {@see upsertAggregates()} was called, so a test can prove a batch was
     * written in one statement rather than one call per row.
     */
    private int $upsertCallCount = 0;

    /**
     * @inheritdoc
     */
    public function upsertAggregates(array $rows): void
    {
        $this->upsertCallCount++;
        foreach ($rows as $row) {
            $this->rowsByKey[$this->groupingKey($row)] = $row;
        }
    }

    /**
     * @inheritdoc
     */
    public function deleteOlderThan(\DateTimeInterface $cutoff): int
    {
        $cutoffDate = $cutoff->format('Y-m-d');
        $before = count($this->rowsByKey);

        $this->rowsByKey = array_filter(
            $this->rowsByKey,
            fn (array $row): bool => (string) $row['usage_date'] >= $cutoffDate
        );

        return $before - count($this->rowsByKey);
    }

    /**
     * @inheritdoc
     */
    public function sumRange(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        ?string $consumer = null,
        ?int $storeId = null
    ): array
    {
        $rows = $this->rowsInWindow($from, $to);
        if ($consumer !== null) {
            $rows = array_filter($rows, fn (array $row): bool => $row['consumer'] === $consumer);
        }

        return $this->totals($rows);
    }

    /**
     * @inheritdoc
     */
    public function groupRange(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        string $groupBy,
        ?int $storeId = null
    ): array
    {
        $groups = [];
        foreach ($this->rowsInWindow($from, $to) as $row) {
            $groups[(string) $row[$groupBy]][] = $row;
        }

        $result = array_map(
            fn (string $groupValue, array $rows): array => array_merge(
                [$groupBy => $groupValue],
                $this->totals($rows)
            ),
            array_keys($groups),
            array_values($groups)
        );

        usort($result, fn (array $left, array $right): int => $right['total_tokens'] <=> $left['total_tokens']);

        return $result;
    }

    /**
     * @inheritdoc
     */
    public function seriesRange(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        string $granularity,
        ?int $storeId = null
    ): array
    {
        $buckets = [];
        foreach ($this->rowsInWindow($from, $to) as $row) {
            $period = $granularity === UsageDailyRepositoryInterface::GRANULARITY_MONTH
                ? substr((string) $row['usage_date'], 0, 7)
                : (string) $row['usage_date'];
            $buckets[$period][] = $row;
        }

        ksort($buckets);

        return array_map(
            fn (string $period, array $rows): array => array_merge(['period' => $period], $this->totals($rows)),
            array_keys($buckets),
            array_values($buckets)
        );
    }

    /**
     * @return int Number of times {@see upsertAggregates()} was called.
     */
    public function getUpsertCallCount(): int
    {
        return $this->upsertCallCount;
    }

    /**
     * @return array<int,array<string,int|string|null>> Every row currently stored, for a test to
     *         assert on directly.
     */
    public function getStoredRows(): array
    {
        return array_values($this->rowsByKey);
    }

    /**
     * @param array<string,int|string|null> $row
     * @return string
     */
    private function groupingKey(array $row): string
    {
        return implode('|', [
            $row['usage_date'],
            $row['service_id'],
            $row['model'],
            $row['consumer'],
            $row['store_id'],
        ]);
    }

    /**
     * @param \DateTimeInterface $from
     * @param \DateTimeInterface $to
     * @return array<int,array<string,int|string|null>>
     */
    private function rowsInWindow(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $fromDate = $from->format('Y-m-d');
        $toDate = $to->format('Y-m-d');

        return array_values(array_filter(
            $this->rowsByKey,
            fn (array $row): bool => (string) $row['usage_date'] >= $fromDate && (string) $row['usage_date'] < $toDate
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
            'cached_tokens' => null,
            'reasoning_tokens' => null,
        ];

        foreach ($rows as $row) {
            $totals['calls'] += (int) $row['calls'];
            $totals['input_tokens'] += (int) $row['input_tokens'];
            $totals['output_tokens'] += (int) $row['output_tokens'];
            $totals['total_tokens'] += (int) $row['total_tokens'];
            if ($row['cached_tokens'] !== null) {
                $totals['cached_tokens'] = ($totals['cached_tokens'] ?? 0) + (int) $row['cached_tokens'];
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
