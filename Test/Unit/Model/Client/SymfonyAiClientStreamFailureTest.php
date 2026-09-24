<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Client;

use MageOS\AiBase\Api\Data\FinishReason;
use MageOS\AiBase\Api\Data\MessageRole;
use MageOS\AiBase\Api\Data\StreamChunkType;
use MageOS\AiBase\Model\Chat\ChatMessage;
use MageOS\AiBase\Model\Chat\ChatRequest;
use MageOS\AiBase\Model\Client\AiExceptionMapper;
use MageOS\AiBase\Model\Client\AiRateLimitedException;
use MageOS\AiBase\Model\Client\AiServiceException;
use MageOS\AiBase\Model\Client\BridgeRegistry;
use MageOS\AiBase\Model\Client\OptionNormalizer;
use MageOS\AiBase\Model\Client\SymfonyAiClient;
use MageOS\AiBase\Model\Client\UsageNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;

/**
 * Exercises the mid-stream failure path against the real symfony/ai-platform
 * {@see DeferredResult} / {@see StreamResult} / {@see \Symfony\AI\Platform\TokenUsage\StreamListener}
 * pipeline rather than the bare-generator FakeResult used by {@see SymfonyAiClientChatTest}: only
 * the real classes run the listener that promotes a usage delta into result metadata on error, and
 * only DeferredResult::asStream()'s `finally` copies that metadata back out where extractUsage()
 * can read it after the generator has torn down.
 */
final class SymfonyAiClientStreamFailureTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(TextResult::class)) {
            self::markTestSkipped('symfony/ai-platform is not installed.');
        }
    }

    public function test_it_yields_the_usage_seen_before_a_stream_failure(): void
    {
        $platform = new FakeStreamingPlatform(static function (): \Generator {
            yield new TokenUsage(promptTokens: 120, completionTokens: 45);

            throw new \RuntimeException('overloaded');
        });

        $chunks = $this->drainIgnoringFailure($platform);

        $usageChunks = $this->usageChunksOf($chunks);
        self::assertCount(1, $usageChunks);
        self::assertSame(120, $usageChunks[0]->getUsage()?->getPromptTokens());
        self::assertSame(45, $usageChunks[0]->getUsage()?->getCompletionTokens());
    }

    public function test_it_wraps_the_mapped_exception_after_yielding_usage(): void
    {
        $platform = new FakeStreamingPlatform(static function (): \Generator {
            yield new TokenUsage(promptTokens: 10, completionTokens: 5);

            throw new \RuntimeException('overloaded');
        });

        try {
            iterator_to_array($this->client($platform)->streamChat($this->helloRequest()));
            self::fail('Expected an AiServiceException.');
        } catch (AiServiceException $e) {
            self::assertStringContainsString('overloaded', $e->getMessage());
            self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
            self::assertSame('overloaded', $e->getPrevious()->getMessage());
        }
    }

    public function test_it_maps_a_mid_stream_platform_failure_to_its_typed_exception(): void
    {
        $platform = new FakeStreamingPlatform(static function (): \Generator {
            yield new TextDelta('partial');

            throw new \Symfony\AI\Platform\Exception\RateLimitExceededException(30, 'slow down');
        });

        try {
            iterator_to_array($this->client($platform)->streamChat($this->helloRequest()));
            self::fail('Expected an AiRateLimitedException.');
        } catch (AiRateLimitedException $e) {
            self::assertSame(30, $e->getRetryAfter());
            self::assertInstanceOf(
                \Symfony\AI\Platform\Exception\RateLimitExceededException::class,
                $e->getPrevious(),
            );
        }
    }

    public function test_it_ends_the_stream_normally_when_the_answer_is_truncated(): void
    {
        $platform = new FakeStreamingPlatform(static function (): \Generator {
            yield new TextDelta('partial answer');
            yield new TokenUsage(promptTokens: 10, completionTokens: 5);

            throw new \Symfony\AI\Platform\Exception\MaxOutputTokensException('truncated');
        });

        $stream = $this->client($platform)->streamChat($this->helloRequest());
        $chunks = iterator_to_array($stream, false);

        self::assertSame(
            [StreamChunkType::Text, StreamChunkType::Usage],
            array_map(static fn ($c) => $c->getType(), $chunks),
        );

        $turn = $stream->getReturn();
        self::assertSame('partial answer', $turn->getText());
        self::assertSame(FinishReason::Length, $turn->getFinishReason());
    }

    public function test_it_rethrows_without_a_usage_chunk_when_no_usage_was_reported(): void
    {
        $platform = new FakeStreamingPlatform(static function (): \Generator {
            yield new TextDelta('Hi');

            throw new \RuntimeException('overloaded');
        });

        $chunks = $this->drainIgnoringFailure($platform);

        self::assertSame([], $this->usageChunksOf($chunks));
    }

    public function test_it_yields_normalized_usage_on_failure_for_anthropic(): void
    {
        $platform = new FakeStreamingPlatform(static function (): \Generator {
            yield new TokenUsage(
                promptTokens: 100,
                completionTokens: 50,
                cacheCreationTokens: 10,
                cacheReadTokens: 20,
            );

            throw new \RuntimeException('overloaded');
        });

        $chunks = $this->drainIgnoringFailure($platform, 'anthropic');

        $usageChunks = $this->usageChunksOf($chunks);
        self::assertCount(1, $usageChunks);
        self::assertSame(130, $usageChunks[0]->getUsage()?->getPromptTokens());
        self::assertSame(180, $usageChunks[0]->getUsage()?->getTotalTokens());
    }

    public function test_it_still_yields_usage_once_on_a_successful_stream(): void
    {
        $platform = new FakeStreamingPlatform(static function (): \Generator {
            yield new TextDelta('Hi');
            yield new TokenUsage(promptTokens: 10, completionTokens: 5);
        });

        $chunks = iterator_to_array($this->client($platform)->streamChat($this->helloRequest()), false);

        self::assertCount(1, $this->usageChunksOf($chunks));
    }

    /**
     * Iterates the stream to exhaustion, keeping every chunk yielded before the mapped exception
     * the fake's underlying RuntimeException always causes, so the caught-and-discarded exception
     * cannot hide a missing chunk.
     *
     * @return list<\MageOS\AiBase\Api\Data\StreamChunkInterface>
     */
    private function drainIgnoringFailure(FakeStreamingPlatform $platform, string $serviceCode = 'openai'): array
    {
        $chunks = [];
        try {
            foreach ($this->client($platform, $serviceCode)->streamChat($this->helloRequest()) as $chunk) {
                $chunks[] = $chunk;
            }
            self::fail('Expected the stream to rethrow.');
        } catch (AiServiceException) {
        }

        return $chunks;
    }

    /**
     * @param list<\MageOS\AiBase\Api\Data\StreamChunkInterface> $chunks
     * @return list<\MageOS\AiBase\Api\Data\StreamChunkInterface>
     */
    private function usageChunksOf(array $chunks): array
    {
        return array_values(array_filter(
            $chunks,
            static fn ($chunk) => $chunk->getType() === StreamChunkType::Usage,
        ));
    }

    private function client(FakeStreamingPlatform $platform, string $serviceCode = 'openai'): SymfonyAiClient
    {
        return new SymfonyAiClient(
            $platform,
            'gpt-4o',
            $serviceCode,
            '_row_1',
            $this->optionNormalizer(),
            $this->usageNormalizer(),
            new AiExceptionMapper(),
        );
    }

    /**
     * A normalizer wired the way di.xml wires it, so the Anthropic cache-outside-prompt case
     * behaves like production.
     */
    private function usageNormalizer(): UsageNormalizer
    {
        return new UsageNormalizer(new BridgeRegistry(['anthropic' => ['cache_outside_prompt' => true]]));
    }

    private function optionNormalizer(): OptionNormalizer
    {
        return new OptionNormalizer(
            new BridgeRegistry([
                'openai' => ['dialect' => 'openai_responses'],
                'anthropic' => ['dialect' => 'anthropic_messages'],
            ]),
            [
                'openai_responses' => ['map' => [
                    'max_tokens' => 'max_output_tokens',
                ]],
                'anthropic_messages' => [
                    'map' => ['max_tokens' => 'max_tokens'],
                    'defaults' => ['max_tokens' => 4096],
                ],
            ]
        );
    }

    private function helloRequest(): ChatRequest
    {
        return new ChatRequest([new ChatMessage(MessageRole::User, 'Hello')]);
    }
}

