<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Integration\Model\Usage;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AiBase\Api\UsageDailyRepositoryInterface;
use MageOS\AiBase\Api\UsageRecordRepositoryInterface;
use MageOS\AiBase\Model\ResourceModel\Usage\UsageLog;
use PHPUnit\Framework\TestCase;

/**
 * Proves the SQL behind {@see UsageLog} against a real database.
 *
 * This is the counterpart the class docblock on
 * {@see \MageOS\AiBase\Test\Unit\Model\Usage\UsageRecordRepositoryTest} points to: that suite's
 * fake mirrors the resource model's contract closely enough to drive the repository's own tests,
 * but only a real database can prove the fake's mirroring is actually correct — in particular the
 * bounded batch delete and the half-open window boundary, both of which are MySQL's behaviour, not
 * PHP's.
 *
 * {@see UsageLog::seriesRange()}'s store-timezone bucketing (task 021) is exercised here rather
 * than in the unit suite for the same reason: the fake in
 * {@see \MageOS\AiBase\Test\Unit\Model\Usage\UsageRecordRepositoryTest} only proves this
 * repository delegates to the resource model, never the resource model's own bucket-boundary
 * arithmetic, since that arithmetic reads the real `TimezoneInterface`, which only a bootstrapped
 * store (and therefore this suite) has.
 */
final class UsageLogTest extends TestCase
{
    private const TABLE = 'mageos_ai_usage_log';

