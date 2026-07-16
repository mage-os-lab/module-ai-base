<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Client;

use Magento\Framework\Exception\LocalizedException;
use MageOS\AiBase\Api\AiServiceSelectorInterface;
use MageOS\AiBase\Model\AiService;
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

    public function test_create_throws_when_no_service_is_configured(): void
    {
        $this->serviceSelector->method('getAll')->willReturn([]);
        $subject = new ClientFactory($this->serviceSelector, $this->clientFactory, []);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('No AI service configured');

        $subject->create();
    }

    public function test_create_throws_when_no_bridge_is_registered_for_the_service(): void
    {
        $this->serviceSelector->method('getByCode')->with('openai')
            ->willReturn([new AiService('openai', ['apikey' => 'k'])]);
        $subject = new ClientFactory($this->serviceSelector, $this->clientFactory, []);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('No Symfony AI platform bridge registered');

        $subject->create('openai');
    }

    public function test_create_throws_when_bridge_class_is_not_installed(): void
    {
        // Note: Magento's unit-test autoloader fabricates any missing "*Factory"
        // class with a non-static create() and no createPlatform(), which is
        // exactly the shape the method_exists guard must reject.
        $this->serviceSelector->method('getByCode')->with('openai')
            ->willReturn([new AiService('openai', ['apikey' => 'k'])]);
        $subject = new ClientFactory(
            $this->serviceSelector,
            $this->clientFactory,
            ['openai' => 'MageOS\AiBase\Test\Unit\Model\Client\AbsentBridgeFactory'],
        );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('is not installed');

        $subject->create('openai');
    }

    public function test_create_builds_client_from_registered_bridge(): void
    {
        $this->serviceSelector->method('getByCode')->with('openai')
            ->willReturn([new AiService('openai', ['apikey' => 'k', 'model' => 'gpt-4o'])]);

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
            ['openai' => FakePlatformFactory::class],
        );

        self::assertSame($client, $subject->create('openai'));
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