/**
 * Stand-in for a symfony/ai Platform whose invoke() returns a real
 * {@see DeferredResult} wired to a {@see StreamResult} built from the given deltas, so a mid-stream
 * failure runs through the real {@see \Symfony\AI\Platform\TokenUsage\StreamListener} and
 * DeferredResult::asStream()'s metadata copy-back exactly as production does.
 *
 * Deliberately not named FakePlatform: {@see SymfonyAiClientChatTest} already declares a class of
 * that name in this namespace, and both files load in the same Platform suite run.
 */
final class FakeStreamingPlatform
{
    public function __construct(
        private readonly \Closure $deltas,
    ) {
    }

    public function invoke(string $model, mixed $messages, array $options = []): DeferredResult
    {
        return new DeferredResult(
            new FakeStreamResultConverter($this->deltas),
            new InMemoryRawResult(),
            ['stream' => true],
        );
    }
}

/**
 * Converts straight to a {@see StreamResult} built from a fresh generator per call, and reports no
 * token usage extractor: this test's usage comes only from the deltas, exactly like a bridge whose
 * usage travels solely through the stream, which is the path {@see SymfonyAiClient::streamChat()}
 * has to recover on failure.
 */
final class FakeStreamResultConverter implements ResultConverterInterface
{
    public function __construct(
        private readonly \Closure $deltas,
    ) {
    }

    public function supports(Model $model): bool
    {
        return true;
    }

    public function convert(RawResultInterface $result, array $options = []): ResultInterface
    {
        return new StreamResult(($this->deltas)());
    }

    public function getTokenUsageExtractor(): ?TokenUsageExtractorInterface
    {
        return null;
    }
}
