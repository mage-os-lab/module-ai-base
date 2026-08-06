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
     * With $serviceCode, uses the first configured service with that code, whether or not its
     * bridge is installed: the caller named the provider, so an unusable one fails loudly rather
     * than silently resolving to a different provider.
     *
     * Without $serviceCode, uses the first configured service whose bridge is installed. Admin
     * row order is unrelated to which bridges an install has, so selecting the first configured
     * service regardless of usability would let one unusable provider at the top of the list
     * disable this entry point entirely.
     *
     * @param string|null $serviceCode
     * @return AiClientInterface
     * @throws LocalizedException When no matching service is configured or the
     *         underlying client library is not installed
     */
    public function create(?string $serviceCode = null): AiClientInterface;

    /**
     * Create a client for one specific configured row, addressed by AiServiceInterface::getId().
     *
     * This is the counterpart of Model\Config\Source\ConfiguredService: a module storing the row
     * an administrator selected turns that stored value into a client here. Unlike create(), it
     * reaches rows that are not the first of their service code, which is the whole reason an
     * administrator was offered a choice.
     *
     * @param string $serviceId
     * @return AiClientInterface
     * @throws LocalizedException When no row carries that id, or the underlying client library
     *         is not installed
     */
    public function createById(string $serviceId): AiClientInterface;
}
