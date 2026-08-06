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

    public function test_create_builds_client_from_registered_bridge(): void
    {
        $this->serviceSelector->method('getByCode')->with('openai')
            ->willReturn([new AiService('row_openai', 'openai', ['api_key' => 'k', 'model' => 'gpt-4o'])]);

        $client = new SymfonyAiClient(new \stdClass(), 'gpt-4o', 'openai');
        $this->clientFactory->expects(self::once())->method('create')
            ->with(self::callback(
                fn (array $data) => $data['model'] === 'gpt-4o'
                    && $data['serviceCode'] === 'openai'
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
                fn (array $data) => $data['model'] === 'o1-mini' && $data['serviceCode'] === 'openai'
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
