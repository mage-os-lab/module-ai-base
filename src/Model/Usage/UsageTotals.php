<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Usage;

use MageOS\AiBase\Api\Data\UsageTotalsInterface;

/**
 * Immutable value object for one aggregated set of token counts.
 *
 * Built only by {@see UsageStats}, which is the one class in this module that merges rows from
 * both usage tables into a single answer; nothing here talks to a repository or does any
 * aggregation of its own.
 */
class UsageTotals implements UsageTotalsInterface
{
    /**
     * @param int $calls {@see UsageTotalsInterface::getCalls()}
     * @param int $inputTokens {@see UsageTotalsInterface::getInputTokens()}
     * @param int $outputTokens {@see UsageTotalsInterface::getOutputTokens()}
     * @param int $totalTokens {@see UsageTotalsInterface::getTotalTokens()}
     * @param int|null $cacheReadTokens {@see UsageTotalsInterface::getCacheReadTokens()}
     * @param int|null $reasoningTokens {@see UsageTotalsInterface::getReasoningTokens()}
     * @param int|null $cacheWriteTokens {@see UsageTotalsInterface::getCacheWriteTokens()}
     * @param int $failedCalls {@see UsageTotalsInterface::getFailedCalls()}
     */
    public function __construct(
        private readonly int $calls,
        private readonly int $inputTokens,
        private readonly int $outputTokens,
        private readonly int $totalTokens,
        private readonly ?int $cacheReadTokens,
        private readonly ?int $reasoningTokens,
        private readonly ?int $cacheWriteTokens = null,
        private readonly int $failedCalls = 0,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getCalls(): int
    {
        return $this->calls;
    }

    /**
     * @inheritdoc
     */
    public function getInputTokens(): int
    {
        return $this->inputTokens;
    }

    /**
     * @inheritdoc
     */
    public function getOutputTokens(): int
    {
        return $this->outputTokens;
    }

    /**
     * @inheritdoc
     */
    public function getTotalTokens(): int
    {
        return $this->totalTokens;
    }

    /**
     * @inheritdoc
     */
    public function getCacheReadTokens(): ?int
    {
        return $this->cacheReadTokens;
    }

    /**
     * @inheritdoc
     */
    public function getCacheWriteTokens(): ?int
    {
        return $this->cacheWriteTokens;
    }

    /**
     * @inheritdoc
     */
    public function getReasoningTokens(): ?int
    {
        return $this->reasoningTokens;
    }

    /**
     * @inheritdoc
     */
    public function getFailedCalls(): int
    {
        return $this->failedCalls;
    }
}
