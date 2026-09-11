<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Usage;

use MageOS\AiBase\Model\ResourceModel\Usage\UsageLog as UsageLogResource;
use Magento\Framework\Api\ExtensibleDataInterface;
use Magento\Framework\Model\AbstractModel;

/**
 * Magento's load/save glue for one `mageos_ai_usage_log` row.
 *
 * Unlike {@see UsageRecord} (the immutable value object from task 004 the rest of the module
 * sees), this is the `AbstractModel` shape the admin grid (task 015) needs to filter and page
 * through Magento's collection machinery. {@see UsageRecordRepository::getList()} returns these
 * directly rather than mapping them into {@see UsageRecord}, exactly the split
 * {@see \MageOS\AiBase\Api\UsageRecordRepositoryInterface}'s class docblock explains.
 *
 * Implements the otherwise-unused marker {@see ExtensibleDataInterface} only so this class
 * satisfies `SearchResultsInterface::setItems()`'s declared `ExtensibleDataInterface[]` parameter
 * type; it defines no methods of its own, so nothing else about this class changes.
 */
class UsageLog extends AbstractModel implements ExtensibleDataInterface
{
    /**
     * @inheritdoc
     */
    protected function _construct(): void
    {
        $this->_init(UsageLogResource::class);
    }
}
