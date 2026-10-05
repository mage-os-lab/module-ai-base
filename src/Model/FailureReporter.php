<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use MageOS\AiBase\Model\Client\AiAuthenticationException;
use MageOS\AiBase\Model\Client\AiInvalidRequestException;
use MageOS\AiBase\Model\Client\AiRateLimitedException;
use MageOS\AiBase\Model\Client\AiServiceException;
use MageOS\AiBase\Model\Client\AiTransientException;
use Psr\Log\LoggerInterface;

/**
 * Decides what a failed admin action may say on the page, and sends the rest to the log.
 *
 * The admin form writes whatever error text a controller returns straight into the page. A provider
 * or transport failure arrives with the HTTP client's own message, which commonly carries the full
 * request URL, and a base URL or proxy URL can carry a token or credentials in it. Those belong in
 * the log, where an administrator debugging the row can read them, not in a response that is cached,
 * proxied and screenshotted like any other page.
 *
 * One kind of message is shown as it is: a {@see LocalizedException} this module raised itself,
 * which is text the module wrote for the administrator ("No AI service configured for code ...",
 * "install package ..."). An {@see AiServiceException} is a LocalizedException too, but its text is
 * the provider's, so it is summarised by type instead. Anything untyped is treated as a transport
 * failure and summarised to the action that failed. Every failure is logged in full either way, so
 * the log line is where the debugging starts regardless of what the page said.
 */
class FailureReporter
{
    /**
     * @param LoggerInterface $logger
     */
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * Log the failure in full and return the text the admin form may show for it.
     *
     * @param Phrase $failureNotice What failed, without trailing punctuation ("Connection test
     *        failed"); it heads the log line and is the whole message when nothing more specific
     *        can be said
     * @param \Throwable $failure
     * @param array<string,mixed> $context Added to the log record, typically the row id and the
     *        service code the action was for
     * @return string
     */
    public function report(Phrase $failureNotice, \Throwable $failure, array $context = []): string
    {
        $this->logger->error(
            sprintf('%s: %s', $failureNotice->render(), $failure->getMessage()),
            $context + ['exception' => $failure],
        );

        if ($this->isWrittenForTheAdministrator($failure)) {
            return $failure->getMessage();
        }

        return $this->summarize($failureNotice, $failure)->render()
            . ' '
            . __('The full error was written to the log.')->render();
    }

    /**
     * Whether the message is this module's own words rather than a provider's or an HTTP client's.
     *
     * The client layer maps every provider failure to an {@see AiServiceException}, and everything
     * else this module throws as a plain LocalizedException is a sentence it wrote itself.
     *
     * @param \Throwable $failure
     * @return bool
     */
    private function isWrittenForTheAdministrator(\Throwable $failure): bool
    {
        return $failure instanceof LocalizedException && !$failure instanceof AiServiceException;
    }

    /**
     * What the administrator can do about the failure, by the type the client layer gave it.
     *
     * @param Phrase $failureNotice
     * @param \Throwable $failure
     * @return Phrase
     */
    private function summarize(Phrase $failureNotice, \Throwable $failure): Phrase
    {
        return match (true) {
            $failure instanceof AiAuthenticationException => __(
                'The provider rejected the API key. Check the key saved for this service.'
            ),
            $failure instanceof AiRateLimitedException => __(
                'The provider is rate limiting this account. Wait and try again.'
            ),
            $failure instanceof AiTransientException => __(
                'The provider could not be reached or answered with a server error. Check the base URL and try again.'
            ),
            $failure instanceof AiInvalidRequestException => __(
                'The provider rejected the request. Check the model saved for this service.'
            ),
            $failure instanceof AiServiceException => __('The provider returned an error.'),
            default => __('%1.', $failureNotice->render()),
        };
    }
}
