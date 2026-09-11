<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Usage\Source;

use MageOS\AiBase\Api\UsageRecordRepositoryInterface;
use MageOS\AiBase\Api\Data\UsageRecordInterface;
use MageOS\AiBase\Model\Usage\Source\Consumer;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MageOS\AiBase\Model\Usage\Source\Consumer
 */
final class ConsumerTest extends TestCase
{
    private FakeConsumerSource $usageRecordRepository;

    protected function setUp(): void
    {
        $this->usageRecordRepository = new FakeConsumerSource();
    }

    /**
     * The consumer filter is a select, not a free-text input, because nobody can spell an opaque
     * consumer identifier. Its options are exactly the distinct values actually recorded.
     */
    public function test_it_offers_the_distinct_recorded_consumers_as_filter_options(): void
    {
        $this->usageRecordRepository->withRecordedConsumers(['maggy_chat', 'issue_tracker']);

        $options = (new Consumer($this->usageRecordRepository))->toOptionArray();

        self::assertSame(
            ['maggy_chat', 'issue_tracker'],
            array_column($options, 'value')
        );
        self::assertSame(
            ['maggy_chat', 'issue_tracker'],
            array_column($options, 'label')
        );
    }

    public function test_it_offers_no_options_when_nothing_was_recorded(): void
    {
        $this->usageRecordRepository->withRecordedConsumers([]);

        self::assertSame([], (new Consumer($this->usageRecordRepository))->toOptionArray());
    }
}

/**
 * In-memory stand-in for {@see UsageRecordRepositoryInterface}, offering only the distinct
 * consumers {@see Consumer} reads, per this codebase's fakes-over-mocks convention. Every other
 * method throws, so a test that comes to depend on one fails loudly rather than reading a
 * plausible-looking default.
 */
final class FakeConsumerSource implements UsageRecordRepositoryInterface
{
    /** @var string[] */
    private array $consumers = [];

    /**
     * @param string[] $consumers
     */
    public function withRecordedConsumers(array $consumers): self
    {
        $this->consumers = $consumers;

        return $this;
    }

    /**
     * @return string[]
     */
    public function getDistinctConsumers(): array
    {
        return $this->consumers;
    }

    public function save(UsageRecordInterface $record): void
    {
        throw new \LogicException('Not needed by ConsumerTest.');
    }

    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface
    {
        throw new \LogicException('Not needed by ConsumerTest.');
    }

    public function deleteOlderThan(\DateTimeInterface $cutoff): int
    {
        throw new \LogicException('Not needed by ConsumerTest.');
    }

    public function getOldestRecordedAt(): ?\DateTimeImmutable
    {
        throw new \LogicException('Not needed by ConsumerTest.');
    }

    /**
     * @return array<int,array<string,int|string|null>>
     */
    public function aggregateRange(\DateTimeInterface $from, \DateTimeInterface $to, string $usageDate): array
    {
        throw new \LogicException('Not needed by ConsumerTest.');
    }

    /**
     * @return array<string,int|null>
     */
    public function sumRange(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        ?string $consumer = null,
        ?int $storeId = null
    ): array {
        throw new \LogicException('Not needed by ConsumerTest.');
    }

    /**
     * @return array<int,array<string,int|string|null>>
     */
    public function groupRange(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        string $groupBy,
        ?int $storeId = null
    ): array {
        throw new \LogicException('Not needed by ConsumerTest.');
    }

    /**
     * @return array<int,array<string,int|string|null>>
     */
    public function seriesRange(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        string $granularity,
        ?int $storeId = null
    ): array {
        throw new \LogicException('Not needed by ConsumerTest.');
    }

    /**
     * @return array<int,array<string,int|string|null>>
     */
    public function seriesRangeGrouped(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        string $granularity,
        string $groupBy,
        ?int $storeId = null
    ): array {
        throw new \LogicException('Not needed by ConsumerTest.');
    }
}
