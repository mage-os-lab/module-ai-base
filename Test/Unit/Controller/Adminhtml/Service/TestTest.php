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

        $this->subject = new Test($context, $this->jsonFactory, $this->clientFactory);
    }

    public function test_execute_returns_success_with_latency_and_response_snippet(): void
    {
        $this->request->method('getParam')->with('service_code')->willReturn('openai');

        $client = $this->createMock(AiClientInterface::class);
        $client->expects(self::once())->method('complete')
            ->with('Reply with the single word: OK', ['max_tokens' => 16])
            ->willReturn(str_repeat('a', 150));
        $this->clientFactory->expects(self::once())->method('create')
            ->with('openai')->willReturn($client);

        $this->subject->execute();

        self::assertTrue($this->resultData['success']);
        self::assertIsInt($this->resultData['latency_ms']);
        self::assertSame(str_repeat('a', 100), $this->resultData['response']);
    }

    public function test_execute_passes_localized_exception_message_through(): void
    {
        $this->request->method('getParam')->with('service_code')->willReturn('openai');

        $this->clientFactory->expects(self::once())->method('create')
            ->with('openai')
            ->willThrowException(new LocalizedException(__('No AI service configured for code "openai".')));

        $this->subject->execute();

        self::assertFalse($this->resultData['success']);
        self::assertSame('No AI service configured for code "openai".', $this->resultData['error']);
    }

    public function test_execute_wraps_generic_throwable_in_generic_message(): void
    {
        $this->request->method('getParam')->with('service_code')->willReturn('openai');

        $client = $this->createMock(AiClientInterface::class);
        $client->method('complete')->willThrowException(new \RuntimeException('cURL error 7'));
        $this->clientFactory->method('create')->with('openai')->willReturn($client);

        $this->subject->execute();

        self::assertFalse($this->resultData['success']);
        self::assertSame('Connection test failed: cURL error 7', $this->resultData['error']);
    }

    public function test_execute_rejects_missing_service_code(): void
    {
        $this->request->method('getParam')->with('service_code')->willReturn(null);
        $this->clientFactory->expects(self::never())->method('create');

        $this->subject->execute();

        self::assertFalse($this->resultData['success']);
        self::assertSame('service_code is required', $this->resultData['error']);
    }
}
