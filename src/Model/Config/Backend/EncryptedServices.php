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
 * Credentials are never decrypted into the admin form: after load, sensitive
 * values are replaced with an obscured placeholder. On save, a submitted
 * placeholder restores the previously stored (encrypted) value, so admins can
 * save the form without retyping credentials.
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
     * @param Json $jsonSerializer
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly SensitiveDataProcessor $sensitiveDataProcessor,
        private readonly Json $jsonSerializer,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $config,
            $cacheTypeList,
            $resource,
            $resourceCollection,
            $data,
            $jsonSerializer
        );
    }

    /**
     * Restore placeholder-masked credentials from stored config, then encrypt before persisting.
     *
     * @return $this
     */
    public function beforeSave()
    {
        $value = $this->getValue();
        if (is_array($value)) {
            $stored = $this->getStoredRows();
            $this->setValue($this->mapRows(
                $value,
                fn (array $row, string $rowId, string $service): array => $this->sensitiveDataProcessor->encryptRow(
                    $this->sensitiveDataProcessor->restoreRow($row, $this->storedRow($stored, $rowId, $service))
                ),
            ));
        }

        return parent::beforeSave();
    }

    /**
     * Mask credential fields with the obscured placeholder for the admin form.
     *
     * @return $this
     */
    protected function _afterLoad()
    {
        parent::_afterLoad();

        $value = $this->getValue();
        if (is_array($value)) {
            $this->setValue($this->mapRows(
                $value,
                fn (array $row): array => $this->sensitiveDataProcessor->maskRow($row),
            ));
        }

        return $this;
    }

    /**
     * Apply a row processor to each service configuration row.
     *
     * The processor is called with the row configuration, the row ID and the service code.
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
                $value[$rowId][$service] = $processor($row[$service], (string)$rowId, (string)$service);
            }
        }

        return $value;
    }

    /**
     * Previously stored service rows, decoded from the old (raw, still encrypted) config value.
     *
     * @return array
     */
    private function getStoredRows(): array
    {
        $old = $this->getOldValue();
        if ($old === '') {
            return [];
        }
        try {
            $decoded = $this->jsonSerializer->unserialize($old);
        } catch (\InvalidArgumentException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Extract a single stored service configuration row, if present.
     *
     * @param array $stored
     * @param string $rowId
     * @param string $service
     * @return array
     */
    private function storedRow(array $stored, string $rowId, string $service): array
    {
        $row = $stored[$rowId][$service] ?? [];

        return is_array($row) ? $row : [];
    }
}
