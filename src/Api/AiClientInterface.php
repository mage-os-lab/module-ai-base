<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api;

use Magento\Framework\Exception\LocalizedException;

/**
 * Provider-agnostic AI client.
 *
 * Consumer modules should depend on this interface instead of talking to
 * provider APIs or raw configuration directly.
 */
interface AiClientInterface
{
    /**
     * Send a single-turn prompt and return the assistant's text response.
     *
     * @param string $prompt
     * @param array $options Provider options (e.g. temperature, max_tokens)
     * @return string
     * @throws LocalizedException When the underlying platform is unavailable or the call fails
     */
    public function complete(string $prompt, array $options = []): string;

    /**
     * Code of the configured service backing this client (e.g. "openai").
     *
     * @return string
     */
    public function getServiceCode(): string;
}
