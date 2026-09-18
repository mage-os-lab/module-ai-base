<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Usage;

require_once __DIR__ . '/../../Stubs/UsageLogCollectionFactoryStub.php';
require_once __DIR__ . '/../../Stubs/SearchResultsInterfaceFactoryStub.php';

use MageOS\AiBase\Api\UsageRecordRepositoryInterface;
use MageOS\AiBase\Model\ResourceModel\Usage\UsageLog\CollectionFactory;
use MageOS\AiBase\Model\ResourceModel\Usage\UsageLogResourceInterface;
use MageOS\AiBase\Model\Usage\UsageRecord;
use MageOS\AiBase\Model\Usage\UsageRecordRepository;
use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchResultsInterfaceFactory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MageOS\AiBase\Model\Usage\UsageRecordRepository
 *
 * Exercises the repository against {@see FakeUsageLogResource}, an in-memory stand-in for
 * {@see \MageOS\AiBase\Model\ResourceModel\Usage\UsageLog}. `Magento\Framework\DB\Adapter\
 * AdapterInterface` has over a hundred methods and is not realistically fakeable, which is why the
 * behaviour under test here is proven twice: the fake mirrors the resource model's contract
 * closely enough to drive these tests honestly, and
 * `Test/Integration/Model/Usage/UsageLogTest.php` proves the same behaviour against a real
 * database, which is the only thing that can actually prove the SQL.
 *
 * `getList()` is deliberately not exercised here: it only assembles Magento's collection/search
 * criteria machinery, which needs a real database connection to mean anything, so it is covered by
 * `Test/Integration/Model/Usage/UsageRecordRepositoryTest.php` instead.
 */
final class UsageRecordRepositoryTest extends TestCase
{
    private FakeUsageLogResource $resource;
    private UsageRecordRepository $subject;

    protected function setUp(): void
    {
        $this->resource = new FakeUsageLogResource();
        $this->subject = new UsageRecordRepository(
            $this->resource,
            $this->createMock(CollectionFactory::class),
            $this->createMock(SearchResultsInterfaceFactory::class),
            $this->createMock(CollectionProcessorInterface::class),
        );
    }

    public function test_it_saves_a_usage_record_and_assigns_it_an_id(): void
    {
        $this->subject->save($this->record());

        $stored = $this->resource->getStoredRows();
        self::assertCount(1, $stored);
        self::assertSame(1, $stored[0]['entity_id']);
    }

    public function test_it_maps_every_token_count_onto_the_stored_row(): void
    {
        $this->subject->save($this->record([
            'inputTokens' => 10,
            'outputTokens' => 5,
            'totalTokens' => 15,
            'cacheReadTokens' => 3,
            'reasoningTokens' => 2,
        ]));

        $stored = $this->resource->getStoredRows();
        self::assertSame(10, $stored[0]['input_tokens']);
        self::assertSame(5, $stored[0]['output_tokens']);
        self::assertSame(15, $stored[0]['total_tokens']);
        self::assertSame(3, $stored[0]['cache_read_tokens']);
        self::assertSame(2, $stored[0]['reasoning_tokens']);
    }

    public function test_it_writes_cache_split_failed_flag_and_null_tokens_to_the_row(): void
    {
        $this->subject->save($this->record([
            'inputTokens' => null,
            'outputTokens' => null,
            'totalTokens' => null,
            'cacheReadTokens' => 7,
            'cacheWriteTokens' => 4,
            'failed' => true,
        ]));

        $stored = $this->resource->getStoredRows();
        self::assertNull($stored[0]['input_tokens']);
        self::assertNull($stored[0]['output_tokens']);
        self::assertNull($stored[0]['total_tokens']);
        self::assertSame(7, $stored[0]['cache_read_tokens']);
        self::assertSame(4, $stored[0]['cache_write_tokens']);
        self::assertSame(1, $stored[0]['failed']);
    }

    public function test_it_deletes_only_records_older_than_the_given_cutoff(): void
    {
        $this->resource->insert($this->row(['created_at' => '2026-01-01 00:00:00']));
        $this->resource->insert($this->row(['created_at' => '2026-06-01 00:00:00']));

        $deleted = $this->subject->deleteOlderThan(new \DateTimeImmutable('2026-02-01 00:00:00'));

        self::assertSame(1, $deleted);
        $remaining = $this->resource->getStoredRows();
        self::assertCount(1, $remaining);
        self::assertSame('2026-06-01 00:00:00', $remaining[0]['created_at']);
    }

    public function test_it_returns_the_number_of_records_deleted(): void
    {
        $this->resource->insert($this->row(['created_at' => '2026-01-01 00:00:00']));
        $this->resource->insert($this->row(['created_at' => '2026-01-02 00:00:00']));
        $this->resource->insert($this->row(['created_at' => '2026-06-01 00:00:00']));

        $deleted = $this->subject->deleteOlderThan(new \DateTimeImmutable('2026-02-01 00:00:00'));

        self::assertSame(2, $deleted);
    }

