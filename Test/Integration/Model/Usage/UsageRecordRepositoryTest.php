<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Integration\Model\Usage;

use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AiBase\Api\UsageRecordRepositoryInterface;
use MageOS\AiBase\Model\Usage\UsageLog;
use PHPUnit\Framework\TestCase;

/**
 * Proves {@see UsageRecordRepositoryInterface::getList()} against a real database.
 *
 * `getList()` only assembles Magento's collection/search-criteria machinery
 * ({@see \Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface} and the generated
 * `CollectionFactory`); neither is realistically fakeable, so unlike the rest of the repository's
 * behaviour (proven twice, once against {@see \MageOS\AiBase\Test\Unit\Model\Usage\
 * UsageRecordRepositoryTest}'s fake and once here), this method is proven only here.
 */
final class UsageRecordRepositoryTest extends TestCase
{
    private const TABLE = 'mageos_ai_usage_log';

    private ObjectManagerInterface $objectManager;
    private UsageRecordRepositoryInterface $repository;
    private SearchCriteriaBuilder $searchCriteriaBuilder;
    private FilterBuilder $filterBuilder;
    private ResourceConnection $resourceConnection;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
        $this->repository = $this->objectManager->get(UsageRecordRepositoryInterface::class);
        $this->searchCriteriaBuilder = $this->objectManager->get(SearchCriteriaBuilder::class);
        $this->filterBuilder = $this->objectManager->get(FilterBuilder::class);
        $this->resourceConnection = $this->objectManager->get(ResourceConnection::class);
        $this->truncateTable();
    }

    protected function tearDown(): void
    {
        $this->truncateTable();
    }

    public function test_it_returns_saved_records_through_getList(): void
    {
        $this->insertRow(['consumer' => 'chat']);
        $this->insertRow(['consumer' => 'docs_search']);

        $searchResults = $this->repository->getList($this->searchCriteriaBuilder->create());

        $items = $searchResults->getItems();
        self::assertCount(2, $items);
        self::assertContainsOnlyInstancesOf(UsageLog::class, $items);
        self::assertSame(
            ['chat', 'docs_search'],
            array_values(array_map(static fn (UsageLog $item): string => (string) $item->getData('consumer'), $items))
        );
    }

    public function test_it_applies_search_criteria_filters_when_listing_records(): void
    {
        $this->insertRow(['consumer' => 'chat']);
        $this->insertRow(['consumer' => 'docs_search']);

        $filter = $this->filterBuilder->setField('consumer')->setValue('chat')->setConditionType('eq')->create();
        $searchCriteria = $this->searchCriteriaBuilder->addFilters([$filter])->create();

        $items = $this->repository->getList($searchCriteria)->getItems();

        self::assertCount(1, $items);
        self::assertSame('chat', reset($items)->getData('consumer'));
    }

    public function test_it_reports_the_total_count_independently_of_the_paged_result_size(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->insertRow(['consumer' => 'chat']);
        }

        $searchCriteria = $this->searchCriteriaBuilder->setPageSize(2)->setCurrentPage(1)->create();

        $searchResults = $this->repository->getList($searchCriteria);

        self::assertCount(2, $searchResults->getItems());
        self::assertSame(5, $searchResults->getTotalCount());
    }

    /**
     * @param array<string,int|string|null> $overrides
     * @return void
     */
    private function insertRow(array $overrides = []): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->insert($this->resourceConnection->getTableName(self::TABLE), array_merge(
            [
                'service_id' => '_row1',
                'service_code' => 'anthropic',
                'model' => 'claude-sonnet',
                'consumer' => 'chat',
                'store_id' => 0,
                'input_tokens' => 10,
                'output_tokens' => 5,
                'total_tokens' => 15,
                'streamed' => 0,
            ],
            $overrides
        ));
    }

    private function truncateTable(): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->delete($this->resourceConnection->getTableName(self::TABLE));
    }
}
