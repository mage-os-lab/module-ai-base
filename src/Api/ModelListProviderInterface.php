<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api;

use Magento\Framework\Exception\LocalizedException;

/**
 * Opt-in capability for AI backends that can list their available models live.
 *
 * Implement this alongside {@see \MageOS\AiBase\Api\Data\AiServiceConfigurationInterface} to enable
 * the "Refresh Models" action in the admin form. Backends that cannot enumerate models (or whose
 * listing endpoint needs configuration this module does not collect) simply do not implement it.
 */
interface ModelListProviderInterface
{
    /**
     * Fetch the models currently available from the provider.
     *
     * @param array $configuration Saved service configuration with decrypted credentials
     *        (keys such as `api_key`, `base_url`, `endpoint`, `model`)
     * @return array<string, string> Map of model value => human-readable label
     * @throws LocalizedException On HTTP, authentication or response-parsing failure,
     *         with an admin-readable message
     */
    public function fetchModels(array $configuration): array;
}