    public function test_it_deletes_in_batches_rather_than_a_single_unbounded_statement(): void
    {
        $this->resource->setDeleteBatchLimitOverride(2);
        $this->resource->insert($this->row(['created_at' => '2026-01-01 00:00:00']));
        $this->resource->insert($this->row(['created_at' => '2026-01-02 00:00:00']));
        $this->resource->insert($this->row(['created_at' => '2026-01-03 00:00:00']));

        $deleted = $this->subject->deleteOlderThan(new \DateTimeImmutable('2026-02-01 00:00:00'));

        self::assertSame(3, $deleted);
        self::assertGreaterThan(1, $this->resource->getDeleteBatchCallCount());
    }

    public function test_it_reports_the_oldest_recorded_timestamp(): void
    {
        $this->resource->insert($this->row(['created_at' => '2026-03-01 00:00:00']));
        $this->resource->insert($this->row(['created_at' => '2026-01-01 00:00:00']));
        $this->resource->insert($this->row(['created_at' => '2026-02-01 00:00:00']));

        $oldest = $this->subject->getOldestRecordedAt();

        self::assertInstanceOf(\DateTimeImmutable::class, $oldest);
        self::assertSame('2026-01-01 00:00:00', $oldest->format('Y-m-d H:i:s'));
    }

    public function test_it_reports_no_oldest_timestamp_when_nothing_has_been_recorded(): void
    {
        self::assertNull($this->subject->getOldestRecordedAt());
    }

    public function test_it_aggregates_a_time_window_by_service_row_model_consumer_and_store(): void
    {
        $this->resource->insert($this->row([
            'created_at' => '2026-01-10 00:00:00',
            'service_id' => '_row1',
            'model' => 'claude-sonnet',
            'consumer' => 'chat',
            'store_id' => 0,
            'total_tokens' => 15,
        ]));
        $this->resource->insert($this->row([
            'created_at' => '2026-01-11 00:00:00',
            'service_id' => '_row2',
            'model' => 'gpt-5',
            'consumer' => 'docs_search',
            'store_id' => 1,
            'total_tokens' => 30,
        ]));

        $rows = $this->subject->aggregateRange(
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2026-02-01 00:00:00'),
            '2026-01'
        );

        self::assertCount(2, $rows);
        self::assertSame(['_row1', '_row2'], array_column($rows, 'service_id'));
        self::assertSame(['claude-sonnet', 'gpt-5'], array_column($rows, 'model'));
        self::assertSame(['chat', 'docs_search'], array_column($rows, 'consumer'));
        self::assertSame([0, 1], array_column($rows, 'store_id'));
        self::assertSame(['2026-01', '2026-01'], array_column($rows, 'usage_date'));
    }

    public function test_it_counts_the_calls_in_each_aggregated_group(): void
    {
        $this->resource->insert($this->row(['created_at' => '2026-01-10 00:00:00', 'consumer' => 'chat']));
        $this->resource->insert($this->row(['created_at' => '2026-01-11 00:00:00', 'consumer' => 'chat']));
        $this->resource->insert($this->row(['created_at' => '2026-01-12 00:00:00', 'consumer' => 'docs_search']));

        $rows = $this->subject->aggregateRange(
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

        $totals = $this->subject->sumRange(
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2026-02-01 00:00:00')
        );

        self::assertSame(1, $totals['calls']);
    }

    public function test_it_totals_a_window_optionally_narrowed_to_one_consumer(): void
    {
        $this->resource->insert($this->row(['consumer' => 'chat', 'total_tokens' => 15]));
        $this->resource->insert($this->row(['consumer' => 'docs_search', 'total_tokens' => 150]));

        $overall = $this->subject->sumRange(
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2026-02-01 00:00:00')
        );
        $chatOnly = $this->subject->sumRange(
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2026-02-01 00:00:00'),
            'chat'
        );

        self::assertSame(165, $overall['total_tokens']);
        self::assertSame(15, $chatOnly['total_tokens']);
    }

    public function test_it_groups_a_window_by_consumer_and_by_service_row(): void
    {
        $this->resource->insert($this->row(['consumer' => 'chat', 'service_id' => '_row1', 'total_tokens' => 15]));
        $this->resource->insert(
            $this->row(['consumer' => 'docs_search', 'service_id' => '_row2', 'total_tokens' => 150])
        );

        $byConsumer = $this->subject->groupRange(
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2026-02-01 00:00:00'),
            UsageRecordRepositoryInterface::GROUP_BY_CONSUMER
        );
        $byService = $this->subject->groupRange(
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

        self::assertSame(['chat', 'docs_search'], $this->subject->getDistinctConsumers());
    }

    /**
     * @param array<string,int|string|bool|null> $overrides
     */
    private function record(array $overrides = []): UsageRecord
    {
        $defaults = [
            'serviceId' => '_row1',
            'serviceCode' => 'anthropic',
            'model' => 'claude-sonnet',
            'storeId' => 0,
            'consumer' => 'chat',
        ];

        /** @var array{
         *     serviceId: string,
         *     serviceCode: string,
         *     model: string,
         *     storeId: int,
         *     consumer: string,
         *     inputTokens?: int|null,
         *     outputTokens?: int|null,
         *     totalTokens?: int|null,
         *     cacheReadTokens?: int|null,
         *     cacheWriteTokens?: int|null,
         *     reasoningTokens?: int|null,
         *     streamed?: bool,
         *     failed?: bool,
         * } $arguments
         */
        $arguments = array_merge($defaults, $overrides);

        return new UsageRecord(...$arguments);
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
                'reasoning_tokens' => null,
                'streamed' => 0,
            ],
            $overrides
        );
    }
}

