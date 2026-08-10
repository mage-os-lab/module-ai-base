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
use MageOS\AiBase\Api\Data\StreamChunkInterface;
use MageOS\AiBase\Api\Data\StreamChunkType;
use MageOS\AiBase\Api\Data\ToolDefinitionInterface;
use MageOS\AiBase\Model\Chat\ChatMessage;
use MageOS\AiBase\Model\Chat\ChatRequest;
use MageOS\AiBase\Model\Chat\ChatResponse;
use MageOS\AiBase\Model\Chat\StreamChunk;
use MageOS\AiBase\Model\Chat\TokenUsage;
use MageOS\AiBase\Model\Chat\ToolCall;

/**
 * Adapter around a symfony/ai-platform Platform instance.
 *
 * The Symfony AI classes are referenced lazily (string FQCNs, guarded by
 * class_exists in ClientFactory) so this module does not hard-require
 * symfony/ai-platform. Written against symfony/ai-platform v0.12.0; the
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
     * @param object $platform \Symfony\AI\Platform\PlatformInterface
     * @param string $model
     * @param string $serviceCode
     * @param string $serviceId Configured row this client was built from
     * @param OptionNormalizer $optionNormalizer
     */
    public function __construct(
        private readonly object $platform,
        private readonly string $model,
        private readonly string $serviceCode,
        private readonly string $serviceId,
        private readonly OptionNormalizer $optionNormalizer,
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
        } catch (\Throwable $e) {
            throw $this->wrap($e);
        }
    }

    /**
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

        foreach ($deltas as $delta) {
            foreach ($this->toStreamChunks($delta) as $chunk) {
                $text .= $chunk->getType() === StreamChunkType::Text ? $chunk->getText() : '';
                $toolCall = $chunk->getToolCall();
                if ($toolCall !== null) {
                    $toolCalls[] = $toolCall;
                }
                $usage = $chunk->getUsage() ?? $usage;
                yield $chunk;
            }
        }

        // Token counts and the stop reason arrive at the very end of a stream, and the platform
        // lifts both out of the delta sequence into the result metadata rather than letting them
        // through as deltas. Reading them here is what makes a usage chunk reachable at all.
        if ($usage === null && ($usage = $this->extractUsage($result)) !== null) {
            yield new StreamChunk(StreamChunkType::Usage, '', null, $usage);
        }

        return new ChatResponse(
            $text,
            $toolCalls,
            $usage,
            $this->extractFinishReason($result),
            $this->extractRawFinishReason($result),
        );
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
    public function getPlatform(): object
    {
        return $this->platform;
    }

    /**
     * @inheritdoc
     */
    public function normalizeOptions(array $options): array
    {
        return $this->optionNormalizer->normalize($this->serviceCode, $options);
    }

    /**
     * Send the request to the platform.
     *
     * @param ChatRequestInterface $request
     * @param array $options
     * @return object
     * @throws LocalizedException
     */
    private function invoke(ChatRequestInterface $request, array $options): object
    {
        $options = $this->normalizeOptions($options);

        $tools = $this->toTools($request->getTools());
        if ($tools !== []) {
            $options['tools'] = $tools;
        }

        try {
            return $this->platform->invoke($this->model, $this->toMessageBag($request), $options);
        } catch (\Throwable $e) {
            throw $this->wrap($e);
        }
    }

    /**
     * Build the platform's message bag from the request's conversation.
     *
     * @param ChatRequestInterface $request
     * @return object \Symfony\AI\Platform\Message\MessageBag
     */
    private function toMessageBag(ChatRequestInterface $request): object
    {
        $messageBagClass = \Symfony\AI\Platform\Message\MessageBag::class;

        return new $messageBagClass(...array_map(
            fn (ChatMessageInterface $message): object => $this->toMessage($message),
            $request->getMessages(),
        ));
    }

    /**
     * Translate one message into the platform's equivalent.
     *
     * @param ChatMessageInterface $message
     * @return object \Symfony\AI\Platform\Message\MessageInterface
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
     * Content parts of an assistant turn: its text, then any tool calls it requested.
     *
     * The text is dropped when empty, because a model that only requested tools wrote none and an
     * empty text part is not something every provider accepts.
     *
     * @param ChatMessageInterface $message
     * @return array
     */
    private function toAssistantParts(ChatMessageInterface $message): array
    {
        $parts = $message->getContent() === '' ? [] : [$message->getContent()];
        foreach ($message->getToolCalls() as $toolCall) {
            $parts[] = $this->toPlatformToolCall($toolCall);
        }

        return $parts;
    }

    /**
     * Translate a tool call into the platform's own value object.
     *
     * @param \MageOS\AiBase\Api\Data\ToolCallInterface|null $toolCall
     * @return object \Symfony\AI\Platform\Result\ToolCall
     * @throws LocalizedException
     */
    private function toPlatformToolCall(?object $toolCall): object
    {
        if ($toolCall === null) {
            throw new LocalizedException(
                __('A tool result message must name the tool call it answers.')
            );
        }

        $toolCallClass = \Symfony\AI\Platform\Result\ToolCall::class;

        return new $toolCallClass($toolCall->getId(), $toolCall->getName(), $toolCall->getArguments());
    }

    /**
     * Translate offered tools into the platform's Tool objects.
     *
     * @param ToolDefinitionInterface[] $tools
     * @return array
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
     * @param object $result
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
        );
    }

    /**
     * Flatten a result into its parts, so single and multi-part results read the same.
     *
     * @param object $result
     * @return array
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
     * @param array $parts
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
     * @param array $parts
     * @return array
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
     * Read token counts off the result metadata, where the platform stores them.
     *
     * @param object $result
     * @return TokenUsage|null
     */
    private function extractUsage(object $result): ?TokenUsage
    {
        $usage = $result->getMetadata()->get(self::METADATA_TOKEN_USAGE);

        return $usage === null ? null : $this->toAiBaseUsage($usage);
    }

    /**
     * Read the stop reason off the result metadata and normalize it.
     *
     * The platform maps each provider's wording onto its own case set per bridge, so the object it
     * stores already answers "was this truncated?"; only its vocabulary has to be translated.
     *
     * @param object $result
     * @return FinishReason|null
     */
    private function extractFinishReason(object $result): ?FinishReason
    {
        $reason = $result->getMetadata()->get(self::METADATA_FINISH_REASON);
        if ($reason === null) {
            return null;
        }

        $case = is_object($reason) && method_exists($reason, 'getCase') ? $reason->getCase()->value : null;

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
     * @param object $result
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
     * Deltas that carry no payload of their own translate to nothing.
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
        if ($delta instanceof \Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete) {
            return $this->toToolCallChunks($delta);
        }
        if ($delta instanceof \Symfony\AI\Platform\TokenUsage\TokenUsageInterface) {
            return [new StreamChunk(StreamChunkType::Usage, '', null, $this->toAiBaseUsage($delta))];
        }

        return [];
    }

    /**
     * One chunk per completed tool call, arguments already accumulated and decoded by the bridge.
     *
     * ToolCallComplete signals that *all* of the turn's tool calls are finished and carries them
     * together, so taking only the first would drop every tool but one from a parallel-tool turn,
     * which the buffered path does not do.
     *
     * @param object $delta
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
     * @param object $toolCall \Symfony\AI\Platform\Result\ToolCall
     * @return ToolCall
     */
    private function toAiBaseToolCall(object $toolCall): ToolCall
    {
        return new ToolCall($toolCall->getId(), $toolCall->getName(), $toolCall->getArguments());
    }

    /**
     * Translate platform token counts into this module's own.
     *
     * @param object $usage \Symfony\AI\Platform\TokenUsage\TokenUsageInterface
     * @return TokenUsage
     */
    private function toAiBaseUsage(object $usage): TokenUsage
    {
        return new TokenUsage(
            $usage->getPromptTokens(),
            $usage->getCompletionTokens(),
            $usage->getTotalTokens(),
        );
    }

    /**
     * Present a provider or library failure as an admin-readable error naming the service.
     *
     * @param \Throwable $e
     * @return LocalizedException
     */
    private function wrap(\Throwable $e): LocalizedException
    {
        return new LocalizedException(
            __('AI request to service "%1" failed: %2', $this->serviceCode, $e->getMessage()),
            $e instanceof \Exception ? $e : null
        );
    }
}
