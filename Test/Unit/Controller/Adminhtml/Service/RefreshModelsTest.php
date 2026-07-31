<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Controller\Adminhtml\Service;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use MageOS\AiBase\AiServices\OpenAi;
use MageOS\AiBase\Api\AiServiceSelectorInterface;
use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\Data\AiServiceInterface;
use MageOS\AiBase\Controller\Adminhtml\Service\RefreshModels;
use MageOS\AiBase\Model\ModelList\Storage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MageOS\AiBase\Controller\Adminhtml\Service\RefreshModels
 *
 * Requires Magento\Backend classes (Backend\App\Action inheritance chain);
 * in the standalone module checkout run PHPUnit with a bootstrap that
 * autoloads the Magento\Backend module sources, otherwise the tests skip.
 */
final class RefreshModelsTest extends TestCase
{
    private RequestInterface&MockObject $request;
    private AiServiceSelectorInterface&MockObject $serviceSelector;
    private Storage&MockObject $storage;
    private OpenAi&MockObject $openAi;

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
        $this->serviceSelector = $this->createMock(AiServiceSelectorInterface::class);
        $this->storage = $this->createMock(Storage::class);

        $this->openAi = $this->createMock(OpenAi::class);
        $this->openAi->method('getCode')->willReturn('openai');
    }

    /**
     * Build the controller under test with the given registered service definitions.
     *
     * @param array<string, AiServiceConfigurationInterface> $services
     * @return RefreshModels
     */
    private function createSubject(array $services): RefreshModels
    {
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($this->request);

        $json = $this->createMock(Json::class);
        $json->method('setData')->willReturnCallback(function (array $data) use ($json) {
            $this->resultData = $data;
            return $json;
        });
        $jsonFactory = $this->createMock(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($json);

        return new RefreshModels($context, $jsonFactory, $this->serviceSelector, $this->storage, $services);
    }

    public function test_execute_rejects_missing_service_code(): void
    {
        $this->request->method('getParam')->with('service_code')->willReturn(null);

        $this->createSubject([])->execute();

        self::assertFalse($this->resultData['success']);
        self::assertSame('service_code is required', $this->resultData['error']);
    }

    public function test_execute_rejects_service_without_model_list_support(): void
    {
        $this->request->method('getParam')->with('service_code')->willReturn('azure');

        $azure = $this->createMock(AiServiceConfigurationInterface::class);
        $azure->method('getCode')->willReturn('azure');
        $this->serviceSelector->expects(self::never())->method('getByCode');
        $this->storage->expects(self::never())->method('save');

        $this->createSubject(['azure' => $azure])->execute();

        self::assertFalse($this->resultData['success']);
        self::assertSame('Model list refresh is not supported for this service.', $this->resultData['error']);
    }

    public function test_execute_reports_missing_saved_configuration(): void
    {
        $this->request->method('getParam')->with('service_code')->willReturn('openai');
        $this->serviceSelector->method('getByCode')->with('openai')->willReturn([]);
        $this->storage->expects(self::never())->method('save');

        $this->createSubject(['openai' => $this->openAi])->execute();

        self::assertFalse($this->resultData['success']);
        self::assertSame('No AI service configured for code "openai".', $this->resultData['error']);
    }

    public function test_execute_fetches_persists_and_returns_model_map(): void
    {
        $this->request->method('getParam')->with('service_code')->willReturn('openai');

        $configured = $this->createMock(AiServiceInterface::class);
        $configured->method('getConfiguration')->willReturn(['api_key' => 'sk-test', 'model' => 'gpt-4o']);
        $this->serviceSelector->method('getByCode')->with('openai')->willReturn([$configured]);

        $models = ['gpt-4o' => 'gpt-4o', 'o1' => 'o1'];
        $this->openAi->expects(self::once())->method('fetchModels')
            ->with(['api_key' => 'sk-test', 'model' => 'gpt-4o'])
            ->willReturn($models);
        $this->storage->expects(self::once())->method('save')->with('openai', $models);

        $this->createSubject(['openai' => $this->openAi])->execute();

        self::assertTrue($this->resultData['success']);
        self::assertSame(2, $this->resultData['count']);
        self::assertSame($models, $this->resultData['models']);
    }

    public function test_execute_passes_localized_exception_message_through(): void
    {
        $this->request->method('getParam')->with('service_code')->willReturn('openai');

        $configured = $this->createMock(AiServiceInterface::class);
        $configured->method('getConfiguration')->willReturn(['api_key' => 'bad']);
        $this->serviceSelector->method('getByCode')->with('openai')->willReturn([$configured]);

        $this->openAi->method('fetchModels')
            ->willThrowException(new LocalizedException(__('Request to %1 returned HTTP status %2.', 'x', 401)));
        $this->storage->expects(self::never())->method('save');

        $this->createSubject(['openai' => $this->openAi])->execute();

        self::assertFalse($this->resultData['success']);
        self::assertSame('Request to x returned HTTP status 401.', $this->resultData['error']);
    }

    public function test_execute_wraps_generic_throwable_in_generic_message(): void
    {
        $this->request->method('getParam')->with('service_code')->willReturn('openai');

        $configured = $this->createMock(AiServiceInterface::class);
        $configured->method('getConfiguration')->willReturn([]);
        $this->serviceSelector->method('getByCode')->with('openai')->willReturn([$configured]);

        $this->openAi->method('fetchModels')->willThrowException(new \RuntimeException('boom'));

        $this->createSubject(['openai' => $this->openAi])->execute();

        self::assertFalse($this->resultData['success']);
        self::assertSame('Model list refresh failed: boom', $this->resultData['error']);
    }
}
