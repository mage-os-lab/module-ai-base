<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Controller\Adminhtml\Service;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use MageOS\AiBase\Api\AiClientFactoryInterface;
use MageOS\AiBase\Api\AiClientInterface;
use MageOS\AiBase\Controller\Adminhtml\Service\Test;
use MageOS\AiBase\Model\Client\AiAuthenticationException;
use MageOS\AiBase\Model\FailureReporter;
use MageOS\AiBase\Test\Unit\Stubs\RecordingLogger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MageOS\AiBase\Controller\Adminhtml\Service\Test
 *
 * Requires Magento\Backend classes (Backend\App\Action inheritance chain);
 * in the standalone module checkout run PHPUnit with a bootstrap that
 * autoloads the Magento\Backend module sources, otherwise the tests skip.
 */
final class TestTest extends TestCase
{
    private RequestInterface&MockObject $request;
    private JsonFactory&MockObject $jsonFactory;
    private AiClientFactoryInterface&MockObject $clientFactory;
    private RecordingLogger $logger;
    private Test $subject;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $resultData = null;

    protected function setUp(): void
    {
        if (!class_exists(\Magento\Backend\App\Action::class)) {
            self::markTestSkipped('Magento\Backend is not available in this environment.');
        }

        $this->request = $this->createMock(RequestInterface::class);
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($this->request);

        $json = $this->createMock(Json::class);
        $json->method('setData')->willReturnCallback(function (array $data) use ($json) {
            $this->resultData = $data;
            return $json;
        });
        $this->jsonFactory = $this->createMock(JsonFactory::class);
        $this->jsonFactory->method('create')->willReturn($json);

        $this->clientFactory = $this->createMock(AiClientFactoryInterface::class);

        $this->logger = new RecordingLogger();
        $this->subject = new Test(
            $context,
            $this->jsonFactory,
            $this->clientFactory,
            new FailureReporter($this->logger),
        );
    }

    /**
     * @param array<string, string|null> $params
     * @return void
     */
    private function stubParams(array $params): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static fn (string $name) => $params[$name] ?? null
        );
    }

    public function test_execute_returns_success_with_latency_and_response_snippet(): void
    {
        $this->stubParams(['service_id' => '_row_a', 'service_code' => 'openai']);

        $client = $this->createMock(AiClientInterface::class);
        $client->expects(self::once())->method('complete')
            ->with('Reply with the single word: OK', ['max_tokens' => 16])
            ->willReturn(str_repeat('a', 150));
        $this->clientFactory->expects(self::once())->method('createById')
            ->with('_row_a')->willReturn($client);

        $this->subject->execute();

        self::assertTrue($this->resultData['success']);
        self::assertIsInt($this->resultData['latency_ms']);
        self::assertSame(str_repeat('a', 100), $this->resultData['response']);
    }

    public function test_execute_passes_localized_exception_message_through(): void
    {
        $this->stubParams(['service_id' => '_row_a', 'service_code' => 'openai']);

        $this->clientFactory->expects(self::once())->method('createById')
            ->with('_row_a')
            ->willThrowException(new LocalizedException(__('No AI service configured for code "openai".')));

        $this->subject->execute();

        self::assertFalse($this->resultData['success']);
        self::assertSame('No AI service configured for code "openai".', $this->resultData['error']);
    }

    /**
     * An untyped failure is the HTTP client's own text, which routinely names the request URL. It
     * goes to the log, with the row it was for, and the page gets told only that the test failed.
     */
    public function test_execute_keeps_an_untyped_failure_out_of_the_page_and_logs_it(): void
    {
        $this->stubParams(['service_id' => '_row_a', 'service_code' => 'openai']);

        $client = $this->createMock(AiClientInterface::class);
        $client->method('complete')->willThrowException(
            new \RuntimeException('cURL error 7 for https://unreachable.example/v1?token=super-secret-value')
        );
        $this->clientFactory->method('createById')->with('_row_a')->willReturn($client);

        $this->subject->execute();

        self::assertFalse($this->resultData['success']);
        self::assertSame(
            'Connection test failed. The full error was written to the log.',
            $this->resultData['error']
        );
        self::assertStringContainsString('super-secret-value', $this->logger->getMessages());
        self::assertSame('_row_a', $this->logger->getRecords()[0]['context']['service_id']);
    }

    /**
     * The scenario from issue #52: a base URL carrying a token in its query string, echoed back
     * into the admin page by the provider's error. The typed exception says what kind of failure
     * it was, and that is all the page may repeat.
     */
    public function test_execute_shows_a_provider_failure_by_kind_without_its_text(): void
    {
        $this->stubParams(['service_id' => '_row_a', 'service_code' => 'openai_compatible']);

        $client = $this->createMock(AiClientInterface::class);
        $client->method('complete')->willThrowException(new AiAuthenticationException(
            __('AI request to service "openai_compatible" failed: HTTP 401 from %1', 'https://gw.example/v1?token=super-secret-value')
        ));
        $this->clientFactory->method('createById')->with('_row_a')->willReturn($client);

        $this->subject->execute();

        self::assertFalse($this->resultData['success']);
        self::assertSame(
            'The provider rejected the API key. Check the key saved for this service. '
            . 'The full error was written to the log.',
            $this->resultData['error']
        );
        self::assertStringContainsString('super-secret-value', $this->logger->getMessages());
    }

    public function test_execute_rejects_a_request_naming_no_row_and_no_code(): void
    {
        $this->stubParams([]);
        $this->clientFactory->expects(self::never())->method('create');
        $this->clientFactory->expects(self::never())->method('createById');

        $this->subject->execute();

        self::assertFalse($this->resultData['success']);
        self::assertSame('service_id is required', $this->resultData['error']);
    }

    /**
     * The button sits in a row, and two rows of the same provider are the setup row ids exist for.
     * Resolving by code would test the first row's credentials from the second row's button and
     * report the result against the key the administrator is looking at.
     */
    public function test_execute_tests_the_row_the_button_belongs_to_not_the_first_of_its_code(): void
    {
        $this->stubParams(['service_id' => '_second_openai_row', 'service_code' => 'openai']);

        $client = $this->createMock(AiClientInterface::class);
        $client->method('complete')->willReturn('OK');
        $this->clientFactory->expects(self::once())->method('createById')->with('_second_openai_row')
            ->willReturn($client);
        $this->clientFactory->expects(self::never())->method('create');

        $this->subject->execute();

        self::assertTrue($this->resultData['success']);
    }

    /**
     * A caller that has only a service code, which is every caller written against the previous
     * request shape, still resolves rather than failing.
     */
    public function test_execute_falls_back_to_the_service_code_when_no_row_is_named(): void
    {
        $this->stubParams(['service_code' => 'openai']);

        $client = $this->createMock(AiClientInterface::class);
        $client->method('complete')->willReturn('OK');
        $this->clientFactory->expects(self::once())->method('create')->with('openai')->willReturn($client);
        $this->clientFactory->expects(self::never())->method('createById');

        $this->subject->execute();

        self::assertTrue($this->resultData['success']);
    }
}
