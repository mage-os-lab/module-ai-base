<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Client;

use Magento\Framework\Exception\LocalizedException;
use MageOS\AiBase\Api\AiClientInterface;
use MageOS\AiBase\Api\PlatformAwareInterface;
use MageOS\AiBase\Api\Data\ChatMessageInterface;
use MageOS\AiBase\Api\Data\ChatRequestInterface;
use MageOS\AiBase\Api\Data\ChatResponseInterface;
use MageOS\AiBase\Api\Data\FinishReason;
use MageOS\AiBase\Api\Data\MessageRole;
use MageOS\AiBase\Api\Data\ReasoningInterface;
use MageOS\AiBase\Api\Data\StreamChunkInterface;
use MageOS\AiBase\Api\Data\StreamChunkType;
use MageOS\AiBase\Api\Data\TokenUsageInterface;
use MageOS\AiBase\Api\Data\ToolDefinitionInterface;
use MageOS\AiBase\Api\Data\UsageRecordInterface;
use MageOS\AiBase\Model\Chat\ChatMessage;
use MageOS\AiBase\Model\Chat\ChatRequest;
use MageOS\AiBase\Model\Chat\ChatResponse;
use MageOS\AiBase\Model\Chat\Reasoning;
use MageOS\AiBase\Model\Chat\StreamChunk;
use MageOS\AiBase\Model\Chat\TokenUsage;
use MageOS\AiBase\Model\Chat\ToolCall;

/**
 * Adapter around a symfony/ai-platform Platform instance.
 *
 * The Symfony AI classes are referenced lazily (string FQCNs, guarded by
 * class_exists in ClientFactory) so this module does not hard-require
 * symfony/ai-platform. Native signatures therefore say `object`, while the
 * docblocks name the real platform type: annotations are never autoloaded, so
 * static analysis gets to check these calls without the runtime gaining a
 * dependency on a package that may be absent. Written against symfony/ai-platform v0.14.0; the
 * component is experimental and not covered by Symfony's BC promise, so
 * pin the version and re-verify on upgrade.
 */
class SymfonyAiClient implements AiClientInterface, PlatformAwareInterface
{
    /**
     * Metadata key the platform stores extracted token counts under.
     */
    private const METADATA_TOKEN_USAGE = 'token_usage';

    /**
     * Metadata key the platform stores the normalized stop reason under.
     */
    private const METADATA_FINISH_REASON = 'finish_reason';

    /**
     * Provider stop-reason wordings, for bridges reporting a bare string instead of a mapped value.
     *
     * The platform normalizes the reason itself for every bundled bridge; this is the fallback for
     * a third-party bridge that only forwards what its provider wrote.
     */
    private const RAW_FINISH_REASONS = [
        'stop' => FinishReason::Stop,
        'end_turn' => FinishReason::Stop,
        'complete' => FinishReason::Stop,
        'length' => FinishReason::Length,
        'max_tokens' => FinishReason::Length,
        'model_length' => FinishReason::Length,
        'tool_use' => FinishReason::ToolCall,
        'tool_calls' => FinishReason::ToolCall,
        'function_call' => FinishReason::ToolCall,
        'content_filter' => FinishReason::ContentFilter,
        'refusal' => FinishReason::ContentFilter,
        'safety' => FinishReason::ContentFilter,
        'stop_sequence' => FinishReason::StopSequence,
    ];

    /**
     * Placeholder execution target for tool definitions.
     *
     * Symfony's Tool requires an ExecutionReference, but this module never executes tools and the
     * reference is not serialized into the provider payload, so nothing ever resolves it.
     */
    private const TOOL_EXECUTION_PLACEHOLDER_METHOD = 'toolsAreExecutedByTheConsumer';

    /**
     * Dialect of the bridges whose Responses API drops a reasoning item unless it is asked for.
     */
    private const DIALECT_OPENAI_RESPONSES = 'openai_responses';

