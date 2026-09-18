<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Usage;

use MageOS\AiBase\Api\Data\Granularity;
use MageOS\AiBase\Api\UsageStatsInterface;
use MageOS\AiBase\Api\Data\Period;
use MageOS\AiBase\Api\Data\UsageBreakdownInterface;
use MageOS\AiBase\Api\UsageDailyRepositoryInterface;
use MageOS\AiBase\Api\UsageRecordRepositoryInterface;
use MageOS\AiBase\Model\Usage\UsageStats;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MageOS\AiBase\Model\Usage\UsageStats
 *
 * Exercises {@see UsageStats} against {@see FakeRawUsageRepository} and
 * {@see FakeDailyUsageRepository}, in-memory stand-ins for the two repositories it merges, per
 * this codebase's fakes-over-mocks convention. The union across a real database is proven
 * separately by `Test/Integration/Model/Usage/UsageStatsTest.php`.
 */
final class UsageStatsTest extends TestCase
{
    private FakeRawUsageRepository $rawUsageRepository;
    private FakeDailyUsageRepository $dailyUsageRepository;
    private UsageStats $subject;

    protected function setUp(): void
    {
        $this->rawUsageRepository = new FakeRawUsageRepository();
        $this->dailyUsageRepository = new FakeDailyUsageRepository();
        $this->subject = new UsageStats(
            $this->rawUsageRepository,
            $this->dailyUsageRepository,
            new FakeStatsTimezone('UTC')
        );
    }

    public function test_it_totals_token_counts_for_a_period_covered_entirely_by_raw_rows(): void
    {
        $this->rawUsageRepository->addRow($this->rawRow(['created_at' => '2026-01-10 00:00:00', 'total_tokens' => 15]));
        $this->rawUsageRepository->addRow($this->rawRow(['created_at' => '2026-01-20 00:00:00', 'total_tokens' => 30]));

        $totals = $this->subject->getTotals($this->period('2026-01-01 00:00:00', '2026-02-01 00:00:00'));

        self::assertSame(45, $totals->getTotalTokens());
    }

    public function test_it_totals_token_counts_for_a_period_covered_entirely_by_daily_rows(): void
    {
        $this->rawUsageRepository->addRow($this->rawRow(['created_at' => '2026-06-01 00:00:00', 'total_tokens' => 999]));
        $this->dailyUsageRepository->addRow($this->dailyRow(['usage_date' => '2026-01-10', 'total_tokens' => 15]));
        $this->dailyUsageRepository->addRow($this->dailyRow(['usage_date' => '2026-01-20', 'total_tokens' => 30]));

        $totals = $this->subject->getTotals($this->period('2026-01-01 00:00:00', '2026-02-01 00:00:00'));

        self::assertSame(45, $totals->getTotalTokens());
    }

    public function test_it_totals_token_counts_for_a_period_spanning_both_tables_without_double_counting(): void
    {
        $this->rawUsageRepository->addRow($this->rawRow(['created_at' => '2026-02-15 08:00:00', 'total_tokens' => 100]));
        $this->rawUsageRepository->addRow($this->rawRow(['created_at' => '2026-02-20 00:00:00', 'total_tokens' => 50]));
        $this->dailyUsageRepository->addRow($this->dailyRow(['usage_date' => '2026-01-10', 'total_tokens' => 10]));
        $this->dailyUsageRepository->addRow($this->dailyRow(['usage_date' => '2026-02-15', 'total_tokens' => 999]));

        $totals = $this->subject->getTotals($this->period('2026-01-01 00:00:00', '2026-03-01 00:00:00'));

        self::assertSame(160, $totals->getTotalTokens());
    }

    public function test_it_breaks_totals_down_by_consumer_ordered_by_tokens_descending(): void
    {
        $this->rawUsageRepository->addRow(
            $this->rawRow(['created_at' => '2026-01-10 00:00:00', 'consumer' => 'chat', 'total_tokens' => 15])
        );
        $this->dailyUsageRepository->addRow(
            $this->dailyRow(['usage_date' => '2026-01-05', 'consumer' => 'docs_search', 'total_tokens' => 150])
        );

        $breakdown = $this->subject->getByConsumer($this->period('2026-01-01 00:00:00', '2026-02-01 00:00:00'));

        self::assertSame(['docs_search', 'chat'], array_map($this->toGroupValue(...), $breakdown));
        self::assertSame(150, $breakdown[0]->getTotals()->getTotalTokens());
    }

    public function test_it_breaks_totals_down_by_service_row_ordered_by_tokens_descending(): void
    {
        $this->rawUsageRepository->addRow(
            $this->rawRow(['created_at' => '2026-01-10 00:00:00', 'service_id' => '_row1', 'total_tokens' => 15])
        );
        $this->dailyUsageRepository->addRow(
            $this->dailyRow(['usage_date' => '2026-01-05', 'service_id' => '_row2', 'total_tokens' => 150])
        );

        $breakdown = $this->subject->getByService($this->period('2026-01-01 00:00:00', '2026-02-01 00:00:00'));

        self::assertSame(['_row2', '_row1'], array_map($this->toGroupValue(...), $breakdown));
        self::assertSame(150, $breakdown[0]->getTotals()->getTotalTokens());
    }

