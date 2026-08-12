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
     * Request option naming the model a single call should run against.
     *
     * Every other option is a provider setting that reaches the request body; this one replaces
     * the model the service was configured with, for this call only. It exists because the model
     * is a property of the work rather than of the account: a module summarising a catalogue wants
     * a cheap model on the same key a chat assistant uses a strong one on, and pinning the model to
     * configuration meant configuring that key twice.
     *
     * Leave it out to use the configured model. A consumer that does not know which provider an
     * administrator picked should leave it out, since a model name is only valid at one provider.
     */
    public const OPTION_MODEL = 'model';

    /**
     * Send a conversation and return what the model replied.
     *
     * The response carries text, any tools the model wants run, token counts and the provider's
     * stop reason. This module never executes tools: run them yourself and feed each result back
     * with ChatRequestInterface::withToolResult() before calling again. Whether a call may run is
     * policy that belongs to the module owning the tool.
     *
     * @param ChatRequestInterface $request
     * @param array<string,mixed> $options Provider options (e.g. temperature, max_tokens), plus
     *        self::OPTION_MODEL to run this one call against a different model
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
     * When the stream runs to completion the generator *returns* the same ChatResponseInterface
     * a buffered chat() would have produced, with the text concatenated, the tool calls collected
     * and the final token counts and stop reason attached:
     *
     *     $stream = $client->streamChat($request);
     *     foreach ($stream as $chunk) { ... }
     *     $turn = $stream->getReturn();
     *     $request = $request->withAssistantTurn($turn);
     *
     * A streaming tool loop needs that accumulated turn to append before the next iteration, and
     * rebuilding it per consumer is exactly the error-prone bookkeeping this client exists to
     * remove. getReturn() is only valid once the generator has finished: a caller that breaks out
     * early gets an \Exception from PHP, which is the correct signal that there is no complete
     * turn to append.
     *
     * @param ChatRequestInterface $request
     * @param array<string,mixed> $options Provider options (e.g. temperature, max_tokens), plus
     *        self::OPTION_MODEL to run this one call against a different model
     * @return \Generator<int, \MageOS\AiBase\Api\Data\StreamChunkInterface, mixed, ChatResponseInterface>
     * @throws LocalizedException When the underlying platform is unavailable or the call fails
     */
    public function streamChat(ChatRequestInterface $request, array $options = []): \Generator;

    /**
     * Send a single-turn prompt and return the assistant's text response.
     *
     * Convenience over chat() for prompt-in, text-out work.
     *
     * @param string $prompt
     * @param array<string,mixed> $options Provider options (e.g. temperature, max_tokens), plus
     *        self::OPTION_MODEL to run this one call against a different model
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

    /**
     * Row id of the configured service backing this client, as AiServiceInterface::getId().
     *
     * The code does not identify a row: the same backend can be configured more than once, with
     * different credentials and different billing owners. Anything attributing spend has to name
     * the row, and re-resolving it through the selector would repeat work the factory already did.
     *
     * @return string
     */
    public function getServiceId(): string;

    /**
     * Model this client sends to (e.g. "gpt-4o"), as configured on the service row.
     *
     * Cost and token accounting is per model, not per provider, so a consumer logging usage needs
     * this alongside getServiceCode(). This is the configured default: a call that passed
     * self::OPTION_MODEL ran against that model instead, and this value did not change.
     *
     * @return string
     */
    public function getModel(): string;
}
