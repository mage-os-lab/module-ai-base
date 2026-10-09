<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\AiServices;

require_once __DIR__ . '/../Stubs/FieldDescriptorInterfaceFactoryStub.php';

use Magento\Framework\Exception\LocalizedException;
use MageOS\AiBase\AiServices\Anthropic;
use MageOS\AiBase\AiServices\Google;
use MageOS\AiBase\AiServices\Ollama;
use MageOS\AiBase\AiServices\OpenAi;
use MageOS\AiBase\AiServices\Opper;
use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;
use MageOS\AiBase\Model\ModelList\HttpFetcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MageOS\AiBase\AiServices\OpenAi
 * @covers \MageOS\AiBase\AiServices\Ollama
 * @covers \MageOS\AiBase\AiServices\Anthropic
 * @covers \MageOS\AiBase\AiServices\Google
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

    public function test_opper_fetches_key_scoped_chat_models_pools_and_routes(): void
    {
        $this->fetcher->expects(self::once())->method('getJson')
            ->with('https://api.opper.ai/v3/compat/models', ['Authorization' => 'Bearer op-key'])
            ->willReturn(['data' => [
                ['id' => 'anthropic/claude-sonnet-4.5', 'opper' => ['type' => 'llm', 'kind' => 'model']],
                ['id' => 'claude-sonnet-4.5', 'opper' => ['type' => 'llm', 'kind' => 'pool']],
                ['id' => 'dynamic/support', 'opper' => ['kind' => 'dynamic_route']],
                ['id' => 'openai/text-embedding-3-small', 'opper' => ['type' => 'embedding']],
                ['object' => 'model'],
                ['id' => ''],
                'invalid',
            ]]);

        $service = new Opper($this->fieldFactory, $this->fetcher);

        self::assertSame([
            'anthropic/claude-sonnet-4.5' => 'anthropic/claude-sonnet-4.5',
            'claude-sonnet-4.5' => 'claude-sonnet-4.5',
            'dynamic/support' => 'dynamic/support',
        ], $service->fetchModels(['api_key' => 'op-key']));
    }

    public function test_opper_rejects_a_missing_model_list(): void
    {
        $this->fetcher->method('getJson')->willReturn(['error' => ['message' => 'nope']]);
        $service = new Opper($this->fieldFactory, $this->fetcher);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('missing "data" list');

        $service->fetchModels(['api_key' => 'op-key']);
    }

    public function test_anthropic_fetch_models_uses_default_base_url_and_prefers_display_names(): void
    {
        $this->fetcher->expects(self::once())->method('getJson')
            ->with('https://api.anthropic.com/v1/models', [
                'x-api-key' => 'sk-ant-test',
                'anthropic-version' => '2023-06-01',
            ])
            ->willReturn(['data' => [
                ['id' => 'claude-sonnet-4-6', 'display_name' => 'Claude Sonnet 4.6'],
                ['id' => 'claude-opus-4-7'],
            ]]);

        $service = new Anthropic($this->fieldFactory, $this->fetcher);

        self::assertSame(
            ['claude-sonnet-4-6' => 'Claude Sonnet 4.6', 'claude-opus-4-7' => 'claude-opus-4-7'],
            $service->fetchModels(['api_key' => 'sk-ant-test']),
        );
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

    public function test_google_fetch_models_keeps_chat_models_and_strips_name_prefix(): void
    {
        $this->fetcher->expects(self::once())->method('getJson')
            ->with(
                'https://generativelanguage.googleapis.com/v1beta/models?pageSize=1000',
                ['x-goog-api-key' => 'gm-test']
            )
            ->willReturn(['models' => [
                [
                    'name' => 'models/gemini-2.5-pro',
                    'displayName' => 'Gemini 2.5 Pro',
                    'supportedGenerationMethods' => ['generateContent', 'countTokens'],
                ],
                [
                    'name' => 'models/gemini-embedding-001',
                    'displayName' => 'Gemini Embedding 001',
                    'supportedGenerationMethods' => ['embedContent'],
                ],
                ['name' => 'models/gemini-2.5-flash', 'supportedGenerationMethods' => ['generateContent']],
                ['name' => 'models/no-methods', 'displayName' => 'No Methods'],
            ]]);

        $service = new Google($this->fieldFactory, $this->fetcher);

        self::assertSame(
            ['gemini-2.5-pro' => 'Gemini 2.5 Pro', 'gemini-2.5-flash' => 'gemini-2.5-flash'],
            $service->fetchModels(['api_key' => 'gm-test']),
        );
    }

    public function test_google_fetch_models_follows_next_page_token(): void
    {
        $urls = [];
        $this->fetcher->expects(self::exactly(2))->method('getJson')
            ->willReturnCallback(function (string $url) use (&$urls): array {
                $urls[] = $url;

                return count($urls) === 1
                    ? [
                        'models' => [
                            ['name' => 'models/gemini-a', 'supportedGenerationMethods' => ['generateContent']],
                        ],
                        'nextPageToken' => 'page/2',
                    ]
                    : [
                        'models' => [
                            ['name' => 'models/gemini-b', 'supportedGenerationMethods' => ['generateContent']],
                        ],
                    ];
            });

        $service = new Google($this->fieldFactory, $this->fetcher);

        self::assertSame(
            ['gemini-a' => 'gemini-a', 'gemini-b' => 'gemini-b'],
            $service->fetchModels(['api_key' => 'gm-test']),
        );
        self::assertSame(
            [
                'https://generativelanguage.googleapis.com/v1beta/models?pageSize=1000',
                'https://generativelanguage.googleapis.com/v1beta/models?pageSize=1000&pageToken=page%2F2',
            ],
            $urls,
        );
    }

    public function test_google_fetch_models_stops_when_the_page_token_repeats(): void
    {
        $this->fetcher->expects(self::exactly(2))->method('getJson')
            ->willReturn(['models' => [], 'nextPageToken' => 'same']);

        $service = new Google($this->fieldFactory, $this->fetcher);

        self::assertSame([], $service->fetchModels(['api_key' => 'gm-test']));
    }

    public function test_google_fetch_models_throws_on_missing_models_list(): void
    {
        $this->fetcher->method('getJson')->willReturn(['error' => ['message' => 'nope']]);

        $service = new Google($this->fieldFactory, $this->fetcher);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('missing "models" list');

        $service->fetchModels(['api_key' => 'gm-test']);
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