    public function test_it_returns_a_daily_time_series_across_the_period(): void
    {
        $this->dailyUsageRepository->addRow($this->dailyRow(['usage_date' => '2026-01-05', 'total_tokens' => 10]));
        $this->dailyUsageRepository->addRow($this->dailyRow(['usage_date' => '2026-01-07', 'total_tokens' => 20]));

        $series = $this->subject->getTimeSeries(
            $this->period('2026-01-05 00:00:00', '2026-01-08 00:00:00'),
            Granularity::Day
        );

        self::assertSame(['2026-01-05', '2026-01-06', '2026-01-07'], array_map($this->toGroupValue(...), $series));
        self::assertSame(10, $series[0]->getTotals()->getTotalTokens());
        self::assertSame(20, $series[2]->getTotals()->getTotalTokens());
    }

    public function test_it_returns_a_monthly_time_series_across_the_period(): void
    {
        $this->dailyUsageRepository->addRow($this->dailyRow(['usage_date' => '2026-01-05', 'total_tokens' => 10]));
        $this->dailyUsageRepository->addRow($this->dailyRow(['usage_date' => '2026-02-05', 'total_tokens' => 20]));

        $series = $this->subject->getTimeSeries(
            $this->period('2026-01-01 00:00:00', '2026-03-01 00:00:00'),
            Granularity::Month
        );

        self::assertSame(['2026-01', '2026-02'], array_map($this->toGroupValue(...), $series));
        self::assertSame(10, $series[0]->getTotals()->getTotalTokens());
        self::assertSame(20, $series[1]->getTotals()->getTotalTokens());
    }

    public function test_it_returns_zeroed_totals_for_a_period_with_no_recorded_usage(): void
    {
        $totals = $this->subject->getTotals($this->period('2026-01-01 00:00:00', '2026-02-01 00:00:00'));

        self::assertSame(0, $totals->getCalls());
        self::assertSame(0, $totals->getInputTokens());
        self::assertSame(0, $totals->getOutputTokens());
        self::assertSame(0, $totals->getTotalTokens());
        self::assertNull($totals->getCacheReadTokens());
        self::assertNull($totals->getCacheWriteTokens());
        self::assertNull($totals->getReasoningTokens());
        self::assertSame(0, $totals->getFailedCalls());
    }

    public function test_it_fills_gaps_in_a_time_series_with_zero_rather_than_skipping_the_day(): void
    {
        $this->dailyUsageRepository->addRow($this->dailyRow(['usage_date' => '2026-01-05', 'total_tokens' => 10]));
        $this->dailyUsageRepository->addRow($this->dailyRow(['usage_date' => '2026-01-07', 'total_tokens' => 20]));

        $series = $this->subject->getTimeSeries(
            $this->period('2026-01-05 00:00:00', '2026-01-08 00:00:00'),
            Granularity::Day
        );

        self::assertCount(3, $series);
        self::assertSame('2026-01-06', $series[1]->getGroupValue());
        self::assertSame(0, $series[1]->getTotals()->getTotalTokens());
        self::assertSame(0, $series[1]->getTotals()->getCalls());
    }

    public function test_it_counts_the_number_of_calls_alongside_the_token_totals(): void
    {
        $this->rawUsageRepository->addRow($this->rawRow(['created_at' => '2026-01-10 00:00:00']));
        $this->rawUsageRepository->addRow($this->rawRow(['created_at' => '2026-01-11 00:00:00']));
        $this->dailyUsageRepository->addRow($this->dailyRow(['usage_date' => '2025-12-01', 'calls' => 4]));
        $this->rawUsageRepository->setOldestOverride('2026-01-10 00:00:00');

        $totals = $this->subject->getTotals($this->period('2025-12-01 00:00:00', '2026-02-01 00:00:00'));

        self::assertSame(6, $totals->getCalls());
    }

    public function test_it_reads_everything_from_the_daily_table_when_the_raw_table_is_empty(): void
    {
        $this->dailyUsageRepository->addRow($this->dailyRow(['usage_date' => '2026-01-10', 'total_tokens' => 15]));
        $this->dailyUsageRepository->addRow($this->dailyRow(['usage_date' => '2026-01-20', 'total_tokens' => 30]));

        $totals = $this->subject->getTotals($this->period('2026-01-01 00:00:00', '2026-02-01 00:00:00'));

        self::assertSame(45, $totals->getTotalTokens());
    }

    public function test_it_builds_the_raw_side_of_the_time_series_through_the_repository_series_method(): void
    {
        $this->rawUsageRepository->addRow($this->rawRow(['created_at' => '2026-01-10 00:00:00', 'total_tokens' => 15]));
        $this->rawUsageRepository->addRow($this->rawRow(['created_at' => '2026-01-11 00:00:00', 'total_tokens' => 20]));

        $series = $this->subject->getTimeSeries(
            $this->period('2026-01-10 00:00:00', '2026-01-12 00:00:00'),
            Granularity::Day
        );

        self::assertSame(0, $this->rawUsageRepository->getSumRangeCallCount());
        self::assertSame(['2026-01-10', '2026-01-11'], array_map($this->toGroupValue(...), $series));
        self::assertSame(15, $series[0]->getTotals()->getTotalTokens());
        self::assertSame(20, $series[1]->getTotals()->getTotalTokens());
    }

