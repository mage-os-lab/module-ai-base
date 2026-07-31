<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\AiServices;

require_once __DIR__ . '/../Stubs/FieldDescriptorInterfaceFactoryStub.php';

use Magento\Framework\Exception\LocalizedException;
use MageOS\AiBase\AiServices\Ollama;
use MageOS\AiBase\AiServices\OpenAi;
use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;
use MageOS\AiBase\Model\ModelList\HttpFetcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MageOS\AiBase\AiServices\OpenAi
 * @covers \MageOS\AiBase\AiServices\Ollama
 */
final class ModelListFetchTest extends TestCase
{
    private FieldDescriptorInterfaceFactory&MockObject $fieldFactory;
    private HttpFetcher&MockObject $fetcher;

    protected function setUp(): void
    {
        $this->fieldFactory = $this->createMock(FieldDescriptorInterfaceFactory::class);
        $this->fetcher = $this->createMock(HttpFetcher::class);
    }

    public function test_openai_fetch_models_parses_data_list_with_bearer_header(): void
    {
        $this->fetcher->expects(self::once())->method('getJson')
            ->with('https://api.openai.com/v1/models', ['Authorization' => 'Bearer sk-test'])
            ->willReturn(['data' => [
                ['id' => 'gpt-4o'],
                ['id' => 'o1'],
                ['object' => 'model'], // entry without id is skipped
            ]]);

        $service = new OpenAi($this->fieldFactory, $this->fetcher);

        self::assertSame(
            ['gpt-4o' => 'gpt-4o', 'o1' => 'o1'],
            $service->fetchModels(['api_key' => 'sk-test']),
        );
    }

    public function test_openai_fetch_models_throws_on_missing_data_list(): void
    {
        $this->fetcher->method('getJson')->willReturn(['error' => ['message' => 'nope']]);

        $service = new OpenAi($this->fieldFactory, $this->fetcher);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('missing "data" list');

        $service->fetchModels(['api_key' => 'sk-test']);
    }

    public function test_ollama_fetch_models_uses_default_base_url_and_tags_shape(): void
    {
        $this->fetcher->expects(self::once())->method('getJson')
            ->with('http://localhost:11434/api/tags')
            ->willReturn(['models' => [
                ['name' => 'llama3:8b'],
                ['name' => 'mistral:latest'],
            ]]);

        $service = new Ollama($this->fieldFactory, $this->fetcher);

        self::assertSame(
            ['llama3:8b' => 'llama3:8b', 'mistral:latest' => 'mistral:latest'],
            $service->fetchModels([]),
        );
    }

    public function test_ollama_fetch_models_uses_configured_base_url_without_trailing_slash(): void
    {
        $this->fetcher->expects(self::once())->method('getJson')
            ->with('http://ollama.internal:11434/api/tags')
            ->willReturn(['models' => []]);

        $service = new Ollama($this->fieldFactory, $this->fetcher);

        self::assertSame([], $service->fetchModels(['base_url' => 'http://ollama.internal:11434/']));
    }

    public function test_ollama_fetch_models_throws_on_missing_models_list(): void
    {
        $this->fetcher->method('getJson')->willReturn(['tags' => []]);

        $service = new Ollama($this->fieldFactory, $this->fetcher);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('missing "models" list');

        $service->fetchModels([]);
    }
}
