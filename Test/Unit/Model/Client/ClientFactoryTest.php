<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Client;

use Magento\Framework\Exception\LocalizedException;
use MageOS\AiBase\Api\AiServiceSelectorInterface;
use MageOS\AiBase\Model\AiService;
use MageOS\AiBase\Model\Client\BridgeRegistry;
use MageOS\AiBase\Model\Client\ClientFactory;
use MageOS\AiBase\Model\Client\SymfonyAiClient;
use MageOS\AiBase\Model\Client\SymfonyAiClientFactory;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ClientFactoryTest extends TestCase
{
    private AiServiceSelectorInterface&MockObject $serviceSelector;
    private SymfonyAiClientFactory&MockObject $clientFactory;

    protected function setUp(): void
    {
        $this->serviceSelector = $this->createMock(AiServiceSelectorInterface::class);
        $this->clientFactory = $this->createMock(SymfonyAiClientFactory::class);

        RecordingAnthropicFactory::$apiKey = null;
        RecordingAnthropicFactory::$modelCatalog = null;
        RecordingLocalRuntimeFactory::$baseUrl = null;
    }

    /**
     * Admin row order has nothing to do with which bridge packages an install has, so the
     * no-argument entry point must not be hostage to whichever provider happens to be first.
     */
    public function test_create_without_a_code_skips_a_provider_whose_bridge_is_not_installed(): void
    {
        $this->serviceSelector->method('getAll')->willReturn([
            new AiService('row_ollama', 'ollama', ['model' => 'llama3']),
            new AiService('row_openai', 'openai', ['api_key' => 'k', 'model' => 'gpt-4o']),
        ]);
        $this->clientFactory->method('create')->willReturnCallback(
            function (array $data): SymfonyAiClient {
                self::assertSame('openai', $data['serviceCode'], 'The usable provider must win.');
                self::assertSame('gpt-4o', $data['model']);

                return $this->createMock(SymfonyAiClient::class);
            }
        );

        $subject = new ClientFactory($this->serviceSelector, $this->clientFactory, new BridgeRegistry([
            'ollama' => ['factory' => 'Absent\\Ollama\\Factory', 'package' => 'symfony/ai-ollama-platform'],
            'openai' => ['factory' => FakePlatformFactory::class, 'package' => 'symfony/ai-open-ai-platform'],
        ]));

        $subject->create();
    }

    public function test_create_without_a_code_keeps_the_first_provider_when_it_is_usable(): void
    {
        $this->serviceSelector->method('getAll')->willReturn([
            new AiService('row_openai', 'openai', ['api_key' => 'k', 'model' => 'gpt-4o']),
            new AiService('row_anthropic', 'anthropic', ['api_key' => 'k2', 'model' => 'claude']),
        ]);
        $this->clientFactory->method('create')->willReturnCallback(
            function (array $data): SymfonyAiClient {
                self::assertSame('openai', $data['serviceCode'], 'Order is still respected among usable providers.');

                return $this->createMock(SymfonyAiClient::class);
            }
        );

        $subject = new ClientFactory($this->serviceSelector, $this->clientFactory, new BridgeRegistry([
            'openai' => ['factory' => FakePlatformFactory::class, 'package' => 'symfony/ai-open-ai-platform'],
            'anthropic' => ['factory' => FakePlatformFactory::class, 'package' => 'symfony/ai-anthropic-platform'],
        ]));

        $subject->create();
    }

    public function test_create_without_a_code_names_what_to_install_when_nothing_is_usable(): void
    {
        $this->serviceSelector->method('getAll')->willReturn([
            new AiService('row_ollama', 'ollama', ['model' => 'llama3']),
        ]);

        $subject = new ClientFactory($this->serviceSelector, $this->clientFactory, new BridgeRegistry([
            'ollama' => ['factory' => 'Absent\\Ollama\\Factory', 'package' => 'symfony/ai-ollama-platform'],
        ]));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('ollama (needs symfony/ai-ollama-platform)');

        $subject->create();
    }

    /**
     * Naming a provider explicitly must fail rather than quietly resolve to a different one:
     * silently billing another provider's account would be worse than an error.
     */
    public function test_create_with_a_code_does_not_fall_back_to_a_usable_provider(): void
    {
        $this->serviceSelector->method('getByCode')->with('ollama')->willReturn([
            new AiService('row_ollama', 'ollama', ['model' => 'llama3']),
        ]);
        $this->clientFactory->expects(self::never())->method('create');

        $subject = new ClientFactory($this->serviceSelector, $this->clientFactory, new BridgeRegistry([
            'ollama' => ['factory' => 'Absent\\Ollama\\Factory', 'package' => 'symfony/ai-ollama-platform'],
            'openai' => ['factory' => FakePlatformFactory::class, 'package' => 'symfony/ai-open-ai-platform'],
        ]));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('composer require symfony/ai-ollama-platform');

        $subject->create('ollama');
    }

    public function test_create_throws_when_no_service_is_configured(): void
    {
        $this->serviceSelector->method('getAll')->willReturn([]);
        $subject = new ClientFactory($this->serviceSelector, $this->clientFactory, new BridgeRegistry([]));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('No AI service configured');

        $subject->create();
    }

    public function test_create_throws_when_no_bridge_is_registered_for_the_service(): void
    {
        $this->serviceSelector->method('getByCode')->with('openai')
            ->willReturn([new AiService('row_openai', 'openai', ['api_key' => 'k'])]);
        $subject = new ClientFactory($this->serviceSelector, $this->clientFactory, new BridgeRegistry([]));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('No Symfony AI bridge has been released');

        $subject->create('openai');
    }

    public function test_create_throws_when_bridge_class_is_not_installed(): void
    {
        // Note: Magento's unit-test autoloader fabricates any missing "*Factory"
        // class with a non-static create() and no createPlatform(), which is
        // exactly the shape the method_exists guard must reject.
        $this->serviceSelector->method('getByCode')->with('openai')
            ->willReturn([new AiService('row_openai', 'openai', ['api_key' => 'k'])]);
        $subject = new ClientFactory(
            $this->serviceSelector,
            $this->clientFactory,
            new BridgeRegistry(['openai' => [
                'factory' => 'MageOS\AiBase\Test\Unit\Model\Client\AbsentBridgeFactory',
                'package' => 'symfony/ai-open-ai-platform',
            ]]),
        );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('is not installed');

        $subject->create('openai');
    }

    /**
     * Every platform takes the model as a required name. Passing an empty one on reaches the
     * provider as a request for nothing and comes back as the provider's own words about a model
     * it cannot find, naming neither this module nor the row an administrator has to go fix.
     */
    public function test_create_refuses_a_row_with_no_model_selected(): void
    {
        $this->serviceSelector->method('getByCode')->with('openai')
            ->willReturn([new AiService('row_openai', 'openai', ['api_key' => 'k'])]);
        $this->clientFactory->expects(self::never())->method('create');

        $subject = new ClientFactory(
            $this->serviceSelector,
            $this->clientFactory,
            new BridgeRegistry(['openai' => [
                'factory' => FakePlatformFactory::class,
                'package' => 'symfony/ai-open-ai-platform',
            ]]),
        );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('no model selected');

        $subject->create('openai');
    }

    /**
     * A bridge is registered in di.xml, so what its factory returns is only as good as that entry.
     */
    public function test_create_refuses_a_bridge_factory_that_returns_no_platform(): void
    {
        $this->serviceSelector->method('getByCode')->with('openai')
            ->willReturn([new AiService('row_openai', 'openai', ['api_key' => 'k', 'model' => 'gpt-4o'])]);
        $this->clientFactory->expects(self::never())->method('create');

        $subject = new ClientFactory(
            $this->serviceSelector,
            $this->clientFactory,
            new BridgeRegistry(['openai' => [
                'factory' => FakePlatformlessFactory::class,
                'package' => 'symfony/ai-open-ai-platform',
            ]]),
        );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('did not return a platform object');

        $subject->create('openai');
    }

    public function test_create_builds_client_from_registered_bridge(): void
    {
        $this->serviceSelector->method('getByCode')->with('openai')
            ->willReturn([new AiService('row_openai', 'openai', ['api_key' => 'k', 'model' => 'gpt-4o'])]);

        $client = $this->createMock(SymfonyAiClient::class);
        $this->clientFactory->expects(self::once())->method('create')
            ->with(self::callback(
                fn (array $data) => $data['model'] === 'gpt-4o'
                    && $data['serviceCode'] === 'openai'
                    && $data['serviceId'] === 'row_openai'
                    && $data['platform'] instanceof \stdClass
            ))
            ->willReturn($client);

        $subject = new ClientFactory(
            $this->serviceSelector,
            $this->clientFactory,
            new BridgeRegistry(['openai' => [
                'factory' => FakePlatformFactory::class,
                'package' => 'symfony/ai-open-ai-platform',
            ]]),
        );

        self::assertSame($client, $subject->create('openai'));
    }

    /**
     * The point of addressing a row by id: two rows of the same provider differ in credentials
     * and model, and create('openai') can only ever reach the first of them.
     */
    public function test_create_by_id_builds_a_client_for_that_exact_row(): void
    {
        $this->serviceSelector->method('getById')->with('_row_b')
            ->willReturn(new AiService('_row_b', 'openai', ['api_key' => 'k2', 'model' => 'o1-mini']));
        $this->clientFactory->expects(self::once())->method('create')
            ->with(self::callback(
                fn (array $data) => $data['model'] === 'o1-mini'
                    && $data['serviceCode'] === 'openai'
                    && $data['serviceId'] === '_row_b'
            ))
            ->willReturn($this->createMock(SymfonyAiClient::class));

        $subject = new ClientFactory($this->serviceSelector, $this->clientFactory, new BridgeRegistry([
            'openai' => ['factory' => FakePlatformFactory::class, 'package' => 'symfony/ai-open-ai-platform'],
        ]));

        $subject->createById('_row_b');
    }

    /**
     * An administrator deleting a row leaves stale ids in every consumer that stored one. Falling
     * back to another row would quietly bill an account nobody chose, so this fails and says why.
     */
    public function test_create_by_id_throws_when_the_row_no_longer_exists(): void
    {
        $this->serviceSelector->method('getById')->with('_deleted_row')->willReturn(null);
        $this->clientFactory->expects(self::never())->method('create');

        $subject = new ClientFactory($this->serviceSelector, $this->clientFactory, new BridgeRegistry([]));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('_deleted_row');

        $subject->createById('_deleted_row');
    }

    /**
     * The catalogue is frozen at the installed bridge version, so a model the provider shipped
     * later is unroutable no matter how valid the credentials. The administrator's choice wins.
     */
    public function test_create_registers_a_model_the_bridge_catalogue_does_not_know(): void
    {
        $this->serviceSelector->method('getByCode')->with('anthropic')->willReturn([
            new AiService('row_anthropic', 'anthropic', ['api_key' => 'k', 'model' => 'claude-from-the-future']),
        ]);
        $this->clientFactory->method('create')->willReturn($this->createMock(SymfonyAiClient::class));

        $subject = new ClientFactory($this->serviceSelector, $this->clientFactory, new BridgeRegistry([
            'anthropic' => [
                'factory' => RecordingAnthropicFactory::class,
                'package' => 'symfony/ai-anthropic-platform',
                'catalog' => FakeModelCatalog::class,
            ],
        ]));

        $subject->create('anthropic');

        self::assertInstanceOf(FakeModelCatalog::class, RecordingAnthropicFactory::$modelCatalog);
        self::assertArrayHasKey(
            'claude-from-the-future',
            RecordingAnthropicFactory::$modelCatalog->getModels(),
            'The configured model has to reach the catalogue, or the router rejects it.'
        );
        self::assertSame(
            ['a', 'b', 'c'],
            RecordingAnthropicFactory::$modelCatalog->getModels()['claude-from-the-future']['capabilities'],
            'An unknown model inherits the capabilities of the most capable one in the catalogue.'
        );
    }

    /**
     * Passing a catalogue that only repeats what the bridge already ships is pure risk: it pins the
     * entry to whatever template this code picked instead of the bridge's own definition.
     */
    public function test_create_leaves_a_known_model_on_the_bridge_catalogue(): void
    {
        $this->serviceSelector->method('getByCode')->with('anthropic')->willReturn([
            new AiService('row_anthropic', 'anthropic', ['api_key' => 'k', 'model' => 'known-model']),
        ]);
        $this->clientFactory->method('create')->willReturn($this->createMock(SymfonyAiClient::class));

        $subject = new ClientFactory($this->serviceSelector, $this->clientFactory, new BridgeRegistry([
            'anthropic' => [
                'factory' => RecordingAnthropicFactory::class,
                'package' => 'symfony/ai-anthropic-platform',
                'catalog' => FakeModelCatalog::class,
            ],
        ]));

        $subject->create('anthropic');

        self::assertNull(RecordingAnthropicFactory::$modelCatalog);
    }

    /**
     * Bridges gain parameters between releases. An install on an older one must go without the
     * argument rather than die on an unknown named parameter.
     */
    public function test_create_withholds_arguments_a_bridge_factory_does_not_declare(): void
    {
        $this->serviceSelector->method('getByCode')->with('anthropic')->willReturn([
            new AiService('row_anthropic', 'anthropic', [
                'api_key' => 'k',
                'model'   => 'claude-from-the-future',
            ]),
        ]);
        $this->clientFactory->method('create')->willReturn($this->createMock(SymfonyAiClient::class));

        $subject = new ClientFactory($this->serviceSelector, $this->clientFactory, new BridgeRegistry([
            'anthropic' => [
                'factory' => FakePlatformFactory::class,
                'package' => 'symfony/ai-anthropic-platform',
                'catalog' => FakeModelCatalog::class,
            ],
        ]));

        self::assertInstanceOf(SymfonyAiClient::class, $subject->create('anthropic'));
    }

    /**
     * A catalogue that ignores the argument is no better than none. PHP drops an extra argument to
     * a no-parameter constructor silently, so without this the model would never land and the
     * router would reject it exactly as if this code were not here.
     */
    public function test_create_passes_no_catalog_when_the_bridge_catalog_cannot_be_extended(): void
    {
        $this->serviceSelector->method('getByCode')->with('anthropic')->willReturn([
            new AiService('row_anthropic', 'anthropic', ['api_key' => 'k', 'model' => 'claude-from-the-future']),
        ]);
        $this->clientFactory->method('create')->willReturn($this->createMock(SymfonyAiClient::class));

        $subject = new ClientFactory($this->serviceSelector, $this->clientFactory, new BridgeRegistry([
            'anthropic' => [
                'factory' => RecordingAnthropicFactory::class,
                'package' => 'symfony/ai-anthropic-platform',
                'catalog' => UnextendableModelCatalog::class,
            ],
        ]));

        $subject->create('anthropic');

        self::assertNull(RecordingAnthropicFactory::$modelCatalog);
    }

    /**
     * Both fields ship with a pre-filled default, so an administrator who wants the default back
     * clears the input. That stores an empty string, which ?? does not catch and which reaches the
     * bridge as the host to call.
     */
    #[TestWith(['ollama', 'http://localhost:11434'])]
    #[TestWith(['lmstudio', 'http://localhost:1234'])]
    public function test_create_falls_back_to_the_local_runtime_default_when_base_url_is_cleared(
        string $code,
        string $expected
    ): void {
        $this->serviceSelector->method('getByCode')->with($code)->willReturn([
            new AiService('row_local', $code, ['model' => 'llama3', 'base_url' => '  ']),
        ]);
        $this->clientFactory->method('create')->willReturn($this->createMock(SymfonyAiClient::class));

        $subject = new ClientFactory($this->serviceSelector, $this->clientFactory, new BridgeRegistry([
            $code => ['factory' => RecordingLocalRuntimeFactory::class, 'package' => 'symfony/ai-' . $code],
        ]));

        $subject->create($code);

        self::assertSame($expected, RecordingLocalRuntimeFactory::$baseUrl);
    }

    public function test_create_by_id_reports_a_missing_bridge_for_the_selected_row(): void
    {
        $this->serviceSelector->method('getById')->with('_row_a')
            ->willReturn(new AiService('_row_a', 'ollama', ['model' => 'llama3']));

        $subject = new ClientFactory($this->serviceSelector, $this->clientFactory, new BridgeRegistry([
            'ollama' => ['factory' => 'Absent\\Ollama\\Factory', 'package' => 'symfony/ai-ollama-platform'],
        ]));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('composer require symfony/ai-ollama-platform');

        $subject->createById('_row_a');
    }
}

