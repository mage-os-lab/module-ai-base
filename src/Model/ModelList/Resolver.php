<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\ModelList;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;

/**
 * Single merge point between refreshed (stored) model lists and each service's curated defaults.
 *
 * Service classes stay pure — their getSupportedModels() never reads storage; consumers that want
 * the effective list (the admin form) go through this resolver instead.
 */
class Resolver
{
    /**
     * @param Storage $storage
     */
    public function __construct(
        private readonly Storage $storage,
    ) {
    }

    /**
     * Effective model list for a service.
     *
     * Returns the stored (refreshed) list when present and non-empty, otherwise the curated defaults.
     *
     * @param AiServiceConfigurationInterface $service
     * @return array<string, string> Map of model value => label
     */
    public function getModels(AiServiceConfigurationInterface $service): array
    {
        $stored = $this->storage->getModels($service->getCode());
        if ($stored !== null && $stored !== []) {
            return $stored;
        }

        return $service->getSupportedModels();
    }
}