    public function test_it_aligns_the_raw_and_daily_halves_of_a_series_that_spans_both_tables(): void
    {
        $this->rawUsageRepository->addRow($this->rawRow(['created_at' => '2026-01-20 08:00:00', 'total_tokens' => 25]));
        $this->dailyUsageRepository->addRow($this->dailyRow(['usage_date' => '2026-01-05', 'total_tokens' => 10]));
        $this->rawUsageRepository->setOldestOverride('2026-01-20 00:00:00');

        $series = $this->subject->getTimeSeries(
            $this->period('2026-01-05 00:00:00', '2026-01-21 00:00:00'),
            Granularity::Day
        );

        $totalsByLabel = array_combine(
            array_map($this->toGroupValue(...), $series),
            array_map(static fn (UsageBreakdownInterface $bucket): int => $bucket->getTotals()->getTotalTokens(), $series)
        );

        self::assertSame(10, $totalsByLabel['2026-01-05']);
        self::assertSame(25, $totalsByLabel['2026-01-20']);
        self::assertSame(0, $totalsByLabel['2026-01-10']);
    }

    public function test_it_reports_failed_calls_in_the_totals(): void
    {
        $this->rawUsageRepository->addRow($this->rawRow(['created_at' => '2026-01-10 00:00:00', 'failed_calls' => 1]));
        $this->rawUsageRepository->addRow($this->rawRow(['created_at' => '2026-01-11 00:00:00', 'failed_calls' => 0]));

        $totals = $this->subject->getTotals($this->period('2026-01-01 00:00:00', '2026-02-01 00:00:00'));

        self::assertSame(1, $totals->getFailedCalls());
    }

    public function test_it_merges_failed_calls_across_raw_and_daily_rows(): void
    {
        $this->rawUsageRepository->addRow($this->rawRow(['created_at' => '2026-02-15 08:00:00', 'failed_calls' => 1]));
        $this->dailyUsageRepository->addRow($this->dailyRow(['usage_date' => '2026-01-10', 'failed_calls' => 2]));

        $totals = $this->subject->getTotals($this->period('2026-01-01 00:00:00', '2026-03-01 00:00:00'));

        self::assertSame(3, $totals->getFailedCalls());
    }

    public function test_it_reports_cache_read_and_write_in_the_totals(): void
    {
        $this->rawUsageRepository->addRow(
            $this->rawRow(['created_at' => '2026-01-10 00:00:00', 'cache_read_tokens' => 40, 'cache_write_tokens' => 12])
        );

        $totals = $this->subject->getTotals($this->period('2026-01-01 00:00:00', '2026-02-01 00:00:00'));

        self::assertSame(40, $totals->getCacheReadTokens());
        self::assertSame(12, $totals->getCacheWriteTokens());
    }

    public function test_it_keeps_cache_totals_null_when_neither_table_reported_them(): void
    {
        $this->rawUsageRepository->addRow($this->rawRow(['created_at' => '2026-02-15 08:00:00']));
        $this->dailyUsageRepository->addRow($this->dailyRow(['usage_date' => '2026-01-10']));

        $totals = $this->subject->getTotals($this->period('2026-01-01 00:00:00', '2026-03-01 00:00:00'));

        self::assertNull($totals->getCacheReadTokens());
        self::assertNull($totals->getCacheWriteTokens());
    }

    public function test_it_reports_failed_calls_per_breakdown_row(): void
    {
        $this->rawUsageRepository->addRow(
            $this->rawRow(['created_at' => '2026-01-10 00:00:00', 'consumer' => 'chat', 'failed_calls' => 1])
        );
        $this->rawUsageRepository->addRow(
            $this->rawRow(['created_at' => '2026-01-11 00:00:00', 'consumer' => 'docs_search', 'failed_calls' => 0])
        );

        $breakdown = $this->subject->getByConsumer($this->period('2026-01-01 00:00:00', '2026-02-01 00:00:00'));

        $failedCallsByConsumer = array_combine(
            array_map($this->toGroupValue(...), $breakdown),
            array_map(static fn (UsageBreakdownInterface $row): int => $row->getTotals()->getFailedCalls(), $breakdown)
        );

        self::assertSame(1, $failedCallsByConsumer['chat']);
        self::assertSame(0, $failedCallsByConsumer['docs_search']);
    }

    private function period(string $start, string $end): Period
    {
        return Period::between(
            new \DateTimeImmutable($start, new \DateTimeZone('UTC')),
            new \DateTimeImmutable($end, new \DateTimeZone('UTC'))
        );
    }

    private function toGroupValue(UsageBreakdownInterface $breakdown): string
    {
        return $breakdown->getGroupValue();
    }

