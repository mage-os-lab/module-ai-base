<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\AiServices;

require_once __DIR__ . '/../Stubs/FieldDescriptorInterfaceFactoryStub.php';

use Magento\Framework\Exception\LocalizedException;
use MageOS\AiBase\AiServices\Anthropic;
use MageOS\AiBase\AiServices\Ollama;
use MageOS\AiBase\AiServices\OpenAi;
use MageOS\AiBase\AiServices\OpenCode;
use MageOS\AiBase\AiServices\OpenCodeCustom;
use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;
use MageOS\AiBase\Model\ModelList\HttpFetcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MageOS\AiBase\AiServices\OpenAi
 * @covers \MageOS\AiBase\AiServices\Ollama
 * @covers \MageOS\AiBase\AiServices\Anthropic
 * @covers \MageOS\AiBase\AiServices\OpenCode
 * @covers \MageOS\AiBase\AiServices\OpenCodeCustom
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

    public function test_ollama_fetch_models_throws_on_missing_models_list(): void
    {
        $this->fetcher->method('getJson')->willReturn(['tags' => []]);

        $service = new Ollama($this->fieldFactory, $this->fetcher);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('missing "models" list');

        $service->fetchModels([]);
    }

    /**
     * Zen's listing carries no display name of any kind, only the id every other field of the entry
     * describes. Asking for one would label every model after whichever key happened to be there.
     */
    public function test_opencode_fetch_models_labels_entries_by_their_id(): void
    {
        $this->fetcher->expects(self::once())->method('getJson')
            ->with('https://opencode.ai/zen/v1/models', ['Authorization' => 'Bearer zen-test'])
            ->willReturn(['data' => [
                ['id' => 'kimi-k3', 'object' => 'model', 'owned_by' => 'opencode-zen'],
                ['id' => 'glm-5.3-flash', 'object' => 'model', 'owned_by' => 'opencode-zen'],
            ]]);

        $service = new OpenCode($this->fieldFactory, $this->fetcher);

        self::assertSame(
            ['kimi-k3' => 'kimi-k3', 'glm-5.3-flash' => 'glm-5.3-flash'],
            $service->fetchModels(['api_key' => 'zen-test']),
        );
    }

    /**
     * The hosted gateway serves its listing unauthenticated, so an administrator can populate the
     * model list before pasting a key. Sending an empty bearer token instead would turn that into
     * a 401 for no reason.
     */
    public function test_opencode_fetch_models_sends_no_authorization_header_without_a_key(): void
    {
        $this->fetcher->expects(self::once())->method('getJson')
            ->with('https://opencode.ai/zen/v1/models', [])
            ->willReturn(['data' => []]);

        $service = new OpenCode($this->fieldFactory, $this->fetcher);

        self::assertSame([], $service->fetchModels([]));
    }

    public function test_opencode_fetch_models_uses_the_configured_base_url_without_trailing_slash(): void
    {
        $this->fetcher->expects(self::once())->method('getJson')
            ->with('https://ai.example.com/zen/v1/models', [])
            ->willReturn(['data' => []]);

        $service = new OpenCode($this->fieldFactory, $this->fetcher);

        self::assertSame([], $service->fetchModels(['base_url' => 'https://ai.example.com/zen/']));
    }

    /**
     * The shape a live 1.18.32 server answers `GET /config/providers` with, trimmed: the listing
     * is keyed by the server's provider id, and the bridge routes by `providerID/modelID`.
     */
    public function test_opencode_custom_lists_every_configured_provider_model_as_provider_slash_model(): void
    {
        $this->fetcher->expects(self::once())->method('getJson')
            ->with('http://127.0.0.1:4096/config/providers', [])
            ->willReturn(['providers' => [
                ['id' => 'anthropic', 'name' => 'Anthropic', 'models' => [
                    'claude-sonnet-4-6' => ['id' => 'claude-sonnet-4-6', 'name' => 'Claude Sonnet 4.6'],
                ]],
                ['id' => 'yireo-test-1', 'name' => 'yireo-test-1', 'models' => [
                    'qwen3.5:9b' => ['id' => 'qwen3.5:9b'],
                ]],
            ], 'default' => ['anthropic' => 'claude-sonnet-4-6']]);

        $service = new OpenCodeCustom($this->fieldFactory, $this->fetcher);

        self::assertSame(
            [
                'anthropic/claude-sonnet-4-6' => 'Anthropic: Claude Sonnet 4.6',
                'yireo-test-1/qwen3.5:9b'     => 'yireo-test-1: qwen3.5:9b',
            ],
            $service->fetchModels([]),
        );
    }

    /**
     * The server protects its whole API with HTTP basic auth, the listing included, and the stored
     * "API key" is that password.
     */
    public function test_opencode_custom_fetch_models_authenticates_with_the_stored_server_login(): void
    {
        $this->fetcher->expects(self::once())->method('getJson')
            ->with(
                'http://host.docker.internal:4096/config/providers',
                ['Authorization' => 'Basic ' . base64_encode('shop:s3cret')],
            )
            ->willReturn(['providers' => []]);

        $service = new OpenCodeCustom($this->fieldFactory, $this->fetcher);

        $service->fetchModels([
            'base_url' => 'http://host.docker.internal:4096/',
            'username' => 'shop',
            'api_key'  => 's3cret',
        ]);
    }

    public function test_opencode_custom_fetch_models_falls_back_to_the_servers_default_username(): void
    {
        $this->fetcher->expects(self::once())->method('getJson')
            ->with(self::anything(), ['Authorization' => 'Basic ' . base64_encode('opencode:s3cret')])
            ->willReturn(['providers' => []]);

        (new OpenCodeCustom($this->fieldFactory, $this->fetcher))->fetchModels(['api_key' => 's3cret', 'username' => ' ']);
    }

    public function test_opencode_custom_fetch_models_throws_on_missing_providers_list(): void
    {
        $this->fetcher->method('getJson')->willReturn(['name' => 'NotFoundError']);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('missing "providers" list');

        (new OpenCodeCustom($this->fieldFactory, $this->fetcher))->fetchModels([]);
    }
}
