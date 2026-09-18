<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Client;

use MageOS\AiBase\Model\Client\BridgeRegistry;
use MageOS\AiBase\Model\Client\UsageNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\AI\Platform\TokenUsage\TokenUsageAggregation;

/**
 * symfony/ai-platform is a soft dependency of this module, so these run only where it is
 * installed. Skipping beats failing: an install without the bridges is a supported setup.
 */
final class UsageNormalizerTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(TokenUsage::class)) {
            self::markTestSkipped('symfony/ai-platform is not installed.');
        }
    }

    public function test_it_adds_cache_read_and_write_to_the_prompt_for_anthropic(): void
    {
        $usage = new TokenUsage(
            promptTokens: 100,
            completionTokens: 50,
            cacheCreationTokens: 10,
            cacheReadTokens: 20,
        );

        $normalized = $this->normalizer()->normalize('anthropic', $usage);

        self::assertSame(130, $normalized->getPromptTokens());
    }

    public function test_it_includes_cache_tokens_in_the_total_for_anthropic(): void
    {
        $usage = new TokenUsage(
            promptTokens: 100,
            completionTokens: 50,
            cacheCreationTokens: 10,
            cacheReadTokens: 20,
        );

        $normalized = $this->normalizer()->normalize('anthropic', $usage);

        self::assertSame(180, $normalized->getTotalTokens());
    }

    /**
     * Anthropic's streamed usage arrives as an aggregation of two deltas: message_start carries the
     * prompt and cache counts, message_delta carries only the completion count once it is final, and
     * neither reports a total. The normalizer has to treat the merged result exactly like the
     * buffered, single-object usage the non-streaming path hands it.
     */
    public function test_it_normalizes_a_streamed_anthropic_aggregation(): void
    {
        $aggregation = new TokenUsageAggregation();
        $aggregation->add(new TokenUsage(promptTokens: 100, cacheCreationTokens: 10, cacheReadTokens: 20));
        $aggregation->add(new TokenUsage(completionTokens: 50));

        $normalized = $this->normalizer()->normalize('anthropic', $aggregation);

        self::assertSame(130, $normalized->getPromptTokens());
        self::assertSame(50, $normalized->getCompletionTokens());
        self::assertSame(180, $normalized->getTotalTokens());
        self::assertSame(20, $normalized->getCacheReadTokens());
        self::assertSame(10, $normalized->getCacheWriteTokens());
    }

    /**
     * A partial stream usage report must not turn "not reported" into a false zero: dropping the
     * prompt, read and write keys entirely (rather than passing 0) is what {@see TokenUsageAggregation::sum()}
     * treats as "nothing reported", and the normalizer has to preserve that rather than defaulting.
     */
    public function test_it_keeps_the_prompt_null_when_nothing_was_reported(): void
    {
        $usage = new TokenUsage(completionTokens: 50);

        $normalized = $this->normalizer()->normalize('anthropic', $usage);

        self::assertNull($normalized->getPromptTokens());
    }

    public function test_it_keeps_the_prompt_unchanged_for_open_ai(): void
    {
        $usage = new TokenUsage(promptTokens: 120, completionTokens: 45, cachedTokens: 30);

        $normalized = $this->normalizer()->normalize('openai', $usage);

        self::assertSame(120, $normalized->getPromptTokens());
    }

    public function test_it_maps_open_ai_cached_tokens_to_cache_reads(): void
    {
        $usage = new TokenUsage(promptTokens: 120, completionTokens: 45, cachedTokens: 30);

        $normalized = $this->normalizer()->normalize('openai', $usage);

        self::assertSame(30, $normalized->getCacheReadTokens());
    }

    /**
     * A generic chat-completions bridge (OpenRouter, LM Studio, DeepSeek, HuggingFace dialect)
     * reports its read count under the same name this module's own API uses, so it needs no
     * fallback to a combined figure at all.
     */
    public function test_it_maps_generic_cache_read_tokens_to_cache_reads(): void
    {
        $usage = new TokenUsage(promptTokens: 90, completionTokens: 10, cacheReadTokens: 15);

        $normalized = $this->normalizer()->normalize('openrouter', $usage);

        self::assertSame(15, $normalized->getCacheReadTokens());
    }

    public function test_it_leaves_cache_counts_null_when_the_provider_reports_no_cache(): void
    {
        $usage = new TokenUsage(promptTokens: 90, completionTokens: 10);

        $normalized = $this->normalizer()->normalize('openai', $usage);

        self::assertNull($normalized->getCacheReadTokens());
        self::assertNull($normalized->getCacheWriteTokens());
    }

    /**
     * A normalizer wired the way di.xml wires it, so cache-outside-prompt behavior matches
     * production.
     */
    private function normalizer(): UsageNormalizer
    {
        return new UsageNormalizer(new BridgeRegistry(['anthropic' => ['cache_outside_prompt' => true]]));
    }
}
