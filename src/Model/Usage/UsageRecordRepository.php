<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Usage;

use MageOS\AiBase\Api\Data\UsageRecordInterface;
use MageOS\AiBase\Api\UsageRecordRepositoryInterface;
use MageOS\AiBase\Model\ResourceModel\Usage\UsageLog\CollectionFactory;
use MageOS\AiBase\Model\ResourceModel\Usage\UsageLogResourceInterface;
use Magento\Framework\Api\ExtensibleDataInterface;
use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Api\SearchResultsInterfaceFactory;

/**
 * Implementation of {@see UsageRecordRepositoryInterface}.
 *
 * Every method here does one of three things: maps a {@see UsageRecordInterface} onto a row for
 * {@see save()}, assembles a collection for {@see getList()} the way any Magento repository does,
 * or delegates straight to {@see \MageOS\AiBase\Model\ResourceModel\Usage\UsageLog} for the
 * statements that class defines. Nothing here writes SQL of its own, which is what keeps this
 * class testable against a small fake of {@see UsageLogResourceInterface} instead of against a
 * real database connection.
 */
class UsageRecordRepository implements UsageRecordRepositoryInterface
{
    /**
     * Rows {@see deleteOlderThan()} asks {@see UsageLogResourceInterface::deleteBatch()} to remove
     * per statement.
     *
     * Small enough that even a slow disk holds the delete's lock only briefly, large enough that a
     * store with years of history does not need thousands of round trips to finish pruning.
     */
    private const DELETE_BATCH_SIZE = 1000;

    /**
     * @param UsageLogResourceInterface $resource The hand-written statements this repository
     *        delegates the non-`getList()` methods to.
     * @param CollectionFactory $collectionFactory Builds the collection {@see getList()} filters,
     *        used instead of `UsageLog::getCollection()` per this module's phpstan-magento rules.
     * @param SearchResultsInterfaceFactory $searchResultsFactory
     * @param CollectionProcessorInterface $collectionProcessor Translates a
     *        {@see SearchCriteriaInterface} onto the collection; standard Magento machinery rather
     *        than hand-rolled filter translation.
     */
    public function __construct(
        private readonly UsageLogResourceInterface $resource,
        private readonly CollectionFactory $collectionFactory,
        private readonly SearchResultsInterfaceFactory $searchResultsFactory,
        private readonly CollectionProcessorInterface $collectionProcessor,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function save(UsageRecordInterface $record): void
    {
        $this->resource->insert($this->toRow($record));
    }

    /**
     * @inheritdoc
     */
    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface
    {
        $collection = $this->collectionFactory->create();
        $this->collectionProcessor->process($searchCriteria, $collection);

        /** @var SearchResultsInterface $searchResults */
        $searchResults = $this->searchResultsFactory->create();
        $searchResults->setSearchCriteria($searchCriteria);

        /**
         * The collection is typed by {@see \Magento\Framework\Data\Collection::getItems()} as
         * `DataObject[]`, the common denominator of every Magento collection. Every item here is
         * in fact a {@see UsageLog}, which implements ExtensibleDataInterface for exactly this
         * reason, so this annotation is narrowing to what is already true at runtime rather than
         * asserting something new.
         *
         * @var ExtensibleDataInterface[] $items
         */
        $items = $collection->getItems();
        $searchResults->setItems($items);
        $searchResults->setTotalCount($collection->getSize());

        return $searchResults;
    }

    /**
     * @inheritdoc
     */
    public function deleteOlderThan(\DateTimeInterface $cutoff): int
    {
        $deletedTotal = 0;

        do {
            $deletedInBatch = $this->resource->deleteBatch($cutoff, self::DELETE_BATCH_SIZE);
            $deletedTotal += $deletedInBatch;
        } while ($deletedInBatch > 0);

        return $deletedTotal;
    }

    /**
     * @inheritdoc
     */
    public function getOldestRecordedAt(): ?\DateTimeImmutable
    {
        return $this->resource->getOldestRecordedAt();
    }

    /**
     * @inheritdoc
     */
    public function aggregateRange(\DateTimeInterface $from, \DateTimeInterface $to, string $usageDate): array
    {
        return $this->resource->aggregateRange($from, $to, $usageDate);
    }

    /**
     * @inheritdoc
     */
    public function sumRange(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        ?string $consumer = null,
        ?int $storeId = null
    ): array {
        return $this->resource->sumRange($from, $to, $consumer, $storeId);
    }

    /**
     * @inheritdoc
     */
    public function groupRange(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        string $groupBy,
        ?int $storeId = null
    ): array {
        return $this->resource->groupRange($from, $to, $groupBy, $storeId);
    }

    /**
     * @inheritdoc
     */
    public function getDistinctConsumers(): array
    {
        return $this->resource->getDistinctConsumers();
    }

    /**
     * @inheritdoc
     */
    public function seriesRange(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        string $granularity,
        ?int $storeId = null
    ): array {
        return $this->resource->seriesRange($from, $to, $granularity, $storeId);
    }

    /**
     * Maps a {@see UsageRecordInterface} onto the row {@see UsageLogResourceInterface::insert()} writes.
     *
     * Excludes `entity_id` and `created_at`: the database assigns both, so a record built ahead
     * of being saved never carries either.
     *
     * @param UsageRecordInterface $record
     * @return array<string,int|string|null>
     */
    private function toRow(UsageRecordInterface $record): array
    {
        return [
            'service_id' => $record->getServiceId(),
            'service_code' => $record->getServiceCode(),
            'model' => $record->getModel(),
            'consumer' => $record->getConsumer(),
            'store_id' => $record->getStoreId(),
            'input_tokens' => $record->getInputTokens(),
            'output_tokens' => $record->getOutputTokens(),
            'total_tokens' => $record->getTotalTokens(),
            'cache_read_tokens' => $record->getCacheReadTokens(),
            'cache_write_tokens' => $record->getCacheWriteTokens(),
            'reasoning_tokens' => $record->getReasoningTokens(),
            'streamed' => $record->isStreamed() ? 1 : 0,
            'failed' => $record->isFailed() ? 1 : 0,
        ];
    }

    /**
     * @inheritdoc
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
        return $this->resource->seriesRangeGrouped($from, $to, $granularity, $groupBy, $storeId);
    }
}
