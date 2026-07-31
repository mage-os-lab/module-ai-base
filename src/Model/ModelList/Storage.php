<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\ModelList;

use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Persists refreshed model lists per service code in core_config_data (default scope).
 *
 * The payload is a JSON object `{"fetched_at": <unix ts>, "models": {value: label, ...}}` stored
 * at `mageos_ai/services/models/<code>`, so a manually refreshed list survives across requests
 * and feeds the admin form until the next refresh.
 */
class Storage
{
    /**
     * Config path prefix; the service code is appended as the last path segment.
     */
    private const CONFIG_PATH_PREFIX = 'mageos_ai/services/models/';

    /**
     * Payload key holding the value => label model map.
     */
    private const KEY_MODELS = 'models';

    /**
     * Payload key holding the unix timestamp of the fetch.
     */
    private const KEY_FETCHED_AT = 'fetched_at';

    /**
     * @param WriterInterface $configWriter
     * @param ScopeConfigInterface $scopeConfig
     * @param Json $jsonSerializer
     * @param TypeListInterface $cacheTypeList
     */
    public function __construct(
        private readonly WriterInterface $configWriter,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Json $jsonSerializer,
        private readonly TypeListInterface $cacheTypeList,
    ) {
    }

    /**
     * Persist a fetched model list for a service code (default scope) with a fetched-at timestamp.
     *
     * @param string $serviceCode
     * @param array $models Map of model value => label
     * @return void
     */
    public function save(string $serviceCode, array $models): void
    {
        $payload = $this->jsonSerializer->serialize([
            self::KEY_FETCHED_AT => time(),
            self::KEY_MODELS => $models,
        ]);
        $this->configWriter->save(self::CONFIG_PATH_PREFIX . $serviceCode, $payload);
        // The writer bypasses the config cache; clean it so the next page load sees the new list.
        $this->cacheTypeList->cleanType(ConfigCache::TYPE_IDENTIFIER);
    }

    /**
     * Load the stored model list for a service code.
     *
     * @param string $serviceCode
     * @return array<string, string>|null Map of model value => label, or null when nothing is stored
     */
    public function getModels(string $serviceCode): ?array
    {
        $payload = $this->getPayload($serviceCode);
        $models = $payload[self::KEY_MODELS] ?? null;

        return is_array($models) ? $models : null;
    }

    /**
     * Unix timestamp of the last refresh for a service code.
     *
     * @param string $serviceCode
     * @return int|null Null when nothing is stored
     */
    public function getFetchedAt(string $serviceCode): ?int
    {
        $payload = $this->getPayload($serviceCode);
        $fetchedAt = $payload[self::KEY_FETCHED_AT] ?? null;

        return is_numeric($fetchedAt) ? (int) $fetchedAt : null;
    }

    /**
     * Read and defensively decode the stored payload for a service code.
     *
     * @param string $serviceCode
     * @return array<string, mixed>|null
     */
    private function getPayload(string $serviceCode): ?array
    {
        $raw = $this->scopeConfig->getValue(self::CONFIG_PATH_PREFIX . $serviceCode);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $decoded = $this->jsonSerializer->unserialize($raw);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
