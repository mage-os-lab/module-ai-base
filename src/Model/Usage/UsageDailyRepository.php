<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Usage;

use MageOS\AiBase\Api\UsageDailyRepositoryInterface;
use MageOS\AiBase\Model\ResourceModel\Usage\UsageDaily\Collection;
use MageOS\AiBase\Model\ResourceModel\Usage\UsageDaily\CollectionFactory;
use MageOS\AiBase\Model\ResourceModel\Usage\UsageDailyResourceInterface;
use Magento\Framework\Api\ExtensibleDataInterface;
use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Api\SearchResultsInterfaceFactory;

/**
 * Implementation of {@see UsageDailyRepositoryInterface}.
 *
 * Every method here does one of two things: assemble a {@see Collection} for {@see getList()} the
 * way any Magento repository does, or delegate straight to
 * {@see \MageOS\AiBase\Model\ResourceModel\Usage\UsageDaily} for the five statements that class
 * defines. Nothing here writes SQL of its own, which is what keeps this class testable against a
 * small fake of {@see UsageDailyResourceInterface} instead of against a real database connection.
 */
class UsageDailyRepository implements UsageDailyRepositoryInterface
{
    /**
     * @param UsageDailyResourceInterface $resource The five hand-written statements this
     *        repository delegates the non-`getList()` methods to.
     * @param CollectionFactory $collectionFactory Builds the collection {@see getList()} filters,
     *        used instead of `UsageDaily::getCollection()` per this module's phpstan-magento rules.
     * @param SearchResultsInterfaceFactory $searchResultsFactory
     * @param CollectionProcessorInterface $collectionProcessor Translates a
     *        {@see SearchCriteriaInterface} onto the collection; standard Magento machinery rather
     *        than hand-rolled filter translation.
     */
    public function __construct(
        private readonly UsageDailyResourceInterface $resource,
        private readonly CollectionFactory $collectionFactory,
        private readonly SearchResultsInterfaceFactory $searchResultsFactory,
        private readonly CollectionProcessorInterface $collectionProcessor,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function saveAggregates(array $rows): void
    {
        $this->resource->upsertAggregates($rows);
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
         * in fact a {@see \MageOS\AiBase\Model\Usage\UsageDaily}, which implements
         * ExtensibleDataInterface for exactly this reason, so this annotation is narrowing to what
         * is already true at runtime rather than asserting something new.
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
        return $this->resource->deleteOlderThan($cutoff);
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
    public function seriesRange(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        string $granularity,
        ?int $storeId = null
    ): array {
        return $this->resource->seriesRange($from, $to, $granularity, $storeId);
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