/**
 * In-memory stand-in for {@see \MageOS\AiBase\Model\ResourceModel\Usage\UsageLog}, kept next to
 * the test that uses it per this codebase's fakes-over-mocks convention.
 *
 * Rows are keyed by an auto-incrementing id the same way the real `entity_id` column is, and every
 * window query compares `created_at` as a plain string, which sorts identically to how MySQL
 * compares `TIMESTAMP` values for the `Y-m-d H:i:s` format this module always writes.
 */
final class FakeUsageLogResource implements UsageLogResourceInterface
{
    /**
     * @var array<int,array<string,int|string|null>>
     */
    private array $rowsById = [];

    private int $nextId = 1;

    /**
     * Caps every {@see deleteBatch()} call at this many rows, letting a test force more than one
     * batch without inserting thousands of rows. Real deletes are already bounded by the
     * repository's own batch size; this only lets a test observe that bound with a handful of rows.
     */
    private int $deleteBatchLimitOverride = PHP_INT_MAX;

    private int $deleteBatchCallCount = 0;

    /**
     * @inheritdoc
     */
    public function insert(array $row): int
    {
        $id = $this->nextId++;
        $row['entity_id'] = $id;
        $this->rowsById[$id] = $row;

        return $id;
    }

    /**
     * @inheritdoc
     */
    public function deleteBatch(\DateTimeInterface $cutoff, int $limit): int
    {
        $this->deleteBatchCallCount++;
        $limit = min($limit, $this->deleteBatchLimitOverride);
        $cutoffString = $cutoff->format('Y-m-d H:i:s');

        $idsToDelete = [];
        foreach ($this->rowsById as $id => $row) {
            if ((string) $row['created_at'] >= $cutoffString) {
                continue;
            }

            $idsToDelete[] = $id;
            if (count($idsToDelete) >= $limit) {
                break;
            }
        }

        foreach ($idsToDelete as $id) {
            unset($this->rowsById[$id]);
        }

        return count($idsToDelete);
    }

    /**
     * @inheritdoc
     */
    public function getOldestRecordedAt(): ?\DateTimeImmutable
    {
        if ($this->rowsById === []) {
            return null;
        }

        $oldest = min(array_map(fn (array $row): string => (string) $row['created_at'], $this->rowsById));

        return new \DateTimeImmutable($oldest);
    }

    /**
     * @inheritdoc
     */
    public function aggregateRange(\DateTimeInterface $from, \DateTimeInterface $to, string $usageDate): array
    {
        $buckets = [];
        foreach ($this->rowsInWindow($from, $to) as $row) {
            $key = implode('|', [$row['service_id'], $row['model'], $row['consumer'], $row['store_id']]);
            $buckets[$key][] = $row;
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
            $buckets
        ));
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
    public function getDistinctConsumers(): array
    {
        $consumers = array_unique(array_map(
            fn (array $row): string => (string) $row['consumer'],
            $this->rowsById
        ));
        sort($consumers);

        return array_values($consumers);
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
        throw new \LogicException('Not needed by UsageRecordRepositoryTest.');
    }

    /**
     * Forces {@see deleteBatch()} to remove at most $limit rows per call regardless of what the
     * caller asks for, so a test can observe batching without inserting a realistic batch's worth
     * of rows.
     *
     * @param int $limit
     * @return void
     */
    public function setDeleteBatchLimitOverride(int $limit): void
    {
        $this->deleteBatchLimitOverride = $limit;
    }

    /**
     * @return int Number of times {@see deleteBatch()} was called, so a test can prove more than
     *         one batch ran.
     */
    public function getDeleteBatchCallCount(): int
    {
        return $this->deleteBatchCallCount;
    }

    /**
     * @return array<int,array<string,int|string|null>> Every row currently stored, for a test to
     *         assert on directly.
     */
    public function getStoredRows(): array
    {
        return array_values($this->rowsById);
    }

    /**
     * @param \DateTimeInterface $from
     * @param \DateTimeInterface $to
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