    /**
     * @param array<string,int|string|null> $overrides
     * @return array<string,int|string|null>
     */
    private function rawRow(array $overrides = []): array
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
                'failed_calls' => 0,
            ],
            $overrides
        );
    }

    /**
     * @param array<string,int|string|null> $overrides
     * @return array<string,int|string|null>
     */
    private function dailyRow(array $overrides = []): array
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
                'cache_read_tokens' => null,
                'cache_write_tokens' => null,
                'reasoning_tokens' => null,
                'failed_calls' => 0,
            ],
            $overrides
        );
    }

    public function test_it_returns_one_series_per_service_row(): void
    {
        $this->rawUsageRepository->addRow($this->rawRow([
            'created_at' => '2026-01-01 10:00:00', 'service_id' => 'row-a', 'total_tokens' => 40,
        ]));
        $this->rawUsageRepository->addRow($this->rawRow([
            'created_at' => '2026-01-01 10:00:00', 'service_id' => 'row-b', 'total_tokens' => 10,
        ]));

        $series = $this->subject->getTimeSeriesByService(
            $this->period('2026-01-01 00:00:00', '2026-01-02 00:00:00'),
            Granularity::Day,
            5
        );

        self::assertSame(['row-a', 'row-b'], array_keys($series));
        self::assertSame(40, $series['row-a'][0]->getTotals()->getTotalTokens());
    }

    public function test_it_asks_the_daily_table_for_store_local_dates_not_utc_instants(): void
    {
        $subject = new UsageStats(
            $this->rawUsageRepository,
            $this->dailyUsageRepository,
            new FakeStatsTimezone('Europe/Amsterdam')
        );

        // Local 1 January in Amsterdam is 23:00 UTC on 31 December. `usage_date` stores the local
        // calendar date, so a window opened on the raw UTC instant would query from the 31st and
        // sweep in the previous December's bucket.
        $subject->getTotals($this->period('2025-12-31 23:00:00', '2026-12-31 23:00:00'));

        self::assertSame('2026-01-01', $this->dailyUsageRepository->lastSumFrom());
    }

    public function test_it_returns_one_dense_series_per_consumer(): void
    {
        $this->rawUsageRepository->addRow($this->rawRow([
            'created_at' => '2026-01-01 10:00:00', 'consumer' => 'chat', 'total_tokens' => 10,
        ]));
        $this->rawUsageRepository->addRow($this->rawRow([
            'created_at' => '2026-01-03 10:00:00', 'consumer' => 'chat', 'total_tokens' => 30,
        ]));
        $this->rawUsageRepository->addRow($this->rawRow([
            'created_at' => '2026-01-01 10:00:00', 'consumer' => 'docs', 'total_tokens' => 5,
        ]));

        $series = $this->subject->getTimeSeriesByConsumer(
            $this->period('2026-01-01 00:00:00', '2026-01-04 00:00:00'),
            Granularity::Day,
            5
        );

        self::assertSame(['chat', 'docs'], array_keys($series));
        // Dense: the second of January has no rows for either consumer and still gets a bucket.
        self::assertCount(3, $series['chat']);
        self::assertCount(3, $series['docs']);
        self::assertSame(0, $series['chat'][1]->getTotals()->getTotalTokens());
        self::assertSame(30, $series['chat'][2]->getTotals()->getTotalTokens());
    }

    public function test_it_folds_consumers_past_the_limit_into_one_other_series(): void
    {
        foreach (['a' => 100, 'b' => 50, 'c' => 10, 'd' => 5] as $consumer => $tokens) {
            $this->rawUsageRepository->addRow($this->rawRow([
                'created_at' => '2026-01-01 10:00:00', 'consumer' => $consumer, 'total_tokens' => $tokens,
            ]));
        }

        $series = $this->subject->getTimeSeriesByConsumer(
            $this->period('2026-01-01 00:00:00', '2026-01-02 00:00:00'),
            Granularity::Day,
            2
        );

        self::assertSame(['a', 'b', UsageStatsInterface::SERIES_OTHER], array_keys($series));
        self::assertSame(15, $series[UsageStatsInterface::SERIES_OTHER][0]->getTotals()->getTotalTokens());
    }

    public function test_it_ranks_series_over_the_whole_period_not_per_bucket(): void
    {
        // "steady" leads overall; "spiky" beats it on the second day alone. Ranking per bucket
        // would move one of them in and out of Other partway along the chart.
        $this->rawUsageRepository->addRow($this->rawRow([
            'created_at' => '2026-01-01 10:00:00', 'consumer' => 'steady', 'total_tokens' => 100,
        ]));
        $this->rawUsageRepository->addRow($this->rawRow([
            'created_at' => '2026-01-02 10:00:00', 'consumer' => 'spiky', 'total_tokens' => 60,
        ]));
        $this->rawUsageRepository->addRow($this->rawRow([
            'created_at' => '2026-01-02 10:00:00', 'consumer' => 'steady', 'total_tokens' => 5,
        ]));

        $series = $this->subject->getTimeSeriesByConsumer(
            $this->period('2026-01-01 00:00:00', '2026-01-03 00:00:00'),
            Granularity::Day,
            1
        );

        self::assertSame(['steady', UsageStatsInterface::SERIES_OTHER], array_keys($series));
    }

    public function test_it_counts_the_end_day_of_a_period_that_stops_mid_day(): void
    {
        // The dashboard's change badge compares against a previous window that deliberately ends
        // mid-day. The daily table only stores whole days and the raw table no longer holds the
        // rows, so the end day counts in full rather than being dropped: dropping it made flat
        // usage read as a large increase.
        $this->rawUsageRepository->setOldestOverride('2026-01-04 00:00:00');
        $this->dailyUsageRepository->addRow($this->dailyRow(['usage_date' => '2026-01-01', 'total_tokens' => 1000]));
        $this->dailyUsageRepository->addRow($this->dailyRow(['usage_date' => '2026-01-02', 'total_tokens' => 1000]));
        $this->dailyUsageRepository->addRow($this->dailyRow(['usage_date' => '2026-01-03', 'total_tokens' => 1000]));

        $totals = $this->subject->getTotals($this->period('2026-01-01 00:00:00', '2026-01-03 20:00:00'));

        self::assertSame(3000, $totals->getTotalTokens());
    }

    public function test_it_still_stops_the_daily_half_before_the_day_the_raw_table_owns(): void
    {
        // Rounding the period end up must not reach past the raw handover: the day holding the
        // oldest raw row is answered from the raw side alone, or it would be counted twice.
        $this->rawUsageRepository->setOldestOverride('2026-01-03 09:15:00');
        $this->rawUsageRepository->addRow($this->rawRow(['created_at' => '2026-01-03 09:15:00', 'total_tokens' => 7]));
        $this->dailyUsageRepository->addRow($this->dailyRow(['usage_date' => '2026-01-01', 'total_tokens' => 1000]));
        $this->dailyUsageRepository->addRow($this->dailyRow(['usage_date' => '2026-01-03', 'total_tokens' => 9999]));

        $totals = $this->subject->getTotals($this->period('2026-01-01 00:00:00', '2026-01-03 20:00:00'));

        self::assertSame(1007, $totals->getTotalTokens());
    }

    public function test_it_narrows_totals_to_one_store(): void
    {
        $this->rawUsageRepository->addRow($this->rawRow([
            'created_at' => '2026-01-10 00:00:00', 'store_id' => 1, 'total_tokens' => 15,
        ]));
        $this->rawUsageRepository->addRow($this->rawRow([
            'created_at' => '2026-01-11 00:00:00', 'store_id' => 2, 'total_tokens' => 30,
        ]));

        $totals = $this->subject->getTotals($this->period('2026-01-01 00:00:00', '2026-02-01 00:00:00'), 1);

        self::assertSame(15, $totals->getTotalTokens());
    }

    public function test_it_totals_every_store_when_no_store_was_asked_for(): void
    {
        $this->rawUsageRepository->addRow($this->rawRow([
            'created_at' => '2026-01-10 00:00:00', 'store_id' => 1, 'total_tokens' => 15,
        ]));
        $this->rawUsageRepository->addRow($this->rawRow([
            'created_at' => '2026-01-11 00:00:00', 'store_id' => 2, 'total_tokens' => 30,
        ]));

        $totals = $this->subject->getTotals($this->period('2026-01-01 00:00:00', '2026-02-01 00:00:00'));

        self::assertSame(45, $totals->getTotalTokens());
    }

    public function test_it_narrows_a_store_scoped_total_across_both_tables(): void
    {
        // The store filter has to reach both halves of the union: a filter applied to only one of
        // them still returns a number, which is exactly the bug that would go unnoticed.
        $this->rawUsageRepository->addRow($this->rawRow([
            'created_at' => '2026-02-15 08:00:00', 'store_id' => 1, 'total_tokens' => 100,
        ]));
        $this->rawUsageRepository->addRow($this->rawRow([
            'created_at' => '2026-02-15 09:00:00', 'store_id' => 2, 'total_tokens' => 700,
        ]));
        $this->dailyUsageRepository->addRow($this->dailyRow([
            'usage_date' => '2026-01-10', 'store_id' => 1, 'total_tokens' => 10,
        ]));
        $this->dailyUsageRepository->addRow($this->dailyRow([
            'usage_date' => '2026-01-11', 'store_id' => 2, 'total_tokens' => 900,
        ]));

        $totals = $this->subject->getTotals($this->period('2026-01-01 00:00:00', '2026-03-01 00:00:00'), 1);

        self::assertSame(110, $totals->getTotalTokens());
    }

    public function test_it_narrows_the_consumer_breakdown_to_one_store(): void
    {
        $this->rawUsageRepository->addRow($this->rawRow([
            'created_at' => '2026-01-10 00:00:00', 'consumer' => 'chat', 'store_id' => 1, 'total_tokens' => 15,
        ]));
        $this->rawUsageRepository->addRow($this->rawRow([
            'created_at' => '2026-01-11 00:00:00', 'consumer' => 'search', 'store_id' => 2, 'total_tokens' => 30,
        ]));

        $rows = $this->subject->getByConsumer($this->period('2026-01-01 00:00:00', '2026-02-01 00:00:00'), 1);

        self::assertSame(
            ['chat'],
            array_map(static fn (UsageBreakdownInterface $row): string => $row->getGroupValue(), $rows)
        );
    }

    public function test_it_narrows_the_service_breakdown_to_one_store(): void
    {
        $this->rawUsageRepository->addRow($this->rawRow([
            'created_at' => '2026-01-10 00:00:00', 'service_id' => '_row1', 'store_id' => 1, 'total_tokens' => 15,
        ]));
        $this->rawUsageRepository->addRow($this->rawRow([
            'created_at' => '2026-01-11 00:00:00', 'service_id' => '_row2', 'store_id' => 2, 'total_tokens' => 30,
        ]));

        $rows = $this->subject->getByService($this->period('2026-01-01 00:00:00', '2026-02-01 00:00:00'), 1);

        self::assertSame(
            ['_row1'],
            array_map(static fn (UsageBreakdownInterface $row): string => $row->getGroupValue(), $rows)
        );
    }

    public function test_it_narrows_the_trend_to_one_store(): void
    {
        $this->rawUsageRepository->addRow($this->rawRow([
            'created_at' => '2026-01-01 10:00:00', 'store_id' => 1, 'total_tokens' => 15,
        ]));
        $this->rawUsageRepository->addRow($this->rawRow([
            'created_at' => '2026-01-01 11:00:00', 'store_id' => 2, 'total_tokens' => 900,
        ]));

        $series = $this->subject->getTimeSeries(
            $this->period('2026-01-01 00:00:00', '2026-01-02 00:00:00'),
            Granularity::Day,
            1
        );

        self::assertSame(
            [15],
            array_map(static fn (UsageBreakdownInterface $point): int => $point->getTotals()->getTotalTokens(), $series)
        );
    }

    public function test_it_narrows_the_per_consumer_trend_to_one_store(): void
    {
        $this->rawUsageRepository->addRow($this->rawRow([
            'created_at' => '2026-01-01 10:00:00', 'consumer' => 'chat', 'store_id' => 1, 'total_tokens' => 15,
        ]));
        $this->rawUsageRepository->addRow($this->rawRow([
            'created_at' => '2026-01-01 11:00:00', 'consumer' => 'search', 'store_id' => 2, 'total_tokens' => 900,
        ]));

        $series = $this->subject->getTimeSeriesByConsumer(
            $this->period('2026-01-01 00:00:00', '2026-01-02 00:00:00'),
            Granularity::Day,
            5,
            1
        );

        self::assertSame(['chat'], array_keys($series));
    }

}

