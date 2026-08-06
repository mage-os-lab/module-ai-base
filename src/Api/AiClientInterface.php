<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api;

use Magento\Framework\Exception\LocalizedException;
use MageOS\AiBase\Api\Data\ChatRequestInterface;
use MageOS\AiBase\Api\Data\ChatResponseInterface;

/**
 * Provider-agnostic AI client.
 *
 * Consumer modules should depend on this interface instead of talking to
 * provider APIs or raw configuration directly.
 */
interface AiClientInterface
{
    /**
     * Send a conversation and return what the model replied.
     *
     * The response carries text, any tools the model wants run, token counts and the provider's
     * stop reason. This module never executes tools: run them yourself and feed each result back
     * with ChatRequestInterface::withToolResult() before calling again. Whether a call may run is
     * policy that belongs to the module owning the tool.
     *
     * @param ChatRequestInterface $request
     * @param array $options Provider options (e.g. temperature, max_tokens)
     * @return ChatResponseInterface
     * @throws LocalizedException When the underlying platform is unavailable or the call fails
     */
    public function chat(ChatRequestInterface $request, array $options = []): ChatResponseInterface;

    /**
     * Send a conversation and yield events as the model produces them.
     *
     * Yields StreamChunkInterface: text deltas, reasoning deltas, completed tool calls and token
     * counts. Tool call arguments arrive whole and already decoded, so there is no partial-JSON
     * accumulation to do. The caller drives the loop and may stop early.
     *
     * @param ChatRequestInterface $request
     * @param array $options Provider options (e.g. temperature, max_tokens)
     * @return \Generator<int, \MageOS\AiBase\Api\Data\StreamChunkInterface>
     * @throws LocalizedException When the underlying platform is unavailable or the call fails
     */
    public function streamChat(ChatRequestInterface $request, array $options = []): \Generator;

    /**
     * Send a single-turn prompt and return the assistant's text response.
     *
     * Convenience over chat() for prompt-in, text-out work.
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