    private ObjectManagerInterface $objectManager;
    private UsageLog $resource;
    private ResourceConnection $resourceConnection;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
        $this->resource = $this->objectManager->get(UsageLog::class);
        $this->resourceConnection = $this->objectManager->get(ResourceConnection::class);
        $this->truncateTable();
    }

    protected function tearDown(): void
    {
        $this->truncateTable();
    }

    public function test_it_saves_a_usage_record_and_assigns_it_an_id(): void
    {
        $id = $this->resource->insert($this->row());

        self::assertGreaterThan(0, $id);
        $stored = $this->fetchAllRows();
        self::assertCount(1, $stored);
        self::assertSame((string) $id, (string) $stored[0]['entity_id']);
    }

    public function test_it_maps_every_token_count_onto_the_stored_row(): void
    {
        $this->resource->insert($this->row([
            'input_tokens' => 10,
            'output_tokens' => 5,
            'total_tokens' => 15,
            'cache_read_tokens' => 3,
            'cache_write_tokens' => 4,
            'reasoning_tokens' => 2,
        ]));

        $stored = $this->fetchAllRows()[0];
        self::assertSame(10, (int) $stored['input_tokens']);
        self::assertSame(5, (int) $stored['output_tokens']);
        self::assertSame(15, (int) $stored['total_tokens']);
        self::assertSame(3, (int) $stored['cache_read_tokens']);
        self::assertSame(4, (int) $stored['cache_write_tokens']);
        self::assertSame(2, (int) $stored['reasoning_tokens']);
    }

    public function test_it_deletes_only_records_older_than_the_given_cutoff(): void
    {
        $this->resource->insert($this->row(['created_at' => '2026-01-01 00:00:00']));
        $this->resource->insert($this->row(['created_at' => '2026-06-01 00:00:00']));

        $deleted = $this->resource->deleteBatch(new \DateTimeImmutable('2026-02-01 00:00:00'), 100);

        self::assertSame(1, $deleted);
        $remaining = $this->fetchAllRows();
        self::assertCount(1, $remaining);
        self::assertSame('2026-06-01 00:00:00', $remaining[0]['created_at']);
    }

    /**
     * The requirement this whole task exists for: a store that ran tracking for months has a
     * table the prune has to chew through without holding a lock for minutes, so
     * {@see UsageLog::deleteBatch()} bounds every statement at $limit rows rather than deleting
     * everything older than the cutoff in one unbounded `DELETE`.
     */
    public function test_it_deletes_in_batches_rather_than_a_single_unbounded_statement(): void
    {
        $this->resource->insert($this->row(['created_at' => '2026-01-01 00:00:00']));
        $this->resource->insert($this->row(['created_at' => '2026-01-02 00:00:00']));
        $this->resource->insert($this->row(['created_at' => '2026-01-03 00:00:00']));

        $deleted = $this->resource->deleteBatch(new \DateTimeImmutable('2026-02-01 00:00:00'), 2);

        self::assertSame(2, $deleted);
        self::assertCount(1, $this->fetchAllRows());
    }

    public function test_it_reports_the_oldest_recorded_timestamp(): void
    {
        $this->resource->insert($this->row(['created_at' => '2026-03-01 00:00:00']));
        $this->resource->insert($this->row(['created_at' => '2026-01-01 00:00:00']));
        $this->resource->insert($this->row(['created_at' => '2026-02-01 00:00:00']));

        $oldest = $this->resource->getOldestRecordedAt();

        self::assertInstanceOf(\DateTimeImmutable::class, $oldest);
        self::assertSame('2026-01-01 00:00:00', $oldest->format('Y-m-d H:i:s'));
    }

    public function test_it_reports_no_oldest_timestamp_when_nothing_has_been_recorded(): void
    {
        self::assertNull($this->resource->getOldestRecordedAt());
    }

    public function test_it_aggregates_a_time_window_by_service_row_model_consumer_and_store(): void
    {
        $this->resource->insert($this->row([
            'created_at' => '2026-01-10 00:00:00',
            'service_id' => '_row1',
            'model' => 'claude-sonnet',
            'consumer' => 'chat',
            'store_id' => 0,
        ]));
        $this->resource->insert($this->row([
            'created_at' => '2026-01-11 00:00:00',
            'service_id' => '_row2',
            'model' => 'gpt-5',
            'consumer' => 'docs_search',
            'store_id' => 1,
        ]));

        $rows = $this->resource->aggregateRange(
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2026-02-01 00:00:00'),
            '2026-01'
        );

        self::assertCount(2, $rows);
        self::assertSame(['_row1', '_row2'], array_column($rows, 'service_id'));
        self::assertSame(['claude-sonnet', 'gpt-5'], array_column($rows, 'model'));
        self::assertSame(['chat', 'docs_search'], array_column($rows, 'consumer'));
        self::assertSame([0, 1], array_column($rows, 'store_id'));
    }

    public function test_it_counts_the_calls_in_each_aggregated_group(): void
    {
        $this->resource->insert($this->row(['created_at' => '2026-01-10 00:00:00', 'consumer' => 'chat']));
        $this->resource->insert($this->row(['created_at' => '2026-01-11 00:00:00', 'consumer' => 'chat']));
        $this->resource->insert($this->row(['created_at' => '2026-01-12 00:00:00', 'consumer' => 'docs_search']));

        $rows = $this->resource->aggregateRange(
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2026-02-01 00:00:00'),
            '2026-01'
        );

        $callsByConsumer = array_combine(array_column($rows, 'consumer'), array_column($rows, 'calls'));
        self::assertSame(2, $callsByConsumer['chat']);
        self::assertSame(1, $callsByConsumer['docs_search']);
    }

    public function test_it_excludes_rows_on_the_closing_boundary_of_the_window(): void
    {
        $this->resource->insert($this->row(['created_at' => '2026-01-31 23:59:59']));
        $this->resource->insert($this->row(['created_at' => '2026-02-01 00:00:00']));

        $totals = $this->resource->sumRange(
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2026-02-01 00:00:00')
        );

        self::assertSame(1, $totals['calls']);
    }

    public function test_it_totals_a_window_optionally_narrowed_to_one_consumer(): void
    {
        $this->resource->insert($this->row(['consumer' => 'chat', 'total_tokens' => 15]));
        $this->resource->insert($this->row(['consumer' => 'docs_search', 'total_tokens' => 150]));

        $overall = $this->resource->sumRange(
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2026-02-01 00:00:00')
        );
        $chatOnly = $this->resource->sumRange(
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2026-02-01 00:00:00'),
            'chat'
        );

        self::assertSame(165, $overall['total_tokens']);
        self::assertSame(15, $chatOnly['total_tokens']);
    }

    public function test_it_counts_rows_without_usage_as_calls(): void
    {
        $this->resource->insert($this->row([
            'input_tokens' => null,
            'output_tokens' => null,
            'total_tokens' => null,
        ]));

        $totals = $this->resource->sumRange(
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2026-02-01 00:00:00')
        );

        self::assertSame(1, $totals['calls']);
    }

    public function test_it_sums_tokens_treating_unreported_as_zero(): void
    {
        $this->resource->insert($this->row(['total_tokens' => 15]));
        $this->resource->insert($this->row(['total_tokens' => null]));

        $totals = $this->resource->sumRange(
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2026-02-01 00:00:00')
        );

        self::assertSame(15, $totals['total_tokens']);
    }

    public function test_it_sums_cache_read_and_write_separately(): void
    {
        $this->resource->insert($this->row(['cache_read_tokens' => 4, 'cache_write_tokens' => 1]));
        $this->resource->insert($this->row(['cache_read_tokens' => 6, 'cache_write_tokens' => 2]));

        $totals = $this->resource->sumRange(
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2026-02-01 00:00:00')
        );

        self::assertSame(10, $totals['cache_read_tokens']);
        self::assertSame(3, $totals['cache_write_tokens']);
    }

    public function test_it_counts_failed_calls(): void
    {
        $this->resource->insert($this->row(['failed' => 1]));
        $this->resource->insert($this->row(['failed' => 1]));
        $this->resource->insert($this->row(['failed' => 0]));

        $totals = $this->resource->sumRange(
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2026-02-01 00:00:00')
        );

        self::assertSame(2, $totals['failed_calls']);
    }

    public function test_it_reports_zero_failed_calls_for_an_empty_window(): void
    {
        $totals = $this->resource->sumRange(
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2026-02-01 00:00:00')
        );

        self::assertSame(0, $totals['failed_calls']);
    }

    public function test_it_keeps_cache_sums_null_when_no_row_reported_them(): void
    {
        $this->resource->insert($this->row(['cache_read_tokens' => null, 'cache_write_tokens' => null]));

        $totals = $this->resource->sumRange(
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2026-02-01 00:00:00')
        );

        self::assertNull($totals['cache_read_tokens']);
        self::assertNull($totals['cache_write_tokens']);
    }

    public function test_it_groups_a_window_by_consumer_and_by_service_row(): void
    {
        $this->resource->insert($this->row(['consumer' => 'chat', 'service_id' => '_row1', 'total_tokens' => 15]));
        $this->resource->insert(
            $this->row(['consumer' => 'docs_search', 'service_id' => '_row2', 'total_tokens' => 150])
        );

        $byConsumer = $this->resource->groupRange(
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2026-02-01 00:00:00'),
            UsageRecordRepositoryInterface::GROUP_BY_CONSUMER
        );
        $byService = $this->resource->groupRange(
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2026-02-01 00:00:00'),
            UsageRecordRepositoryInterface::GROUP_BY_SERVICE
        );

        self::assertSame(['docs_search', 'chat'], array_column($byConsumer, 'consumer'));
        self::assertSame(['_row2', '_row1'], array_column($byService, 'service_id'));
    }

    public function test_it_lists_the_distinct_consumers_present(): void
    {
        $this->resource->insert($this->row(['consumer' => 'chat']));
        $this->resource->insert($this->row(['consumer' => 'docs_search']));
        $this->resource->insert($this->row(['consumer' => 'chat']));

        self::assertSame(['chat', 'docs_search'], $this->resource->getDistinctConsumers());
    }

    /**
     * @magentoConfigFixture default_store general/locale/timezone America/Los_Angeles
     */
    public function test_it_returns_a_per_day_series_aligned_to_store_timezone_days(): void
    {
        $this->resource->insert($this->row(['created_at' => '2026-01-15 18:00:00', 'total_tokens' => 15]));
        $this->resource->insert($this->row(['created_at' => '2026-01-16 18:00:00', 'total_tokens' => 20]));

        $series = $this->resource->seriesRange(
            new \DateTimeImmutable('2026-01-15 00:00:00', new \DateTimeZone('America/Los_Angeles')),
            new \DateTimeImmutable('2026-01-17 00:00:00', new \DateTimeZone('America/Los_Angeles')),
            UsageDailyRepositoryInterface::GRANULARITY_DAY
        );

        self::assertSame(['2026-01-15', '2026-01-16'], array_column($series, 'period'));
        self::assertSame(15, $series[0]['total_tokens']);
        self::assertSame(20, $series[1]['total_tokens']);
    }

    /**
     * @magentoConfigFixture default_store general/locale/timezone America/Los_Angeles
     */
    public function test_it_returns_a_per_month_series_aligned_to_store_timezone_months(): void
    {
        // 2026-02-01 06:00 UTC is 2026-01-31 22:00 PST: still January in the store timezone,
        // though its own calendar date is already February.
        $this->resource->insert($this->row(['created_at' => '2026-02-01 06:00:00', 'total_tokens' => 15]));
        // 2026-02-01 09:00 UTC is 2026-02-01 01:00 PST: safely February in the store timezone.
        $this->resource->insert($this->row(['created_at' => '2026-02-01 09:00:00', 'total_tokens' => 20]));

        $series = $this->resource->seriesRange(
            new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('America/Los_Angeles')),
            new \DateTimeImmutable('2026-03-01 00:00:00', new \DateTimeZone('America/Los_Angeles')),
            UsageDailyRepositoryInterface::GRANULARITY_MONTH
        );

        $totalsByPeriod = array_combine(array_column($series, 'period'), array_column($series, 'total_tokens'));
        self::assertSame(15, $totalsByPeriod['2026-01']);
        self::assertSame(20, $totalsByPeriod['2026-02']);
    }

    /**
     * @magentoConfigFixture default_store general/locale/timezone America/Los_Angeles
     */
    public function test_it_buckets_a_call_made_late_in_the_evening_into_the_store_timezone_day(): void
    {
        // 2026-01-15 23:30 PST is 2026-01-16 07:30 UTC: a later UTC calendar day than the store's
        // own local one.
        $this->resource->insert($this->row(['created_at' => '2026-01-16 07:30:00', 'total_tokens' => 15]));

        $series = $this->resource->seriesRange(
            new \DateTimeImmutable('2026-01-15 00:00:00', new \DateTimeZone('America/Los_Angeles')),
            new \DateTimeImmutable('2026-01-17 00:00:00', new \DateTimeZone('America/Los_Angeles')),
            UsageDailyRepositoryInterface::GRANULARITY_DAY
        );

        $totalsByPeriod = array_combine(array_column($series, 'period'), array_column($series, 'total_tokens'));
        self::assertSame(15, $totalsByPeriod['2026-01-15']);
        self::assertSame(0, $totalsByPeriod['2026-01-16']);
    }

    /**
     * @magentoConfigFixture default_store general/locale/timezone America/Los_Angeles
     */
    public function test_it_buckets_a_call_made_just_after_midnight_utc_into_the_previous_store_timezone_day(): void
    {
        // 2026-01-16 00:10 UTC is 2026-01-15 16:10 PST: the previous store-timezone day.
        $this->resource->insert($this->row(['created_at' => '2026-01-16 00:10:00', 'total_tokens' => 15]));

        $series = $this->resource->seriesRange(
            new \DateTimeImmutable('2026-01-15 00:00:00', new \DateTimeZone('America/Los_Angeles')),
            new \DateTimeImmutable('2026-01-17 00:00:00', new \DateTimeZone('America/Los_Angeles')),
            UsageDailyRepositoryInterface::GRANULARITY_DAY
        );

        $totalsByPeriod = array_combine(array_column($series, 'period'), array_column($series, 'total_tokens'));
        self::assertSame(15, $totalsByPeriod['2026-01-15']);
        self::assertSame(0, $totalsByPeriod['2026-01-16']);
    }

    /**
     * @magentoConfigFixture default_store general/locale/timezone Europe/Amsterdam
     */
    public function test_it_keeps_a_day_that_gains_an_hour_to_daylight_saving_as_one_bucket(): void
    {
        // 2026-03-29 is Europe/Amsterdam's spring-forward day, 23 hours long: 00:30 CET (before
        // the jump) and 22:00 CEST (after it) both still fall on that single local calendar day.
        $this->resource->insert($this->row(['created_at' => '2026-03-28 23:30:00', 'total_tokens' => 15]));
        $this->resource->insert($this->row(['created_at' => '2026-03-29 20:00:00', 'total_tokens' => 20]));

        $series = $this->resource->seriesRange(
            new \DateTimeImmutable('2026-03-29 00:00:00', new \DateTimeZone('Europe/Amsterdam')),
            new \DateTimeImmutable('2026-03-30 00:00:00', new \DateTimeZone('Europe/Amsterdam')),
            UsageDailyRepositoryInterface::GRANULARITY_DAY
        );

        self::assertCount(1, $series);
        self::assertSame('2026-03-29', $series[0]['period']);
        self::assertSame(35, $series[0]['total_tokens']);
    }

    /**
     * @magentoConfigFixture default_store general/locale/timezone UTC
     */
    public function test_it_excludes_rows_on_the_closing_boundary_of_a_bucket(): void
    {
        $this->resource->insert($this->row(['created_at' => '2026-01-15 23:59:59', 'total_tokens' => 15]));
        $this->resource->insert($this->row(['created_at' => '2026-01-16 00:00:00', 'total_tokens' => 999]));

        $series = $this->resource->seriesRange(
            new \DateTimeImmutable('2026-01-15 00:00:00', new \DateTimeZone('UTC')),
            new \DateTimeImmutable('2026-01-16 00:00:00', new \DateTimeZone('UTC')),
            UsageDailyRepositoryInterface::GRANULARITY_DAY
        );

        self::assertCount(1, $series);
        self::assertSame('2026-01-15', $series[0]['period']);
        self::assertSame(15, $series[0]['total_tokens']);
    }

    /**
     * @magentoConfigFixture default_store general/locale/timezone UTC
     */
    public function test_it_returns_an_empty_bucket_rather_than_skipping_a_day_with_no_usage(): void
    {
        $this->resource->insert($this->row(['created_at' => '2026-01-15 12:00:00', 'total_tokens' => 15]));
        $this->resource->insert($this->row(['created_at' => '2026-01-17 12:00:00', 'total_tokens' => 20]));

        $series = $this->resource->seriesRange(
            new \DateTimeImmutable('2026-01-15 00:00:00', new \DateTimeZone('UTC')),
            new \DateTimeImmutable('2026-01-18 00:00:00', new \DateTimeZone('UTC')),
            UsageDailyRepositoryInterface::GRANULARITY_DAY
        );

        self::assertSame(['2026-01-15', '2026-01-16', '2026-01-17'], array_column($series, 'period'));
        self::assertSame(0, $series[1]['calls']);
        self::assertSame(0, $series[1]['total_tokens']);
        self::assertNull($series[1]['cache_read_tokens']);
    }

    public function test_it_narrows_a_total_to_one_store(): void
    {
        $this->resource->insert($this->row(['store_id' => 1, 'total_tokens' => 15]));
        $this->resource->insert($this->row(['store_id' => 2, 'total_tokens' => 150]));

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
        $this->resource->insert($this->row(['consumer' => 'chat', 'store_id' => 1, 'total_tokens' => 15]));
        $this->resource->insert($this->row(['consumer' => 'docs_search', 'store_id' => 2, 'total_tokens' => 150]));

        $storeOne = $this->resource->groupRange(
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2026-02-01 00:00:00'),
            UsageRecordRepositoryInterface::GROUP_BY_CONSUMER,
            1
        );

        self::assertSame(['chat'], array_column($storeOne, 'consumer'));
    }

    public function test_it_narrows_a_series_to_one_store(): void
    {
        $this->resource->insert(
            $this->row(['created_at' => '2026-01-15 12:00:00', 'store_id' => 1, 'total_tokens' => 15])
        );
        $this->resource->insert(
            $this->row(['created_at' => '2026-01-15 13:00:00', 'store_id' => 2, 'total_tokens' => 150])
        );

        $storeOne = $this->resource->seriesRange(
            new \DateTimeImmutable('2026-01-15 00:00:00'),
            new \DateTimeImmutable('2026-01-16 00:00:00'),
            UsageDailyRepositoryInterface::GRANULARITY_DAY,
            1
        );

        // The series is dense over local calendar days, so the window can span more than one
        // bucket; what matters here is that store 2's 150 never lands in any of them.
        self::assertSame(15, array_sum(array_column($storeOne, 'total_tokens')));
    }

    public function test_it_returns_a_grouped_series_with_one_row_per_bucket_and_group(): void
    {
        $this->resource->insert(
            $this->row(['created_at' => '2026-01-15 12:00:00', 'consumer' => 'chat', 'total_tokens' => 15])
        );
        $this->resource->insert(
            $this->row(['created_at' => '2026-01-15 13:00:00', 'consumer' => 'docs_search', 'total_tokens' => 150])
        );
        $this->resource->insert(
            $this->row(['created_at' => '2026-01-16 12:00:00', 'consumer' => 'chat', 'total_tokens' => 7])
        );

        $series = $this->resource->seriesRangeGrouped(
            new \DateTimeImmutable('2026-01-15 00:00:00'),
            new \DateTimeImmutable('2026-01-17 00:00:00'),
            UsageDailyRepositoryInterface::GRANULARITY_DAY,
            UsageRecordRepositoryInterface::GROUP_BY_CONSUMER
        );

        $byBucketAndGroup = [];
        foreach ($series as $row) {
            $byBucketAndGroup[$row['period'] . '|' . $row['consumer']] = $row['total_tokens'];
        }

        self::assertSame(15, $byBucketAndGroup['2026-01-15|chat'] ?? null);
        self::assertSame(150, $byBucketAndGroup['2026-01-15|docs_search'] ?? null);
        self::assertSame(7, $byBucketAndGroup['2026-01-16|chat'] ?? null);
    }

    public function test_it_groups_a_series_by_service_row_as_well_as_by_consumer(): void
    {
        $this->resource->insert(
            $this->row(['created_at' => '2026-01-15 12:00:00', 'service_id' => '_row1', 'total_tokens' => 15])
        );
        $this->resource->insert(
            $this->row(['created_at' => '2026-01-15 13:00:00', 'service_id' => '_row2', 'total_tokens' => 150])
        );

        $series = $this->resource->seriesRangeGrouped(
            new \DateTimeImmutable('2026-01-15 00:00:00'),
            new \DateTimeImmutable('2026-01-16 00:00:00'),
            UsageDailyRepositoryInterface::GRANULARITY_DAY,
            UsageRecordRepositoryInterface::GROUP_BY_SERVICE
        );

        self::assertSame(['_row1', '_row2'], array_column($series, 'service_id'));
    }

    public function test_it_narrows_a_grouped_series_to_one_store(): void
    {
        $this->resource->insert(
            $this->row(['created_at' => '2026-01-15 12:00:00', 'consumer' => 'chat', 'store_id' => 1])
        );
        $this->resource->insert(
            $this->row(['created_at' => '2026-01-15 13:00:00', 'consumer' => 'docs_search', 'store_id' => 2])
        );

        $series = $this->resource->seriesRangeGrouped(
            new \DateTimeImmutable('2026-01-15 00:00:00'),
            new \DateTimeImmutable('2026-01-16 00:00:00'),
            UsageDailyRepositoryInterface::GRANULARITY_DAY,
            UsageRecordRepositoryInterface::GROUP_BY_CONSUMER,
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
                'created_at' => '2026-01-15 12:00:00',
                'service_id' => '_row1',
                'service_code' => 'anthropic',
                'model' => 'claude-sonnet',
                'consumer' => 'chat',
                'store_id' => 0,
                'input_tokens' => 10,
                'output_tokens' => 5,
                'total_tokens' => 15,
                'cache_read_tokens' => null,
                'cache_write_tokens' => null,
                'reasoning_tokens' => null,
                'streamed' => 0,
                'failed' => 0,
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
