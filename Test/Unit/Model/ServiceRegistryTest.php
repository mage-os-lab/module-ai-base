<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Model\ServiceRegistry;
use PHPUnit\Framework\TestCase;

final class ServiceRegistryTest extends TestCase
{
    public function test_keeps_the_order_providers_were_registered_in(): void
    {
        $registry = new ServiceRegistry([
            $this->service('anthropic'),
            $this->service('openai'),
        ]);

        self::assertSame(['anthropic', 'openai'], array_keys($registry->getAll()));
    }

    /**
     * A di.xml item name that drifted from the provider's own code would otherwise make lookups
     * miss a provider that is plainly registered.
     */
    public function test_keys_a_provider_by_the_code_it_declares_not_by_its_array_key(): void
    {
        $registry = new ServiceRegistry(['mislabelled' => $this->service('openai')]);

        self::assertTrue($registry->has('openai'));
        self::assertFalse($registry->has('mislabelled'));
    }

    /**
     * A stored configuration row outlives the module that registered its provider, and every
     * caller has something sensible to do without a definition.
     */
    public function test_reports_an_unregistered_provider_as_absent_rather_than_failing(): void
    {
        $registry = new ServiceRegistry([$this->service('openai')]);

        self::assertNull($registry->get('long_gone'));
        self::assertFalse($registry->has('long_gone'));
    }

    public function test_rejects_a_registration_that_is_not_a_backend_definition(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ServiceRegistry(['not-a-service']);
    }

    private function service(string $code): AiServiceConfigurationInterface
    {
        $service = $this->createMock(AiServiceConfigurationInterface::class);
        $service->method('getCode')->willReturn($code);

        return $service;
    }
}