/**
 * In-memory stand-in for {@see UsageRecordRepositoryInterface}. Rows are added directly through
 * {@see addRow()} with an explicit `created_at` string, giving a test full control over historical
 * timestamps the real repository would leave to the database.
 *
 * `save()`, `getList()`, `deleteOlderThan()`, `aggregateRange()` and `getDistinctConsumers()` are
 * not exercised by {@see UsageStats} and throw, so a test that accidentally depends on one of them
 * fails loudly instead of silently returning a meaningless default.
 */
final class FakeRawUsageRepository implements UsageRecordRepositoryInterface
{
    /**
     * @var array<int,array<string,int|string|null>>
     */
    private array $rows = [];

    /**
     * Forces {@see getOldestRecordedAt()} to a value a test picks, rather than the earliest added
     * row: lets a test prove the raw/daily boundary without needing the added rows themselves to
     * be the earliest thing recorded.
     */
    private ?string $oldestOverride = null;

    /**
     * Counts {@see sumRange()} calls, so a test can prove
     * {@see \MageOS\AiBase\Model\Usage\UsageStats::getTimeSeries()} builds the raw side of a
     * series through {@see seriesRange()} rather than by looping this method per bucket, the way
     * task 013 originally had to before this repository grew its own `seriesRange()`.
     */
    private int $sumRangeCallCount = 0;

