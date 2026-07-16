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
     * @param array $headers Header name => value
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
            throw new LocalizedException(__('Request to %1 failed: %2', $url, $e->getMessage()), $e);
        }

        if ($status < 200 || $status >= 300) {
            throw new LocalizedException(__('Request to %1 returned HTTP status %2.', $url, $status));
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
}
