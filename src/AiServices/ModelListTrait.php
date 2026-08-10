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
     * @return array<string,string> Map of model id => label
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
     * Read the stored API key, treating anything that is not a string as absent.
     *
     * The configuration array is whatever json_decode made of the stored row, so a hand-edited or
     * half-saved value can be any type at all. Casting one to a string would send `Array` as the
     * credential and get back an authentication failure, instead of the empty key the provider can
     * at least name.
     *
     * @param array<string,mixed> $configuration Saved service configuration
     * @return string
     */
    private function resolveApiKey(array $configuration): string
    {
        $apiKey = $configuration['api_key'] ?? null;

        return is_string($apiKey) ? $apiKey : '';
    }

    /**
     * Resolve the base URL from configuration, falling back to a default, without a trailing slash.
     *
     * @param array<string,mixed> $configuration Saved service configuration
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
