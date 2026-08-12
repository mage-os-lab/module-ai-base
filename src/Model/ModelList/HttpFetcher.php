<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\ModelList;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\ClientFactory;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Shared HTTP/JSON plumbing for model-list providers.
 *
 * Wraps client creation, transport errors, non-2xx statuses and JSON decoding into a single
 * call that either returns a decoded array or throws an admin-readable LocalizedException.
 */
class HttpFetcher
{
    /**
     * Request timeout in seconds; model listings are small, providers answer fast.
     */
    private const REQUEST_TIMEOUT = 20;

    /**
     * @param ClientFactory $httpClientFactory
     * @param Json $jsonSerializer
     */
    public function __construct(
        private readonly ClientFactory $httpClientFactory,
        private readonly Json $jsonSerializer,
    ) {
    }

    /**
     * Perform a GET request and decode the JSON response body.
     *
     * @param string $url
     * @param array<string,string> $headers Header name => value
     * @return array<mixed> Decoded JSON response
     * @throws LocalizedException On transport failure, non-2xx status or invalid JSON
     */
    public function getJson(string $url, array $headers = []): array
    {
        $client = $this->httpClientFactory->create();

        try {
            $client->setTimeout(self::REQUEST_TIMEOUT);
            if ($headers !== []) {
                $client->setHeaders($headers);
            }
            $client->get($url);
            $status = (int) $client->getStatus();
            $body = (string) $client->getBody();
        } catch (\Throwable $e) {
            throw new LocalizedException(
                __('Request to %1 failed: %2', $url, $e->getMessage()),
                $e instanceof \Exception ? $e : null
            );
        }

        if ($status < 200 || $status >= 300) {
            throw new LocalizedException($this->describeStatus($url, $status));
        }

        try {
            $decoded = $this->jsonSerializer->unserialize($body);
        } catch (\InvalidArgumentException $e) {
            throw new LocalizedException(__('Response from %1 is not valid JSON.', $url), $e);
        }

        if (!is_array($decoded)) {
            throw new LocalizedException(__('Response from %1 is not a JSON object.', $url));
        }

        return $decoded;
    }

    /**
     * Say what a failing status means before saying what it was.
     *
     * An administrator reading "HTTP status 401" has to know that a model listing is authenticated
     * with the same key the rest of the provider is, and that this provider spells a rejected key
     * that way. The three statuses below are the ones a wrong or unfinished configuration actually
     * produces; the number stays in the message either way, because it is what a bug report needs.
     *
     * @param string $url
     * @param int $status
     * @return \Magento\Framework\Phrase
     */
    private function describeStatus(string $url, int $status): \Magento\Framework\Phrase
    {
        return match ($status) {
            401, 403 => __(
                'The API key was rejected by %1 (HTTP %2). Check the key saved for this service.',
                $this->hostOf($url),
                $status
            ),
            404 => __(
                '%1 has no model list at %2 (HTTP 404). Check the base URL saved for this service.',
                $this->hostOf($url),
                $url
            ),
            429 => __(
                '%1 is rate limiting this account (HTTP 429). Wait and try again.',
                $this->hostOf($url)
            ),
            default => __('Request to %1 returned HTTP status %2.', $url, $status),
        };
    }

    /**
     * The provider's host, which is the part of the URL an administrator recognises.
     *
     * Matched rather than parsed: Magento's coding standard discourages parse_url(), and the only
     * thing needed here is the authority of a URL this module built from its own configuration.
     *
     * @param string $url
     * @return string
     */
    private function hostOf(string $url): string
    {
        return preg_match('~^[a-z][a-z0-9+.-]*://([^/?\#]+)~i', $url, $matches) === 1 ? $matches[1] : $url;
    }
}
