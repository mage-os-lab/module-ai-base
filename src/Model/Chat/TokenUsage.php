<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Chat;

use MageOS\AiBase\Api\Data\TokenUsageInterface;

class TokenUsage implements TokenUsageInterface
{
    /**
     * @param int|null $promptTokens
     * @param int|null $completionTokens
     * @param int|null $totalTokens Provider-reported total, if it reported one
     * @param int|null $cachedTokens Subset of $promptTokens served from the provider's prompt cache
     * @param int|null $reasoningTokens Subset of $completionTokens spent on internal reasoning
     */
    public function __construct(
        private readonly ?int $promptTokens = null,
        private readonly ?int $completionTokens = null,
        private readonly ?int $totalTokens = null,
        private readonly ?int $cachedTokens = null,
        private readonly ?int $reasoningTokens = null,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getPromptTokens(): ?int
    {
        return $this->promptTokens;
    }

    /**
     * @inheritdoc
     */
    public function getCompletionTokens(): ?int
    {
        return $this->completionTokens;
    }

    /**
     * @inheritdoc
     */
    public function getTotalTokens(): ?int
    {
        if ($this->totalTokens !== null) {
            return $this->totalTokens;
        }
        if ($this->promptTokens === null && $this->completionTokens === null) {
            return null;
        }

        return ($this->promptTokens ?? 0) + ($this->completionTokens ?? 0);
    }

    /**
     * @inheritdoc
     */
    public function getCachedTokens(): ?int
    {
        return $this->cachedTokens;
    }

    /**
     * @inheritdoc
     */
    public function getReasoningTokens(): ?int
    {
        return $this->reasoningTokens;
    }
}
