<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Client;

use MageOS\AiBase\Model\Chat\TokenUsage;

/**
 * Normalizes a symfony/ai-platform usage object into this module's own token counts.
 *
 * Bridges disagree on whether a cache read or write is already counted inside the prompt they
 * report: the Anthropic bridge's `input_tokens` excludes both, while every other bundled bridge's
 * prompt count already includes whatever it reports as cached. Left alone, an Anthropic call with
 * a warm cache would under-report its input tokens (and therefore the total) by exactly the part
 * the provider actually billed for. Normalizing here, once, means every consumer of
 * {@see \MageOS\AiBase\Api\Data\ChatResponseInterface::getUsage()} sees an input count that always
 * includes what was billed, on every provider, without having to know which one it was.
 *
 * The bridge's own semantics are read off {@see BridgeRegistry::isCacheOutsidePrompt()} rather than
 * hardcoded to one provider name, so a third-party bridge registered from its own `di.xml` can
 * declare the same behavior.
 */
class UsageNormalizer
{
    /**
     * @param BridgeRegistry $bridgeRegistry Says which bridge counts cache tokens outside its
     *        reported prompt.
     */
    public function __construct(
        private readonly BridgeRegistry $bridgeRegistry,
    ) {
    }

    /**
     * Translate a platform usage object into this module's own, per the target service's bridge.
     *
     * @param string $serviceCode
     * @param \Symfony\AI\Platform\TokenUsage\TokenUsageInterface $usage
     * @return TokenUsage
     */
    public function normalize(string $serviceCode, object $usage): TokenUsage
    {
        $cacheOutsidePrompt = $this->bridgeRegistry->isCacheOutsidePrompt($serviceCode);
        $cacheReadTokens = $this->extractCacheReadTokens($usage, $cacheOutsidePrompt);
        $cacheWriteTokens = $this->extractCacheWriteTokens($usage);
        $promptTokens = $this
            ->normalizePromptTokens($usage, $cacheOutsidePrompt, $cacheReadTokens, $cacheWriteTokens);

        return new TokenUsage(
            promptTokens: $promptTokens,
            completionTokens: $usage->getCompletionTokens(),
            totalTokens: $this->normalizeTotalTokens($usage, $cacheOutsidePrompt),
            cacheReadTokens: $cacheReadTokens,
            reasoningTokens: $this->extractReasoningTokens($usage),
            cacheWriteTokens: $cacheWriteTokens,
        );
    }

    /**
     * Tokens served from the provider's prompt cache, however this bridge reports them.
     *
     * A bridge that names the count directly (`getCacheReadTokens()`) is trusted first. One that
     * only reports a single combined figure (`getCachedTokens()`) is asked for it instead, but only
     * when the bridge counts cache inside its prompt: a bridge that counts cache outside the prompt
     * and also happens to report no read count at all must stay null rather than silently pick up a
     * combined figure that was never validated against that bridge's own semantics.
     *
     * Guarded by method_exists rather than trusted from the interface directly: the component is
     * experimental and carries no BC promise, so a future or older bridge's usage object is not
     * assumed to keep either method just because it satisfies the interface checked by the caller
     * today.
     *
     * @param \Symfony\AI\Platform\TokenUsage\TokenUsageInterface $usage
     * @param bool $cacheOutsidePrompt
     * @return int|null
     */
    private function extractCacheReadTokens(object $usage, bool $cacheOutsidePrompt): ?int
    {
        // @phpstan-ignore function.alreadyNarrowedType
        $cacheReadTokens = method_exists($usage, 'getCacheReadTokens') ? $usage->getCacheReadTokens() : null;
        if ($cacheReadTokens !== null || $cacheOutsidePrompt) {
            return $cacheReadTokens;
        }

        // @phpstan-ignore function.alreadyNarrowedType
        return method_exists($usage, 'getCachedTokens') ? $usage->getCachedTokens() : null;
    }

    /**
     * Tokens written into the provider's prompt cache for reuse by a later request.
     *
     * See {@see extractCacheReadTokens()} for why the guard stays despite PHPStan's certainty today.
     *
     * @param \Symfony\AI\Platform\TokenUsage\TokenUsageInterface $usage
     * @return int|null
     */
    private function extractCacheWriteTokens(object $usage): ?int
    {
        // @phpstan-ignore function.alreadyNarrowedType
        return method_exists($usage, 'getCacheCreationTokens') ? $usage->getCacheCreationTokens() : null;
    }

    /**
     * Tokens the model spent reasoning before its completion, when the bridge reports them.
     *
     * The platform's own vocabulary calls this "thinking", not "reasoning"; this module's naming
     * follows the OpenAI-style term the rest of its API already uses.
     *
     * @param \Symfony\AI\Platform\TokenUsage\TokenUsageInterface $usage
     * @return int|null
     */
    private function extractReasoningTokens(object $usage): ?int
    {
        // @phpstan-ignore function.alreadyNarrowedType
        return method_exists($usage, 'getThinkingTokens') ? $usage->getThinkingTokens() : null;
    }

    /**
     * The prompt count a consumer should see: as reported, or with cache folded in.
     *
     * A bridge that already counts cache inside its prompt is passed through unchanged; folding the
     * read and write counts in again would double them. A bridge that counts cache outside its
     * prompt needs both added in, but only when at least one of the three was actually reported:
     * null stays null rather than turning "this bridge reported nothing" into a false zero.
     *
     * @param \Symfony\AI\Platform\TokenUsage\TokenUsageInterface $usage
     * @param bool $cacheOutsidePrompt
     * @param int|null $cacheReadTokens
     * @param int|null $cacheWriteTokens
     * @return int|null
     */
    private function normalizePromptTokens(
        object $usage,
        bool $cacheOutsidePrompt,
        ?int $cacheReadTokens,
        ?int $cacheWriteTokens,
    ): ?int {
        $promptTokens = $usage->getPromptTokens();
        if (!$cacheOutsidePrompt) {
            return $promptTokens;
        }
        if ($promptTokens === null && $cacheReadTokens === null && $cacheWriteTokens === null) {
            return null;
        }

        return ($promptTokens ?? 0) + ($cacheReadTokens ?? 0) + ($cacheWriteTokens ?? 0);
    }

    /**
     * The total a consumer should see: the provider's own, or none at all.
     *
     * A bridge that counts cache inside its prompt reports a total that already covers it, so that
     * total is kept exactly as reported, falling back as {@see TokenUsage::getTotalTokens()} always
     * has. A bridge that counts cache outside its prompt reports a total that excludes it too (when
     * it reports one at all), which would under-count again if kept; null here instead lets that
     * same fallback compute prompt-plus-completion from the already-normalized prompt.
     *
     * @param \Symfony\AI\Platform\TokenUsage\TokenUsageInterface $usage
     * @param bool $cacheOutsidePrompt
     * @return int|null
     */
    private function normalizeTotalTokens(object $usage, bool $cacheOutsidePrompt): ?int
    {
        return $cacheOutsidePrompt ? null : $usage->getTotalTokens();
    }
}
