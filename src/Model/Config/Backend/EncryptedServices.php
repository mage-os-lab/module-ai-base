<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Config\Backend;

use Magento\Config\Model\Config\Backend\Serialized\ArraySerialized;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use MageOS\AiBase\Model\Config\SensitiveDataProcessor;

/**
 * Serialized services config with credential fields encrypted at rest.
 *
 * Row shape: [rowId => [serviceCode => [field => value, ...]]]
 */
class EncryptedServices extends ArraySerialized
{
    /**
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param SensitiveDataProcessor $sensitiveDataProcessor
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     * @param Json|null $serializer
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly SensitiveDataProcessor $sensitiveDataProcessor,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = [],
        ?Json $serializer = null
    ) {
        parent::__construct(
            $context,
            $registry,
            $config,
            $cacheTypeList,
            $resource,
            $resourceCollection,
            $data,
            $serializer
        );
    }

    /**
     * Encrypt credential fields before persisting.
     *
     * @return $this
     */
    public function beforeSave()
    {
        $value = $this->getValue();
        if (is_array($value)) {
            $this->setValue($this->mapRows($value, [$this->sensitiveDataProcessor, 'encryptRow']));
        }

        return parent::beforeSave();
    }

    /**
     * Decrypt credential fields after loading for the admin form.
     *
     * @return $this
     */
    protected function _afterLoad()
    {
        parent::_afterLoad();

        $value = $this->getValue();
        if (is_array($value)) {
            $this->setValue($this->mapRows($value, [$this->sensitiveDataProcessor, 'decryptRow']));
        }

        return $this;
    }

    /**
     * Apply a row processor to each service configuration row.
     *
     * @param array $value
     * @param callable $processor
     * @return array
     */
    private function mapRows(array $value, callable $processor): array
    {
        foreach ($value as $rowId => $row) {
            if (!is_array($row)) {
                continue;
            }
            $service = array_key_first($row);
            if ($service !== null && is_array($row[$service])) {
                $value[$rowId][$service] = $processor($row[$service]);
            }
        }

        return $value;
    }
}
