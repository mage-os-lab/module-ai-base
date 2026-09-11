<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\ResourceModel\Usage\UsageLog;

use MageOS\AiBase\Model\ResourceModel\Usage\UsageLog as UsageLogResource;
use MageOS\AiBase\Model\Usage\UsageLog;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Collection of `mageos_ai_usage_log` rows.
 *
 * Only {@see \MageOS\AiBase\Model\Usage\UsageRecordRepository::getList()} names this class,
 * through the generated `CollectionFactory` rather than `UsageLog::getCollection()`: the module's
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
        $this->_init(UsageLog::class, UsageLogResource::class);
    }
}
