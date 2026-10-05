<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model;

use Magento\Framework\Exception\LocalizedException;
use MageOS\AiBase\Model\Client\AiAuthenticationException;
use MageOS\AiBase\Model\Client\AiContentFilteredException;
use MageOS\AiBase\Model\Client\AiInvalidRequestException;
use MageOS\AiBase\Model\Client\AiRateLimitedException;
use MageOS\AiBase\Model\Client\AiServiceException;
use MageOS\AiBase\Model\Client\AiToolCallException;
use MageOS\AiBase\Model\Client\AiTransientException;
use MageOS\AiBase\Model\FailureReporter;
use MageOS\AiBase\Test\Unit\Stubs\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MageOS\AiBase\Model\FailureReporter
 */
final class FailureReporterTest extends TestCase
{
    private const LEAKY_URL = 'https://unreachable.example/v1?token=super-secret-value';
    private const LOG_HINT = 'The full error was written to the log.';

    private RecordingLogger $logger;
    private FailureReporter $subject;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->subject = new FailureReporter($this->logger);
    }

    public function test_report_logs_every_failure_in_full_with_the_context_given(): void
    {
        $failure = new \RuntimeException('cURL error 7 for ' . self::LEAKY_URL);

        $this->subject->report(__('Connection test failed'), $failure, ['service_id' => '_row_a']);

        $records = $this->logger->getRecords();
        self::assertCount(1, $records);
        self::assertSame('error', $records[0]['level']);
        self::assertSame('Connection test failed: cURL error 7 for ' . self::LEAKY_URL, $records[0]['message']);
        self::assertSame('_row_a', $records[0]['context']['service_id']);
        self::assertSame($failure, $records[0]['context']['exception']);
    }

    /**
     * A plain LocalizedException is a sentence this module wrote for the administrator, such as the
     * client factory saying which bridge package to install; hiding it would hide the fix.
     */
    public function test_report_shows_the_modules_own_message_as_it_is(): void
    {
        $shown = $this->subject->report(
            __('Connection test failed'),
            new LocalizedException(__('No AI service configured for code "openai".')),
        );

        self::assertSame('No AI service configured for code "openai".', $shown);
    }

    public function test_report_summarises_an_untyped_failure_to_what_was_attempted(): void
    {
        $shown = $this->subject->report(
            __('Connection test failed'),
            new \RuntimeException('cURL error 7 for ' . self::LEAKY_URL),
        );

        self::assertSame('Connection test failed. ' . self::LOG_HINT, $shown);
        self::assertStringNotContainsString('super-secret-value', $shown);
    }

    /**
     * @return iterable<string, array{0: AiServiceException, 1: string}>
     */
    public static function providerFailures(): iterable
    {
        $phrase = __('AI request to service "x" failed: 401 at %1', self::LEAKY_URL);

        yield 'rejected key' => [
            new AiAuthenticationException($phrase),
            'The provider rejected the API key. Check the key saved for this service.',
        ];
        yield 'rate limited' => [
            new AiRateLimitedException($phrase, 30),
            'The provider is rate limiting this account. Wait and try again.',
        ];
        yield 'unreachable or server error' => [
            new AiTransientException($phrase),
            'The provider could not be reached or answered with a server error. Check the base URL and try again.',
        ];
        yield 'rejected request' => [
            new AiInvalidRequestException($phrase),
            'The provider rejected the request. Check the model saved for this service.',
        ];
        yield 'content filtered, a rejected request' => [
            new AiContentFilteredException($phrase),
            'The provider rejected the request. Check the model saved for this service.',
        ];
        yield 'tool call failure' => [
            new AiToolCallException($phrase),
            'The provider returned an error.',
        ];
        yield 'unrecognised provider failure' => [
            new AiServiceException($phrase),
            'The provider returned an error.',
        ];
    }

    #[DataProvider('providerFailures')]
    public function test_report_summarises_a_provider_failure_by_type_and_keeps_its_text_out_of_the_page(
        AiServiceException $failure,
        string $expectedSummary,
    ): void {
        $shown = $this->subject->report(__('Connection test failed'), $failure);

        self::assertSame($expectedSummary . ' ' . self::LOG_HINT, $shown);
        self::assertStringNotContainsString('super-secret-value', $shown);
        self::assertStringContainsString('super-secret-value', $this->logger->getMessages());
    }
}