/**
 * Stand-in for a Symfony AI bridge Factory.
 */
final class FakePlatformFactory
{
    public static function createPlatform(string $apiKey): object
    {
        return new \stdClass();
    }
}

/**
 * Stand-in for the Anthropic bridge Factory, recording what it was handed.
 *
 * The parameters it ignores are the ones the real signature carries between the key and the base
 * URL: reproducing them is what makes the named argument in ClientFactory meaningful here, since a
 * two-parameter fake would accept a positional call the real bridge would misread as an HTTP client.
 */
final class RecordingAnthropicFactory
{
    public static ?string $apiKey = null;
    public static ?object $modelCatalog = null;

    public static function createPlatform(
        string $apiKey,
        ?object $httpClient = null,
        ?object $modelCatalog = null,
        ?object $contract = null,
        ?object $eventDispatcher = null,
        string $cacheRetention = 'short',
        string $name = 'anthropic',
        ?object $modelRouter = null,
        string $baseUrl = 'https://api.anthropic.com',
    ): object {
        self::$apiKey = $apiKey;
        self::$modelCatalog = $modelCatalog;

        return new \stdClass();
    }
}

/**
 * Stand-in for a bridge whose endpoint is its first positional argument, as the local runtimes are.
 */
final class RecordingLocalRuntimeFactory
{
    public static ?string $baseUrl = null;

