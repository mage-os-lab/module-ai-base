<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Chat;

use MageOS\AiBase\Model\Chat\TokenUsage;
use PHPUnit\Framework\TestCase;

final class TokenUsageTest extends TestCase
{
    public function test_it_returns_the_cached_token_count_the_provider_reported(): void
    {
        $usage = new TokenUsage(120, 45, null, 30);

        self::assertSame(30, $usage->getCachedTokens());
    }

    public function test_it_returns_null_for_cached_tokens_when_the_provider_reported_none(): void
    {
        $usage = new TokenUsage(120, 45);

        self::assertNull($usage->getCachedTokens());
    }

    public function test_it_returns_the_reasoning_token_count_the_provider_reported(): void
    {
        $usage = new TokenUsage(120, 45, null, null, 18);

        self::assertSame(18, $usage->getReasoningTokens());
    }

    public function test_it_returns_null_for_reasoning_tokens_when_the_provider_reported_none(): void
    {
        $usage = new TokenUsage(120, 45);

        self::assertNull($usage->getReasoningTokens());
    }

    /**
     * Cached and reasoning tokens are subsets of prompt and completion, not additions to them, so
     * a provider-reported total must stay exactly what the provider sent regardless of whether
     * either subset count is also present.
     */
    public function test_it_leaves_the_total_unchanged_when_cached_and_reasoning_counts_are_present(): void
    {
        $usage = new TokenUsage(120, 45, 165, 30, 18);

        self::assertSame(165, $usage->getTotalTokens());
    }

    /**
     * Cached and reasoning tokens are already counted inside prompt and completion, so the
     * fallback must not add them a second time on top of prompt plus completion.
     */
    public function test_it_still_falls_back_to_prompt_plus_completion_when_no_total_was_reported(): void
    {
        $usage = new TokenUsage(120, 45, null, 30, 18);

        self::assertSame(165, $usage->getTotalTokens());
    }

    /**
     * Existing call sites, notably SymfonyAiClient::toAiBaseUsage(), construct positionally with
     * only prompt, completion and total. The new parameters must default so those sites keep
     * compiling and behaving exactly as before.
     */
    public function test_it_constructs_from_three_positional_arguments_exactly_as_before(): void
    {
        $usage = new TokenUsage(120, 45, 165);

        self::assertSame(120, $usage->getPromptTokens());
        self::assertSame(45, $usage->getCompletionTokens());
        self::assertSame(165, $usage->getTotalTokens());
        self::assertNull($usage->getCachedTokens());
        self::assertNull($usage->getReasoningTokens());
    }
}
