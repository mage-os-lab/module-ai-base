<?php

declare(strict_types=1);

namespace MageOS\AiBase\AiServices;

use Magento\Framework\Exception\LocalizedException;

/**
 * Shared response-parsing helpers for services implementing ModelListProviderInterface.
 */
trait ModelListTrait
{
    /**
     * Parse an OpenAI-style model listing (`{"data": [{"id": ...}, ...]}`) into a value => label map.
     *
     * @param array<mixed> $response Decoded JSON response
     * @param string|null $labelField Optional entry key to use as the label; falls back to the id
     * @return array<string, string> Map of model id => label
     * @throws LocalizedException When the response does not contain a "data" model list
     */
    private function parseDataModelList(array $response, ?string $labelField = null): array
    {
        if (!isset($response['data']) || !is_array($response['data'])) {
            throw new LocalizedException(
                __('Unexpected model list response from %1: missing "data" list.', $this->getName())
            );
        }

        $models = [];
        foreach ($response['data'] as $entry) {
            if (!is_array($entry) || !isset($entry['id']) || !is_string($entry['id']) || $entry['id'] === '') {
                continue;
            }
            $label = $entry['id'];
            if ($labelField !== null
                && isset($entry[$labelField])
                && is_string($entry[$labelField])
                && $entry[$labelField] !== ''
            ) {
                $label = $entry[$labelField];
            }
            $models[$entry['id']] = $label;
        }

        return $models;
    }

    /**
     * Resolve the base URL from configuration, falling back to a default, without a trailing slash.
     *
     * @param array $configuration Saved service configuration
     * @param string $default Default base URL for this service
     * @return string
     */
    private function resolveBaseUrl(array $configuration, string $default): string
    {
        $baseUrl = $configuration['base_url'] ?? null;
        $baseUrl = is_string($baseUrl) && trim($baseUrl) !== '' ? trim($baseUrl) : $default;

        return rtrim($baseUrl, '/');
    }
}