    /**
     * @param array<string,int|string|null> $row
     */
    public function addRow(array $row): void
    {
        $this->rows[] = $row;
    }

    public function setOldestOverride(string $createdAt): void
    {
        $this->oldestOverride = $createdAt;
    }

    public function getSumRangeCallCount(): int
    {
        return $this->sumRangeCallCount;
    }

    public function save(\MageOS\AiBase\Api\Data\UsageRecordInterface $record): void
    {
        throw new \LogicException('Not needed by UsageStatsTest.');
    }

    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface
    {
        throw new \LogicException('Not needed by UsageStatsTest.');
    }

    public function deleteOlderThan(\DateTimeInterface $cutoff): int
    {
        throw new \LogicException('Not needed by UsageStatsTest.');
    }

    public function getOldestRecordedAt(): ?\DateTimeImmutable
    {
        if ($this->oldestOverride !== null) {
            return new \DateTimeImmutable($this->oldestOverride, new \DateTimeZone('UTC'));
        }

        if ($this->rows === []) {
            return null;
        }

        $oldest = min(array_map(fn (array $row): string => (string) $row['created_at'], $this->rows));

        return new \DateTimeImmutable($oldest, new \DateTimeZone('UTC'));
    }

    public function aggregateRange(\DateTimeInterface $from, \DateTimeInterface $to, string $usageDate): array
    {
        throw new \LogicException('Not needed by UsageStatsTest.');
    }

    public function sumRange(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        ?string $consumer = null,
        ?int $storeId = null
    ): array {
        $this->sumRangeCallCount++;
        $rows = $this->rowsInWindow($from, $to, $storeId);
        if ($consumer !== null) {
            $rows = array_filter($rows, fn (array $row): bool => $row['consumer'] === $consumer);
        }

        return $this->totals($rows);
    }

