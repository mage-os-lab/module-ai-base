<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api;

use Magento\Framework\Exception\LocalizedException;

/**
 * Creates ready-to-use AI clients from the services configured in the admin.
 */
interface AiClientFactoryInterface
{
    /**
     * Create a client for a configured service.
     *
     * Uses the first configured service matching $serviceCode, or the first
     * configured service overall when $serviceCode is null.
     *
     * @param string|null $serviceCode
     * @return AiClientInterface
     * @throws LocalizedException When no matching service is configured or the
     *         underlying client library is not installed
     */
    public function create(?string $serviceCode = null): AiClientInterface;
}
