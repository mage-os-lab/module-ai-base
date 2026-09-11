<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Usage;

use MageOS\AiBase\Model\ResourceModel\Usage\UsageDaily as UsageDailyResource;
use Magento\Framework\Api\ExtensibleDataInterface;
use Magento\Framework\Model\AbstractModel;

/**
 * Magento's load/save glue for one `mageos_ai_usage_daily` row.
 *
 * Unlike {@see UsageRecord} (the raw-log value object from task 004), this table has no separate
 * `Api\Data` interface: nothing outside {@see UsageDailyRepository} and the admin grid (task 015)
 * needs to hold a roll-up row independently of Magento's persistence layer, so this `AbstractModel`
 * is both the value object and the load/save glue. `UsageDailyRepository::getList()` returns these
 * directly rather than mapping them into a second shape.
 *
 * Implements the otherwise-unused marker {@see ExtensibleDataInterface} only so this class
 * satisfies `SearchResultsInterface::setItems()`'s declared `ExtensibleDataInterface[]` parameter
 * type; it defines no methods of its own, so nothing else about this class changes.
 */
class UsageDaily extends AbstractModel implements ExtensibleDataInterface
{
    /**
     * @inheritdoc
     */
    protected function _construct(): void
    {
        $this->_init(UsageDailyResource::class);
    }
}
