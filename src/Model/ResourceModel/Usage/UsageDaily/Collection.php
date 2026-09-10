<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\ResourceModel\Usage\UsageDaily;

use MageOS\AiBase\Model\ResourceModel\Usage\UsageDaily as UsageDailyResource;
use MageOS\AiBase\Model\Usage\UsageDaily;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Collection of `mageos_ai_usage_daily` rows.
 *
 * Only {@see \MageOS\AiBase\Model\Usage\UsageDailyRepository::getList()} names this class,
 * through the generated `CollectionFactory` rather than `UsageDaily::getCollection()`: the module's
 * `phpstan-magento` rules enforce exactly that split, and it is what keeps every other consumer
 * going through the repository instead of instantiating a collection of its own.
 */
class Collection extends AbstractCollection
{
    /**
     * @inheritdoc
     */
    protected function _construct(): void
    {
        $this->_init(UsageDaily::class, UsageDailyResource::class);
    }
}