    public function groupRange(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        string $groupBy,
        ?int $storeId = null
    ): array {
        $groups = [];
        foreach ($this->rowsInWindow($from, $to, $storeId) as $row) {
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

    public function getDistinctConsumers(): array
    {
        throw new \LogicException('Not needed by UsageStatsTest.');
    }

    /**
     * Mirrors {@see \MageOS\AiBase\Model\ResourceModel\Usage\UsageLog::seriesRange()}'s dense
     * bucketing (every bucket in the window, including an empty one) in plain UTC, since this fake
     * proves {@see \MageOS\AiBase\Model\Usage\UsageStats}'s orchestration, not the store-timezone
     * arithmetic itself — that is proven separately by
     * `Test/Integration/Model/Usage/UsageLogTest.php`.
     */
    public function seriesRange(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        string $granularity,
        ?int $storeId = null
    ): array {
        $isMonthly = $granularity === UsageDailyRepositoryInterface::GRANULARITY_MONTH;
        $labelFormat = $isMonthly ? 'Y-m' : 'Y-m-d';
        $stepModifier = $isMonthly ? '+1 month' : '+1 day';

        $cursor = $isMonthly
            ? \DateTimeImmutable::createFromInterface($from)->modify('first day of this month')->setTime(0, 0)
            : \DateTimeImmutable::createFromInterface($from)->setTime(0, 0);
        $end = \DateTimeImmutable::createFromInterface($to);

        $buckets = [];
        while ($cursor < $end) {
            $bucketEnd = $cursor->modify($stepModifier);
            $buckets[] = array_merge(
                ['period' => $cursor->format($labelFormat)],
                $this->totals($this->rowsInWindow($cursor, $bucketEnd, $storeId))
            );
            $cursor = $bucketEnd;
        }

        return $buckets;
    }

    /**
     * @return array<int,array<string,int|string|null>>
     */
    private function rowsInWindow(\DateTimeInterface $from, \DateTimeInterface $to, ?int $storeId = null): array
    {
        $fromString = $from->format('Y-m-d H:i:s');
        $toString = $to->format('Y-m-d H:i:s');

        return array_values(array_filter(
            $this->rows,
            fn (array $row): bool => (string) $row['created_at'] >= $fromString
                && (string) $row['created_at'] < $toString
                && ($storeId === null || (int) ($row['store_id'] ?? 0) === $storeId)
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
            'failed_calls' => 0,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'cache_read_tokens' => null,
            'cache_write_tokens' => null,
            'reasoning_tokens' => null,
        ];

        foreach ($rows as $row) {
            $totals['calls']++;
            $totals['failed_calls'] += (int) $row['failed_calls'];
            $totals['input_tokens'] += (int) $row['input_tokens'];
            $totals['output_tokens'] += (int) $row['output_tokens'];
            $totals['total_tokens'] += (int) $row['total_tokens'];
            if ($row['cache_read_tokens'] !== null) {
                $totals['cache_read_tokens'] = ($totals['cache_read_tokens'] ?? 0) + (int) $row['cache_read_tokens'];
            }
            if ($row['cache_write_tokens'] !== null) {
                $totals['cache_write_tokens'] = ($totals['cache_write_tokens'] ?? 0) + (int) $row['cache_write_tokens'];
            }
            if ($row['reasoning_tokens'] !== null) {
                $totals['reasoning_tokens'] = ($totals['reasoning_tokens'] ?? 0) + (int) $row['reasoning_tokens'];
            }
        }

        return $totals;
    }

    /**
     * The same bucketing as {@see seriesRange()}, split again by a grouping column, so a test can
     * assert on what the stats layer does with a real per-group series rather than an empty one.
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
        $isMonthly = $granularity === UsageDailyRepositoryInterface::GRANULARITY_MONTH;
        $labelFormat = $isMonthly ? 'Y-m' : 'Y-m-d';
        $stepModifier = $isMonthly ? '+1 month' : '+1 day';

        $cursor = $isMonthly
            ? \DateTimeImmutable::createFromInterface($from)->modify('first day of this month')->setTime(0, 0)
            : \DateTimeImmutable::createFromInterface($from)->setTime(0, 0);
        $end = \DateTimeImmutable::createFromInterface($to);

        $rows = [];
        while ($cursor < $end) {
            $bucketEnd = $cursor->modify($stepModifier);
            $grouped = [];
            foreach ($this->rowsInWindow($cursor, $bucketEnd, $storeId) as $row) {
                $grouped[(string) $row[$groupBy]][] = $row;
            }
            foreach ($grouped as $group => $groupRows) {
                $rows[] = array_merge(
                    ['period' => $cursor->format($labelFormat), $groupBy => $group],
                    $this->totals($groupRows)
                );
            }
            $cursor = $bucketEnd;
        }

        return $rows;
    }
}

/**
 * In-memory stand-in for {@see UsageDailyRepositoryInterface}. Window filtering compares
 * `usage_date` strings the same way {@see \MageOS\AiBase\Model\ResourceModel\Usage\UsageDaily}
 * does: only the date portion of `$from`/`$to` is read, never the time.
 *
 * `getList()`, `saveAggregates()` and `deleteOlderThan()` are not exercised by {@see UsageStats}
 * and throw.
 */
final class FakeDailyUsageRepository implements UsageDailyRepositoryInterface
{
    private ?string $lastSumFrom = null;

    /**
     * The `Y-m-d` the last totals window opened on — the value the real resource model would have
     * put into its `usage_date >= ?` predicate.
     */
    public function lastSumFrom(): ?string
    {
        return $this->lastSumFrom;
    }

    /**
     * @var array<int,array<string,int|string|null>>
     */
    private array $rows = [];

    /**
     * @param array<string,int|string|null> $row
     */
    public function addRow(array $row): void
    {
        $this->rows[] = $row;
    }

    public function saveAggregates(array $rows): void
    {
        throw new \LogicException('Not needed by UsageStatsTest.');
    }

    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface
    {
        throw new \LogicException('Not needed by UsageStatsTest.');
    }

    public function deleteOlderThan(\DateTimeInterface $cutoff): int
    {
        throw new \LogicException('Not needed by UsageStatsTest.');
    }

    public function sumRange(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        ?string $consumer = null,
        ?int $storeId = null
    ): array {
        $this->lastSumFrom = $from->format('Y-m-d');
        $rows = $this->rowsInWindow($from, $to, $storeId);
        if ($consumer !== null) {
            $rows = array_filter($rows, fn (array $row): bool => $row['consumer'] === $consumer);
        }

        return $this->totals($rows);
    }

    public function groupRange(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        string $groupBy,
        ?int $storeId = null
    ): array {
        $groups = [];
        foreach ($this->rowsInWindow($from, $to, $storeId) as $row) {
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

    public function seriesRange(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        string $granularity,
        ?int $storeId = null
    ): array {
        $buckets = [];
        foreach ($this->rowsInWindow($from, $to, $storeId) as $row) {
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
     * @return array<int,array<string,int|string|null>>
     */
    private function rowsInWindow(\DateTimeInterface $from, \DateTimeInterface $to, ?int $storeId = null): array
    {
        $fromDate = $from->format('Y-m-d');
        $toDate = $to->format('Y-m-d');

        return array_values(array_filter(
            $this->rows,
            fn (array $row): bool => (string) $row['usage_date'] >= $fromDate
                && (string) $row['usage_date'] < $toDate
                && ($storeId === null || (int) ($row['store_id'] ?? 0) === $storeId)
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
            'failed_calls' => 0,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'cache_read_tokens' => null,
            'cache_write_tokens' => null,
            'reasoning_tokens' => null,
        ];

        foreach ($rows as $row) {
            $totals['calls'] += (int) $row['calls'];
            $totals['failed_calls'] += (int) $row['failed_calls'];
            $totals['input_tokens'] += (int) $row['input_tokens'];
            $totals['output_tokens'] += (int) $row['output_tokens'];
            $totals['total_tokens'] += (int) $row['total_tokens'];
            if ($row['cache_read_tokens'] !== null) {
                $totals['cache_read_tokens'] = ($totals['cache_read_tokens'] ?? 0) + (int) $row['cache_read_tokens'];
            }
            if ($row['cache_write_tokens'] !== null) {
                $totals['cache_write_tokens'] = ($totals['cache_write_tokens'] ?? 0) + (int) $row['cache_write_tokens'];
            }
            if ($row['reasoning_tokens'] !== null) {
                $totals['reasoning_tokens'] = ($totals['reasoning_tokens'] ?? 0) + (int) $row['reasoning_tokens'];
            }
        }

        return $totals;
    }

    /**
     * The daily table's own grouped series: one row per (bucket, group) pair present.
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
        $isMonthly = $granularity === UsageDailyRepositoryInterface::GRANULARITY_MONTH;
        $labelFormat = $isMonthly ? 'Y-m' : 'Y-m-d';

        $grouped = [];
        foreach ($this->rowsInWindow($from, $to, $storeId) as $row) {
            $bucket = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $row['usage_date']);
            $label = $bucket === false ? (string) $row['usage_date'] : $bucket->format($labelFormat);
            $grouped[$label . '|' . (string) $row[$groupBy]][] = $row;
        }

        $rows = [];
        foreach ($grouped as $key => $groupRows) {
            [$label, $group] = explode('|', $key, 2);
            $rows[] = array_merge(['period' => $label, $groupBy => $group], $this->totals($groupRows));
        }

        return $rows;
    }
}

/**
 * Store timezone stand-in. Only `getConfigTimezone()` is consulted: {@see UsageStats} converts the
 * daily table's window bounds into the store's own timezone before formatting them as dates.
 */
final class FakeStatsTimezone implements \Magento\Framework\Stdlib\DateTime\TimezoneInterface
{
    public function __construct(private readonly string $timezone)
    {
    }

    public function getConfigTimezone($scopeType = null, $scopeCode = null)
    {
        return $this->timezone;
    }

    public function getDefaultTimezonePath()
    {
        return 'general/locale/timezone';
    }

    public function getDefaultTimezone()
    {
        return $this->timezone;
    }

    public function getConfigLocale($scopeType = null, $scopeCode = null)
    {
        return 'en_US';
    }

    public function getDateFormat($type = null, $showTime = false)
    {
        return 'Y-m-d';
    }

    public function getDateFormatWithLongYear()
    {
        return 'Y-m-d';
    }

    public function getTimeFormat($type = null)
    {
        return 'H:i:s';
    }

    public function getDateTimeFormat($type)
    {
        return 'Y-m-d H:i:s';
    }

    public function date($date = null, $locale = null, $useTimezone = true, $includeTime = true)
    {
        return new \DateTime('now', new \DateTimeZone($this->timezone));
    }

    public function scopeDate($scope = null, $date = null, $includeTime = false)
    {
        return new \DateTime('now', new \DateTimeZone($this->timezone));
    }

    public function formatDate($date = null, $format = 3, $showTime = false)
    {
        return '';
    }

    public function formatDateTime(
        $date,
        $dateType = 3,
        $timeType = 3,
        $locale = null,
        $timezone = null,
        $pattern = null
    ) {
        return '';
    }

    public function convertConfigTimeToUtc($date, $format = 'Y-m-d H:i:s')
    {
        return '';
    }

    public function scopeTimeStamp($scope = null)
    {
        return 0;
    }

    public function isScopeDateInInterval($scope, $dateFrom = null, $dateTo = null)
    {
        return false;
    }

    public function scopeConfigTimezone($scope = null)
    {
        return $this->timezone;
    }
}
