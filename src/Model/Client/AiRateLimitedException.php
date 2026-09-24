<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Client;

use Magento\Framework\Phrase;

/**
 * The provider throttled this call: too many requests, too fast, on the configured account.
 *
 * Worth a delayed retry, unlike {@see AiAuthenticationException} or
 * {@see AiInvalidRequestException}. {@see AiExceptionMapper} builds this from symfony/ai's
 * `RateLimitExceededException`, carrying its `getRetryAfter()` value through unchanged: null on
 * every bridge that reports the failure but not a wait time (Azure, OpenRouter, LM Studio; see
 * docs/CONSUMING.md), a second count on the bridges that do.
 */
class AiRateLimitedException extends AiServiceException
{
    /**
     * @param Phrase $phrase
     * @param int|null $retryAfter Seconds the provider asked callers to wait, or null when it did
     *        not say
     * @param \Exception|null $cause
     */
    public function __construct(
        Phrase $phrase,
        private readonly ?int $retryAfter,
        ?\Exception $cause = null,
    ) {
        parent::__construct($phrase, $cause);
    }

    /**
     * Seconds to wait before retrying, or null when the provider did not say.
     *
     * @return int|null
     */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
