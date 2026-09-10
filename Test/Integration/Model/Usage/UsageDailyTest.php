<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Integration\Model\Usage;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AiBase\Api\UsageDailyRepositoryInterface;
use MageOS\AiBase\Model\ResourceModel\Usage\UsageDaily;
use PHPUnit\Framework\TestCase;

/**
 * Proves the SQL behind {@see UsageDaily} against a real database.
 *
 * This is the counterpart the class docblock on
 * {@see \MageOS\AiBase\Test\Unit\Model\Usage\UsageDailyRepositoryTest} points to: that suite's
 * fake mirrors MySQL's insert-on-duplicate semantics closely enough to drive the repository's own
 * tests, but only a real database can prove the fake's mirroring is actually correct — in
 * particular that saving the same aggregates twice really does leave one row with unchanged
 * totals, which is MySQL's behaviour, not PHP's.
 */
final class UsageDailyTest extends TestCase
{
    private const TABLE = 'mageos_ai_usage_daily';

    private ObjectManagerInterface $objectManager;
    private UsageDaily $resource;
    private ResourceConnection $resourceConnection;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
        $this->resource = $this->objectManager->get(UsageDaily::class);
        $this->resourceConnection = $this->objectManager->get(ResourceConnection::class);
        $this->truncateTable();
    }

    protected function tearDown(): void
    {
        $this->truncateTable();
    }

    public function test_it_stores_a_daily_aggregate_row(): void
    {
        $this->resource->upsertAggregates([$this->row()]);

        $stored = $this->fetchAllRows();
        self::assertCount(1, $stored);
        self::assertSame('2026-01-15', $stored[0]['usage_date']);
        self::assertSame('chat', $stored[0]['consumer']);
        self::assertSame(15, (int) $stored[0]['total_tokens']);
    }

    public function test_it_replaces_the_counts_of_an_existing_row_for_the_same_day_and_grouping_key(): void
    {
        $this->resource->upsertAggregates([$this->row(['calls' => 3, 'total_tokens' => 300])]);
        $this->resource->upsertAggregates([$this->row(['calls' => 7, 'total_tokens' => 700])]);

        $stored = $this->fetchAllRows();
        self::assertCount(1, $stored);
        self::assertSame(7, (int) $stored[0]['calls']);
        self::assertSame(700, (int) $stored[0]['total_tokens']);
    }

    /**
     * The requirement this whole task exists for: MySQL's unique key on
     * (`usage_date`, `service_id`, `model`, `consumer`, `store_id`) is what lets an identical
     * second write match and replace instead of insert, so a cron re-running after a partial
     * failure never doubles a day's totals. No fake can prove this; it is MySQL's behaviour.
     */
    public function test_it_does_not_double_the_totals_when_the_same_aggregates_are_saved_twice(): void
    {
        $row = $this->row(['calls' => 5, 'input_tokens' => 40, 'output_tokens' => 10, 'total_tokens' => 50]);

        $this->resource->upsertAggregates([$row]);
        $this->resource->upsertAggregates([$row]);

        $stored = $this->fetchAllRows();
        self::assertCount(1, $stored);
        self::assertSame(5, (int) $stored[0]['calls']);
        self::assertSame(50, (int) $stored[0]['total_tokens']);
    }

    public function test_it_stores_rows_for_different_consumers_on_the_same_day_separately(): void
    {
        $this->resource->upsertAggregates([
            $this->row(['consumer' => 'chat']),
            $this->row(['consumer' => 'docs_search']),
        ]);

        self::assertCount(2, $this->fetchAllRows());
    }

    public function test_it_stores_rows_for_different_service_rows_on_the_same_day_separately(): void
    {
        $this->resource->upsertAggregates([
            $this->row(['service_id' => '_row1']),
            $this->row(['service_id' => '_row2']),
        ]);

        self::assertCount(2, $this->fetchAllRows());
    }

    public function test_it_writes_a_batch_of_aggregates_in_a_single_statement(): void
    {
        $connection = $this->resourceConnection->getConnection();
        $profiler = $connection->getProfiler();
        $profiler->setEnabled(true);
        $queriesBefore = $profiler->getTotalNumQueries();

        $this->resource->upsertAggregates([
            $this->row(['consumer' => 'chat']),
            $this->row(['consumer' => 'docs_search']),
            $this->row(['consumer' => 'skills']),
        ]);

        $profiler->setEnabled(false);

        self::assertSame(1, $profiler->getTotalNumQueries() - $queriesBefore);
        self::assertCount(3, $this->fetchAllRows());
    }

    public function test_it_deletes_only_daily_rows_older_than_the_given_cutoff(): void
    {
        $this->resource->upsertAggregates([
            $this->row(['usage_date' => '2026-01-01']),
            $this->row(['usage_date' => '2026-06-01', 'consumer' => 'docs_search']),
        ]);

        $deleted = $this->resource->deleteOlderThan(new \DateTimeImmutable('2026-02-01'));

        self::assertSame(1, $deleted);
        $remaining = $this->fetchAllRows();
        self::assertCount(1, $remaining);
        self::assertSame('2026-06-01', $remaining[0]['usage_date']);
    }

    public function test_it_totals_a_date_window_optionally_narrowed_to_one_consumer(): void
    {
        $this->resource->upsertAggregates([
            $this->row([
                'consumer' => 'chat',
                'input_tokens' => 10,
                'output_tokens' => 5,
                'total_tokens' => 15,
                'cached_tokens' => 2,
            ]),
            $this->row([
                'consumer' => 'docs_search',
                'input_tokens' => 100,
                'output_tokens' => 50,
                'total_tokens' => 150,
            ]),
        ]);

        $overall = $this->resource->sumRange(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31'));
        $chatOnly = $this->resource->sumRange(
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
            'chat'
        );

        self::assertSame(165, $overall['total_tokens']);
        self::assertSame(2, $overall['cached_tokens']);
        self::assertSame(15, $chatOnly['total_tokens']);
        self::assertNull($chatOnly['reasoning_tokens']);
    }

    public function test_it_groups_a_date_window_by_consumer_and_by_service_row(): void
    {
        $this->resource->upsertAggregates([
            $this->row(['consumer' => 'chat', 'service_id' => '_row1', 'total_tokens' => 15]),
            $this->row(['consumer' => 'docs_search', 'service_id' => '_row2', 'total_tokens' => 150]),
        ]);

        $byConsumer = $this->resource->groupRange(
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
            UsageDailyRepositoryInterface::GROUP_BY_CONSUMER
        );
        $byService = $this->resource->groupRange(
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
            UsageDailyRepositoryInterface::GROUP_BY_SERVICE
        );

        self::assertSame(['docs_search', 'chat'], array_column($byConsumer, 'consumer'));
        self::assertSame(['_row2', '_row1'], array_column($byService, 'service_id'));
    }

    public function test_it_returns_a_per_day_and_a_per_month_series_across_a_window(): void
    {
        $this->resource->upsertAggregates([
            $this->row(['usage_date' => '2026-01-05', 'total_tokens' => 10]),
            $this->row(['usage_date' => '2026-01-06', 'total_tokens' => 20, 'consumer' => 'docs_search']),
            $this->row(['usage_date' => '2026-02-01', 'total_tokens' => 30, 'consumer' => 'skills']),
        ]);

        $daily = $this->resource->seriesRange(
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-03-01'),
            UsageDailyRepositoryInterface::GRANULARITY_DAY
        );
        $monthly = $this->resource->seriesRange(
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-03-01'),
            UsageDailyRepositoryInterface::GRANULARITY_MONTH
        );

        self::assertSame(['2026-01-05', '2026-01-06', '2026-02-01'], array_column($daily, 'period'));
        self::assertSame(['2026-01', '2026-02'], array_column($monthly, 'period'));
        self::assertSame(30, $monthly[0]['total_tokens']);
    }

    public function test_it_narrows_a_total_to_one_store(): void
    {
        $this->resource->upsertAggregates([$this->row(['store_id' => 1, 'total_tokens' => 15])]);
        $this->resource->upsertAggregates([$this->row(['store_id' => 2, 'total_tokens' => 150])]);

        $storeOne = $this->resource->sumRange(
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2026-02-01 00:00:00'),
            null,
            1
        );

        self::assertSame(15, $storeOne['total_tokens']);
    }

    public function test_it_narrows_a_grouped_window_to_one_store(): void
    {
        $this->resource->upsertAggregates([$this->row(['consumer' => 'chat', 'store_id' => 1, 'total_tokens' => 15])]);
        $this->resource->upsertAggregates([$this->row(['consumer' => 'docs_search', 'store_id' => 2, 'total_tokens' => 150])]);

        $storeOne = $this->resource->groupRange(
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2026-02-01 00:00:00'),
            UsageDailyRepositoryInterface::GROUP_BY_CONSUMER,
            1
        );

        self::assertSame(['chat'], array_column($storeOne, 'consumer'));
    }

    public function test_it_narrows_a_series_to_one_store(): void
    {
        $this->resource->upsertAggregates([$this->row(['usage_date' => '2026-01-15', 'store_id' => 1, 'total_tokens' => 15])]);
        $this->resource->upsertAggregates([$this->row(['usage_date' => '2026-01-15', 'store_id' => 2, 'total_tokens' => 150])]);

        $storeOne = $this->resource->seriesRange(
            new \DateTimeImmutable('2026-01-15 00:00:00'),
            new \DateTimeImmutable('2026-01-16 00:00:00'),
            UsageDailyRepositoryInterface::GRANULARITY_DAY,
            1
        );

        self::assertSame([15], array_column($storeOne, 'total_tokens'));
    }

    public function test_it_returns_a_grouped_series_with_one_row_per_bucket_and_group(): void
    {
        $this->resource->upsertAggregates([
            $this->row(['usage_date' => '2026-01-15', 'consumer' => 'chat', 'total_tokens' => 15]),
            $this->row(['usage_date' => '2026-01-15', 'consumer' => 'docs_search', 'total_tokens' => 150]),
            $this->row(['usage_date' => '2026-01-16', 'consumer' => 'chat', 'total_tokens' => 7]),
        ]);

        $series = $this->resource->seriesRangeGrouped(
            new \DateTimeImmutable('2026-01-15 00:00:00'),
            new \DateTimeImmutable('2026-01-17 00:00:00'),
            UsageDailyRepositoryInterface::GRANULARITY_DAY,
            UsageDailyRepositoryInterface::GROUP_BY_CONSUMER
        );

        $byBucketAndGroup = [];
        foreach ($series as $row) {
            $byBucketAndGroup[$row['period'] . '|' . $row['consumer']] = $row['total_tokens'];
        }

        self::assertSame(15, $byBucketAndGroup['2026-01-15|chat'] ?? null);
        self::assertSame(150, $byBucketAndGroup['2026-01-15|docs_search'] ?? null);
        self::assertSame(7, $byBucketAndGroup['2026-01-16|chat'] ?? null);
    }

    public function test_it_buckets_a_grouped_series_by_month_as_well_as_by_day(): void
    {
        $this->resource->upsertAggregates([
            $this->row(['usage_date' => '2026-01-05', 'consumer' => 'chat', 'total_tokens' => 10]),
            $this->row(['usage_date' => '2026-01-25', 'consumer' => 'chat', 'total_tokens' => 20]),
            $this->row(['usage_date' => '2026-02-05', 'consumer' => 'chat', 'total_tokens' => 40]),
        ]);

        $series = $this->resource->seriesRangeGrouped(
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2026-03-01 00:00:00'),
            UsageDailyRepositoryInterface::GRANULARITY_MONTH,
            UsageDailyRepositoryInterface::GROUP_BY_CONSUMER
        );

        self::assertSame(['2026-01', '2026-02'], array_column($series, 'period'));
        self::assertSame([30, 40], array_column($series, 'total_tokens'));
    }

    public function test_it_narrows_a_grouped_series_to_one_store(): void
    {
        $this->resource->upsertAggregates([
            $this->row(['usage_date' => '2026-01-15', 'consumer' => 'chat', 'store_id' => 1]),
            $this->row(['usage_date' => '2026-01-15', 'consumer' => 'docs_search', 'store_id' => 2]),
        ]);

        $series = $this->resource->seriesRangeGrouped(
            new \DateTimeImmutable('2026-01-15 00:00:00'),
            new \DateTimeImmutable('2026-01-16 00:00:00'),
            UsageDailyRepositoryInterface::GRANULARITY_DAY,
            UsageDailyRepositoryInterface::GROUP_BY_CONSUMER,
            1
        );

        self::assertSame(['chat'], array_column($series, 'consumer'));
    }

    public function test_it_rejects_a_grouping_column_outside_its_allowlist(): void
    {
        // $groupBy becomes a raw SQL identifier, so the allowlist is the only thing between an
        // unexpected caller and the query.
        $this->expectException(\InvalidArgumentException::class);

        $this->resource->seriesRangeGrouped(
            new \DateTimeImmutable('2026-01-15 00:00:00'),
            new \DateTimeImmutable('2026-01-16 00:00:00'),
            UsageDailyRepositoryInterface::GRANULARITY_DAY,
            'model'
        );
    }

    /**
     * @param array<string,int|string|null> $overrides
     * @return array<string,int|string|null>
     */
    private function row(array $overrides = []): array
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

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchAllRows(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()->from($this->resourceConnection->getTableName(self::TABLE));

        return $connection->fetchAll($select);
    }

    private function truncateTable(): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->delete($this->resourceConnection->getTableName(self::TABLE));
    }
}