    public static function createPlatform(?string $hostUrl = null, ?object $httpClient = null): object
    {
        self::$baseUrl = $hostUrl;

        return new \stdClass();
    }
}

/**
 * Stand-in for a bridge ModelCatalog that takes no constructor argument, as FallbackModelCatalog
 * does upstream. PHP drops the extra argument rather than complaining, so this cannot be extended.
 */
final class UnextendableModelCatalog
{
    /**
     * @return array<string,array{class:string,capabilities:array<int,string>}>
     */
    public function getModels(): array
    {
        return ['known-model' => ['class' => 'Fake\\Model', 'capabilities' => ['a']]];
    }
}

/**
 * Stand-in for a bridge ModelCatalog, matching the shape ClientFactory reads and extends.
 */
final class FakeModelCatalog
{
    /**
     * @param array<string,array{class:string,capabilities:array<int,string>}> $additionalModels
     */
    public function __construct(private readonly array $additionalModels = [])
    {
    }

    /**
     * @return array<string,array{class:string,capabilities:array<int,string>}>
     */
    public function getModels(): array
    {
        return array_merge([
            'known-model' => ['class' => 'Fake\\Model', 'capabilities' => ['a']],
            'richer-model' => ['class' => 'Fake\\Model', 'capabilities' => ['a', 'b', 'c']],
        ], $this->additionalModels);
    }
}

/**
 * Stand-in for a bridge registered against a class whose createPlatform() builds nothing.
 */
final class FakePlatformlessFactory
{
    public static function createPlatform(string $apiKey): mixed
    {
        return null;
    }
}