    /**
     * Request option naming which optional items the Responses API should include in its reply.
     */
    private const OPTION_INCLUDE = 'include';

    /**
     * Include value that makes a reasoning item's encrypted content come back at all.
     */
    private const INCLUDE_REASONING_ENCRYPTED_CONTENT = 'reasoning.encrypted_content';

    /**
     * @param \Symfony\AI\Platform\PlatformInterface $platform
     * @param non-empty-string $model Guaranteed by ClientFactory, which refuses a row without one
     * @param string $serviceCode
     * @param string $serviceId Configured row this client was built from
     * @param OptionNormalizer $optionNormalizer
     * @param UsageNormalizer $usageNormalizer Folds cache reads and writes into the reported usage
     *        per this service's bridge; see {@see toAiBaseUsage()}
     * @param AiExceptionMapper $exceptionMapper Turns a symfony/ai failure into this module's own
     *        typed exception; see {@see wrap()}
     * @param BridgeRegistry $bridgeRegistry Says which request-option dialect this service speaks,
     *        so the reasoning-include workaround below applies only to the bridges that need it.
     *        Required, like the normalizers, because Magento only auto-wires a required class-typed
     *        argument and compiles an optional one's default into generated/metadata as a value
     * @param string|null $consumer Feature or module the factory attributed this client to;
     *        read back, normalized, through getConsumer()
     */
    public function __construct(
        private readonly object $platform,
        private readonly string $model,
        private readonly string $serviceCode,
        private readonly string $serviceId,
        private readonly OptionNormalizer $optionNormalizer,
        private readonly UsageNormalizer $usageNormalizer,
        private readonly AiExceptionMapper $exceptionMapper,
        private readonly BridgeRegistry $bridgeRegistry,
        private readonly ?string $consumer = null,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function chat(ChatRequestInterface $request, array $options = []): ChatResponseInterface
    {
        $result = $this->invoke($request, $options);

        try {
            return $this->toChatResponse($result);
        } catch (\Symfony\AI\Platform\Exception\MaxOutputTokensException) {
            return $this->truncatedResponse('', [], $this->extractUsage($result), $result);
        } catch (\Throwable $e) {
            throw $this->wrap($e);
        }
    }

    /**
     * A failing stream still yields the usage it billed before it broke.
     *
     * Symfony runs its token-usage listener on the error path too, so the counts are sitting in the
     * result metadata at the moment the stream throws. Yielding one usage chunk from that metadata
     * before rethrowing is what lets the recording decorator record what was actually billed
     * instead of losing it to the exception. The exception itself is mapped through {@see wrap()}
     * the same way every other failure in this class is, so `@throws LocalizedException` holds for
     * a mid-stream failure too, with one exception: a `MaxOutputTokensException` is not an error to
     * this module, it means the answer was cut off, so the stream ends normally instead, with
     * `FinishReason::Length` and the text collected before the provider truncated it.
     *
     * @inheritdoc
     */
    public function streamChat(ChatRequestInterface $request, array $options = []): \Generator
    {
        $result = $this->invoke($request, ['stream' => true] + $options);

        try {
            $deltas = $result->asStream();
        } catch (\Throwable $e) {
            throw $this->wrap($e);
        }

        $text = '';
        $toolCalls = [];
        $usage = null;

        try {
            foreach ($deltas as $delta) {
                foreach ($this->toStreamChunks($delta) as $chunk) {
                    $text .= $chunk->getType() === StreamChunkType::Text ? $chunk->getText() : '';
                    $toolCall = $chunk->getType() === StreamChunkType::ToolCall ? $chunk->getToolCall() : null;
                    if ($toolCall !== null) {
                        $toolCalls[] = $toolCall;
                    }
                    $usage = $chunk->getUsage() ?? $usage;
                    yield $chunk;
                }
            }
        } catch (\Symfony\AI\Platform\Exception\MaxOutputTokensException) {
            yield from $this->yieldUsageMissedByTheDeltas($result, $usage);

            return $this->truncatedResponse($text, $toolCalls, $usage, $result);
        } catch (\Throwable $e) {
            yield from $this->yieldUsageMissedByTheDeltas($result, $usage);

            throw $this->wrap($e);
        }

        // Token counts and the stop reason arrive at the very end of a stream, and the platform
        // lifts both out of the delta sequence into the result metadata rather than letting them
        // through as deltas. Reading them here is what makes a usage chunk reachable at all.
        yield from $this->yieldUsageMissedByTheDeltas($result, $usage);

        return new ChatResponse(
            $text,
            $toolCalls,
            $usage,
            $this->extractFinishReason($result),
            $this->extractRawFinishReason($result),
            $this->extractStreamedReasoning($result),
        );
    }

    /**
     * The reasoning blocks a finished stream carried, read off the turn the platform reassembled.
     *
     * A stream's thinking arrives as deltas spread across many events, some bridges emitting a
     * signature only after its block has closed. {@see \Symfony\AI\Platform\Result\StreamResult}
     * already does that reassembly for every consumer of `getAssistantMessage()`; reading it here
     * rather than re-tracking deltas in this method keeps that one reassembly the only one that has
     * to match each bridge's own delta order. Called once the loop over deltas has finished, which
     * is exactly the "deltas of interest already consumed" case that method documents as safe.
     *
     * getResult() rather than the DeferredResult itself: getAssistantMessage() lives on the
     * StreamResult it converts to, and asStream() above already forced and cached that conversion,
     * so this is the same object the deltas were read from, not a second pass over the stream.
     *
     * @param \Symfony\AI\Platform\Result\DeferredResult $result
     * @return list<Reasoning>
     */
    private function extractStreamedReasoning(object $result): array
    {
        $reasoning = [];
        foreach ($this->streamedThinking($result) as $thinking) {
            $reasoning[] = new Reasoning((string) $thinking->getContent(), $thinking->getSignature());
        }

        return $reasoning;
    }

    /**
     * The thinking parts of a finished stream's reassembled turn.
     *
     * `getAssistantMessage()` lives on StreamResult, one of many possible ResultInterface
     * implementations, so DeferredResult::getResult() only promises the wider interface. A call
     * made with `stream => true` always converts to StreamResult; that guarantee comes from how
     * this class itself drives the platform, not from anything the platform's own types can state.
     *
     * @param \Symfony\AI\Platform\Result\DeferredResult $result
     * @return list<\Symfony\AI\Platform\Message\Content\Thinking>
     */
    private function streamedThinking(object $result): array
    {
        // @phpstan-ignore method.notFound, method.nonObject, return.type
        return $result->getResult()->getAssistantMessage()->getThinking();
    }

    /**
     * Yields the one usage chunk a stream's deltas never carried, buffered or on failure alike.
     *
     * Shared by both the success and the error path of {@see streamChat()} so a mid-stream failure
     * cannot end up yielding a second usage chunk on top of one the deltas already reported: the
     * `$usage === null` guard is exactly the one the buffered path already relies on.
     *
     * @param \Symfony\AI\Platform\Result\DeferredResult $result
     * @param TokenUsageInterface|null $usage Usage already seen from a delta; passed by reference
     *        so the caller's local keeps the extracted value once this yields it
     * @return \Generator<int, StreamChunkInterface>
     */
    private function yieldUsageMissedByTheDeltas(object $result, ?TokenUsageInterface &$usage): \Generator
    {
        if ($usage !== null) {
            return;
        }

        $usage = $this->extractUsage($result);
        if ($usage !== null) {
            yield new StreamChunk(StreamChunkType::Usage, '', null, $usage);
        }
    }

    /**
     * @inheritdoc
     */
    public function complete(string $prompt, array $options = []): string
    {
        return $this->chat(
            new ChatRequest([new ChatMessage(MessageRole::User, $prompt)]),
            $options,
        )->getText();
    }

    /**
     * @inheritdoc
     */
    public function getServiceCode(): string
    {
        return $this->serviceCode;
    }

    /**
     * @inheritdoc
     */
    public function getServiceId(): string
    {
        return $this->serviceId;
    }

    /**
     * @inheritdoc
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * @inheritdoc
     */
    public function getConsumer(): string
    {
        $consumer = $this->consumer !== null ? trim($this->consumer) : '';

        return $consumer !== '' ? $consumer : UsageRecordInterface::CONSUMER_UNKNOWN;
    }

    /**
     * @inheritdoc
     */
    public function getPlatform(): object
    {
        return $this->platform;
    }

    /**
     * @inheritdoc
     */
    public function normalizeOptions(array $options): array
    {
        return $this->withReasoningInclude($this->optionNormalizer->normalize($this->serviceCode, $options));
    }

    /**
     * Send the request to the platform.
     *
     * @param ChatRequestInterface $request
     * @param array<string,mixed> $options
     * @return \Symfony\AI\Platform\Result\DeferredResult
     * @throws AiRequestNotSentException When the request is malformed and never reaches the platform
     * @throws LocalizedException When the platform rejects or fails to send an otherwise valid request
     */
    private function invoke(ChatRequestInterface $request, array $options): object
    {
        $model = $this->modelFor($options);
        unset($options[AiClientInterface::OPTION_MODEL], $options[AiClientInterface::OPTION_CONSUMER]);

        $options = $this->normalizeOptions($options);

        $tools = $this->toTools($request->getTools());
        if ($tools !== []) {
            $options['tools'] = $tools;
        }

        // Built outside the try below on purpose: a malformed request (e.g. a tool result missing
        // its call id) is a mistake in the calling code, made before anything reached the platform,
        // and must surface as AiRequestNotSentException rather than be caught and reworded by wrap()
        // as if the provider had rejected it.
        $messageBag = $this->toMessageBag($request);

        try {
            return $this->platform->invoke($model, $messageBag, $options);
        } catch (\Throwable $e) {
            throw $this->wrap($e);
        }
    }

    /**
     * Ask the Responses API to include a reasoning item's encrypted content.
     *
     * OpenAI and Azure both speak the Responses API, and it drops the reasoning item from the reply
     * unless the request explicitly lists it under `include`, whether or not extended reasoning was
     * ever otherwise requested. Every other dialect returns what it has without being asked. A
     * caller who already addressed `include` directly keeps whatever else they listed: this only
     * adds the one value needed for {@see toChatResponse()} and {@see toAssistantParts()} to carry
     * reasoning through at all.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function withReasoningInclude(array $options): array
    {
        if ($this->bridgeRegistry->getDialect($this->serviceCode) !== self::DIALECT_OPENAI_RESPONSES) {
            return $options;
        }

        $include = $options[self::OPTION_INCLUDE] ?? [];
        $include = is_array($include) ? array_values($include) : [$include];
        if (!in_array(self::INCLUDE_REASONING_ENCRYPTED_CONTENT, $include, true)) {
            $include[] = self::INCLUDE_REASONING_ENCRYPTED_CONTENT;
        }
        $options[self::OPTION_INCLUDE] = $include;

        return $options;
    }

    /**
     * The model this call runs against: the caller's, or the one the row was configured with.
     *
     * The model is the one call parameter that used to live only in configuration, which forced a
     * second configured row, credentials and all, on anyone who wanted the same account with a
     * cheaper model for bulk work. It is removed from the options before they are normalized
     * because the platform takes it as its own argument, not as a field of the request body.
     *
     * @param array<string,mixed> $options
     * @return non-empty-string
     * @throws AiRequestNotSentException When the caller names a model that is not a usable name
     */
    private function modelFor(array $options): string
    {
        if (!array_key_exists(AiClientInterface::OPTION_MODEL, $options)) {
            return $this->model;
        }

        $requested = $options[AiClientInterface::OPTION_MODEL];
        $model = is_string($requested) ? trim($requested) : '';
        if ($model === '') {
            throw new AiRequestNotSentException(__(
                'The "%1" option for AI service "%2" must be a model name. '
                . 'Leave it out to use the model the service is configured with.',
                AiClientInterface::OPTION_MODEL,
                $this->serviceCode
            ));
        }

        return $model;
    }

    /**
     * Build the platform's message bag from the request's conversation.
     *
     * @param ChatRequestInterface $request
     * @return \Symfony\AI\Platform\Message\MessageBag
     */
    private function toMessageBag(ChatRequestInterface $request): object
    {
        $messageBagClass = \Symfony\AI\Platform\Message\MessageBag::class;

        return new $messageBagClass(...array_map(
            fn (ChatMessageInterface $message) => $this->toMessage($message),
            $request->getMessages(),
        ));
    }

    /**
     * Translate one message into the platform's equivalent.
     *
     * @param ChatMessageInterface $message
     * @return \Symfony\AI\Platform\Message\MessageInterface
     */
    private function toMessage(ChatMessageInterface $message): object
    {
        $messageClass = \Symfony\AI\Platform\Message\Message::class;

        return match ($message->getRole()) {
            MessageRole::System => $messageClass::forSystem($message->getContent()),
            MessageRole::User => $messageClass::ofUser($message->getContent()),
            MessageRole::Assistant => $messageClass::ofAssistant(
                ...$this->toAssistantParts($message)
            ),
            MessageRole::Tool => $messageClass::ofToolCall(
                $this->toPlatformToolCall($message->getAnsweredToolCall()),
                $message->getContent(),
            ),
        };
    }

    /**
     * Content parts of an assistant turn: its reasoning, then its text, then any tool calls.
     *
     * Reasoning goes first because a provider that requires it back (Anthropic with thinking
     * enabled) rejects a turn where it does not lead the other content. The text is dropped when
     * empty, because a model that only requested tools wrote none and an empty text part is not
     * something every provider accepts.
     *
     * @param ChatMessageInterface $message
     * @return list<string|object> Reasoning first, then text, then one platform ToolCall per call
     */
    private function toAssistantParts(ChatMessageInterface $message): array
    {
        $parts = array_map(
            fn (ReasoningInterface $reasoning): object => $this->toPlatformThinking($reasoning),
            $message->getReasoning(),
        );

        if ($message->getContent() !== '') {
            $parts[] = $message->getContent();
        }
        foreach ($message->getToolCalls() as $toolCall) {
            $parts[] = $this->toPlatformToolCall($toolCall);
        }

        return $parts;
    }

    /**
     * Translate a reasoning block into the platform's own content part.
     *
     * @param ReasoningInterface $reasoning
     * @return \Symfony\AI\Platform\Message\Content\Thinking
     */
    private function toPlatformThinking(ReasoningInterface $reasoning): object
    {
        $thinkingClass = \Symfony\AI\Platform\Message\Content\Thinking::class;

        return new $thinkingClass($reasoning->getText(), $reasoning->getSignature());
    }

    /**
     * Translate a tool call into the platform's own value object.
     *
     * @param \MageOS\AiBase\Api\Data\ToolCallInterface|null $toolCall
     * @return \Symfony\AI\Platform\Result\ToolCall
     * @throws AiRequestNotSentException
     */
    private function toPlatformToolCall(?object $toolCall): object
    {
        if ($toolCall === null) {
            throw new AiRequestNotSentException(
                __('A tool result message must name the tool call it answers.')
            );
        }

        $toolCallClass = \Symfony\AI\Platform\Result\ToolCall::class;

        return new $toolCallClass($toolCall->getId(), $toolCall->getName(), $toolCall->getArguments());
    }

    /**
     * Translate offered tools into the platform's Tool objects.
     *
     * @param list<ToolDefinitionInterface> $tools
     * @return list<\Symfony\AI\Platform\Tool\Tool>
     */
    private function toTools(array $tools): array
    {
        $toolClass = \Symfony\AI\Platform\Tool\Tool::class;
        $referenceClass = \Symfony\AI\Platform\Tool\ExecutionReference::class;

        return array_map(
            fn (ToolDefinitionInterface $tool): object => new $toolClass(
                new $referenceClass(self::class, self::TOOL_EXECUTION_PLACEHOLDER_METHOD),
                $tool->getName(),
                $tool->getDescription(),
                // Symfony spells the whole JSON Schema out as an array shape. This one is written
                // by the consumer at runtime and only the provider can rule on it, so the shape is
                // unprovable here; restating it would reject valid schemas Symfony left out.
                // @phpstan-ignore argument.type
                $tool->getParameters(),
            ),
            $tools,
        );
    }

    /**
     * Read text, tool calls and usage off a converted result.
     *
     * The result type is inspected rather than asked for: asText() throws when the model only
     * requested tools, which is exactly what the first turn of a tool loop returns.
     *
     * @param \Symfony\AI\Platform\Result\DeferredResult $result
     * @return ChatResponseInterface
     */
    private function toChatResponse(object $result): ChatResponseInterface
    {
        $parts = $this->toResultParts($result->getResult());

        return new ChatResponse(
            $this->extractText($parts),
            $this->extractToolCalls($parts),
            $this->extractUsage($result),
            $this->extractFinishReason($result),
            $this->extractRawFinishReason($result),
            $this->extractReasoning($parts),
        );
    }

    /**
     * Flatten a result into its parts, so single and multi-part results read the same.
     *
     * @param \Symfony\AI\Platform\Result\ResultInterface $result
     * @return list<\Symfony\AI\Platform\Result\ResultInterface>
     */
    private function toResultParts(object $result): array
    {
        return $result instanceof \Symfony\AI\Platform\Result\MultiPartResult
            ? $result->getContent()
            : [$result];
    }

    /**
     * Concatenate the text of every text part.
     *
     * @param list<\Symfony\AI\Platform\Result\ResultInterface> $parts
     * @return string
     */
    private function extractText(array $parts): string
    {
        $text = '';
        foreach ($parts as $part) {
            if ($part instanceof \Symfony\AI\Platform\Result\TextResult) {
                $text .= (string) $part->getContent();
            }
        }

        return $text;
    }

    /**
     * Collect every tool call across the result's parts.
     *
     * @param list<\Symfony\AI\Platform\Result\ResultInterface> $parts
     * @return list<ToolCall>
     */
    private function extractToolCalls(array $parts): array
    {
        $toolCalls = [];
        foreach ($parts as $part) {
            if (!$part instanceof \Symfony\AI\Platform\Result\ToolCallResult) {
                continue;
            }
            foreach ($part->getContent() as $toolCall) {
                $toolCalls[] = $this->toAiBaseToolCall($toolCall);
            }
        }

        return $toolCalls;
    }

    /**
     * Collect every reasoning block across the result's parts, oldest first.
     *
     * @param list<\Symfony\AI\Platform\Result\ResultInterface> $parts
     * @return list<Reasoning>
     */
    private function extractReasoning(array $parts): array
    {
        $reasoning = [];
        foreach ($parts as $part) {
            if ($part instanceof \Symfony\AI\Platform\Result\ThinkingResult) {
                $reasoning[] = new Reasoning((string) $part->getContent(), $part->getSignature());
            }
        }

        return $reasoning;
    }

    /**
     * Read token counts off the result metadata, where the platform stores them.
     *
     * Metadata is an untyped bag any bridge may write to, so the entry is checked rather than
     * assumed: a third-party bridge storing its own idea of usage under this key would otherwise
     * take down a working call over numbers nothing needs.
     *
     * @param \Symfony\AI\Platform\Result\DeferredResult $result
     * @return TokenUsage|null
     */
    private function extractUsage(object $result): ?TokenUsage
    {
        $usage = $result->getMetadata()->get(self::METADATA_TOKEN_USAGE);

        return $usage instanceof \Symfony\AI\Platform\TokenUsage\TokenUsageInterface
            ? $this->toAiBaseUsage($usage)
            : null;
    }

    /**
     * Read the stop reason off the result metadata and normalize it.
     *
     * The platform maps each provider's wording onto its own case set per bridge, so the object it
     * stores already answers "was this truncated?"; only its vocabulary has to be translated.
     *
     * Anything else under that key falls through to the raw wording below, which is the same path a
     * bridge reporting a bare provider string takes, so an unrecognized entry costs nothing.
     *
     * @param \Symfony\AI\Platform\Result\DeferredResult $result
     * @return FinishReason|null
     */
    private function extractFinishReason(object $result): ?FinishReason
    {
        $reason = $result->getMetadata()->get(self::METADATA_FINISH_REASON);
        if ($reason === null) {
            return null;
        }

        $case = $reason instanceof \Symfony\AI\Platform\FinishReason\FinishReason
            ? $reason->getCase()->value
            : null;

        return match ($case) {
            'stop' => FinishReason::Stop,
            'length' => FinishReason::Length,
            'tool-call' => FinishReason::ToolCall,
            'content-filter' => FinishReason::ContentFilter,
            'stop-sequence' => FinishReason::StopSequence,
            'other' => FinishReason::Other,
            default => $this->toFinishReasonFromRaw((string) $this->extractRawFinishReason($result)),
        };
    }

    /**
     * The stop reason exactly as the provider wrote it.
     *
     * @param \Symfony\AI\Platform\Result\DeferredResult $result
     * @return string|null
     */
    private function extractRawFinishReason(object $result): ?string
    {
        $reason = $result->getMetadata()->get(self::METADATA_FINISH_REASON);
        if ($reason === null) {
            return null;
        }

        $raw = is_object($reason) && method_exists($reason, 'getRaw') ? $reason->getRaw() : $reason;

        return is_scalar($raw) || $raw instanceof \Stringable ? (string) $raw : null;
    }

    /**
     * Best effort meaning for a bridge that reported a bare provider string.
     *
     * @param string $raw
     * @return FinishReason
     */
    private function toFinishReasonFromRaw(string $raw): FinishReason
    {
        return self::RAW_FINISH_REASONS[strtolower($raw)] ?? FinishReason::Other;
    }

    /**
     * Translate one streamed delta into the chunks it carries.
     *
     * A list rather than a single chunk because one delta is not one event: a model requesting
     * several tools in the same turn produces exactly one ToolCallComplete holding all of them.
     * ThinkingStart and ToolCallStart exist purely to signal that a block has opened before it has
     * anything to say, so a consumer stops staring at a silent connection during the pause before
     * the first word or tool call; a delta this module has no mapping for still translates to
     * nothing.
     *
     * @param mixed $delta
     * @return list<StreamChunkInterface>
     */
    private function toStreamChunks(mixed $delta): array
    {
        if ($delta instanceof \Symfony\AI\Platform\Result\Stream\Delta\TextDelta) {
            return [new StreamChunk(StreamChunkType::Text, $delta->getText())];
        }
        if ($delta instanceof \Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta) {
            return [new StreamChunk(StreamChunkType::Thinking, $delta->getThinking())];
        }
        if ($delta instanceof \Symfony\AI\Platform\Result\Stream\Delta\ThinkingStart) {
            return [new StreamChunk(StreamChunkType::ThinkingStart)];
        }
        if ($delta instanceof \Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart) {
            return [$this->toToolCallStartChunk($delta->getId(), $delta->getName())];
        }
        if ($delta instanceof \Symfony\AI\Platform\Result\Stream\Delta\ToolInputDelta) {
            return [$this->toToolCallStartChunk($delta->getId(), $delta->getName())];
        }
        if ($delta instanceof \Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete) {
            return $this->toToolCallChunks($delta);
        }
        if ($delta instanceof \Symfony\AI\Platform\TokenUsage\TokenUsageInterface) {
            return [new StreamChunk(StreamChunkType::Usage, '', null, $this->toAiBaseUsage($delta))];
        }

        return [];
    }

    /**
     * A chunk announcing that a tool call has opened, before its arguments are known.
     *
     * Shared by {@see \Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart}, which fires once
     * per call, and {@see \Symfony\AI\Platform\Result\Stream\Delta\ToolInputDelta}, which then
     * fires repeatedly while the model writes that call's arguments: a consumer only wants to
     * know a call is under way and what it is called, not to reassemble the partial JSON itself,
     * so both map to the same chunk shape with empty arguments. The completed call, arguments
     * included, still arrives once as today on a {@see StreamChunkType::ToolCall} chunk.
     *
     * @param string $id
     * @param string $name
     * @return StreamChunkInterface
     */
    private function toToolCallStartChunk(string $id, string $name): StreamChunkInterface
    {
        return new StreamChunk(StreamChunkType::ToolCallStart, '', new ToolCall($id, $name, []));
    }

    /**
     * One chunk per completed tool call, arguments already accumulated and decoded by the bridge.
     *
     * ToolCallComplete signals that *all* of the turn's tool calls are finished and carries them
     * together, so taking only the first would drop every tool but one from a parallel-tool turn,
     * which the buffered path does not do.
     *
     * @param \Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete $delta
     * @return list<StreamChunkInterface>
     */
    private function toToolCallChunks(object $delta): array
    {
        return array_map(
            fn (object $toolCall): StreamChunkInterface => new StreamChunk(
                StreamChunkType::ToolCall,
                '',
                $this->toAiBaseToolCall($toolCall),
            ),
            array_values($delta->getToolCalls()),
        );
    }

    /**
     * Translate a platform tool call into this module's own.
     *
     * @param \Symfony\AI\Platform\Result\ToolCall $toolCall
     * @return ToolCall
     */
    private function toAiBaseToolCall(object $toolCall): ToolCall
    {
        return new ToolCall($toolCall->getId(), $toolCall->getName(), $toolCall->getArguments());
    }

    /**
     * Translate platform token counts into this module's own, normalized per this service's bridge.
     *
     * Delegated to {@see UsageNormalizer} rather than done inline: the cache-token rules differ per
     * bridge and are exercised on their own in that class's tests, which would otherwise have to go
     * through a full platform result to reach.
     *
     * @param \Symfony\AI\Platform\TokenUsage\TokenUsageInterface $usage
     * @return TokenUsage
     */
    private function toAiBaseUsage(object $usage): TokenUsage
    {
        return $this->usageNormalizer->normalize($this->serviceCode, $usage);
    }

    /**
     * Present a provider or library failure as one of this module's typed exceptions.
     *
     * Admin-readable and naming the service, the same way every wrapped failure always has.
     *
     * @param \Throwable $e
     * @return LocalizedException
     */
    private function wrap(\Throwable $e): LocalizedException
    {
        return $this->exceptionMapper->map($e, $this->serviceCode);
    }

    /**
     * The turn assembled so far, reported as truncated rather than as a failure.
     *
     * `MaxOutputTokensException` means the provider stopped writing because it hit the output token
     * ceiling, not that anything went wrong: the text collected up to that point is a real, usable,
     * truncated answer, and {@see ChatResponseInterface::getFinishReason()} already documents
     * `FinishReason::Length` as the case every consumer should check for exactly this.
     *
     * @param string $text
     * @param list<\MageOS\AiBase\Api\Data\ToolCallInterface> $toolCalls
     * @param TokenUsageInterface|null $usage
     * @param \Symfony\AI\Platform\Result\DeferredResult $result
     * @return ChatResponse
     */
    private function truncatedResponse(
        string $text,
        array $toolCalls,
        ?TokenUsageInterface $usage,
        object $result,
    ): ChatResponse {
        return new ChatResponse(
            $text,
            $toolCalls,
            $usage,
            FinishReason::Length,
            $this->extractRawFinishReason($result),
        );
    }
}
