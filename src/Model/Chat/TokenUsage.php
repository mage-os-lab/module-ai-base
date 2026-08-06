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
     */
    public function __construct(
        private readonly ?int $promptTokens = null,
        private readonly ?int $completionTokens = null,
        private readonly ?int $totalTokens = null,
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
}
