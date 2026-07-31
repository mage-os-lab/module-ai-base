<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Client;

use MageOS\AiBase\Model\Client\BridgeRegistry;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MageOS\AiBase\Model\Client\BridgeRegistry
 */
final class BridgeRegistryTest extends TestCase
{
    /**
     * "Supported" and "available" are deliberately different questions. A provider with no
     * bridge released upstream can never be made to work with the bundled client, whereas one
     * whose package is simply absent is one composer require away, and the admin form says
     * something different for each.
     */
    public function test_a_service_with_no_bridge_entry_is_neither_supported_nor_available(): void
    {
        $registry = new BridgeRegistry([]);

        self::assertFalse($registry->isSupported('acmeai'));
        self::assertFalse($registry->isAvailable('acmeai'));
        self::assertNull($registry->getPackage('acmeai'));
        self::assertNull($registry->getFactoryClass('acmeai'));
    }

    public function test_a_registered_but_uninstalled_bridge_is_supported_and_not_available(): void
    {
        $registry = new BridgeRegistry(['openai' => [
            'factory' => 'MageOS\\AiBase\\Test\\Unit\\Model\\Client\\NotInstalledFactory',
            'package' => 'symfony/ai-open-ai-platform',
        ]]);

        self::assertTrue($registry->isSupported('openai'));
        self::assertFalse($registry->isAvailable('openai'));
        self::assertSame('symfony/ai-open-ai-platform', $registry->getPackage('openai'));
    }

    public function test_an_installed_bridge_is_available(): void
    {
        $registry = new BridgeRegistry(['openai' => [
            'factory' => FakePlatformFactory::class,
            'package' => 'symfony/ai-open-ai-platform',
        ]]);

        self::assertTrue($registry->isAvailable('openai'));
        self::assertSame(FakePlatformFactory::class, $registry->getFactoryClass('openai'));
    }

    /**
     * A class that exists but has no createPlatform() cannot build a platform, so it must not
     * be reported as available; otherwise the admin is told to expect a working provider and
     * the failure surfaces later as an undefined-method error.
     */
    public function test_a_class_without_create_platform_is_not_available(): void
    {
        $registry = new BridgeRegistry(['openai' => [
            'factory' => self::class,
            'package' => 'symfony/ai-open-ai-platform',
        ]]);

        self::assertTrue($registry->isSupported('openai'));
        self::assertFalse($registry->isAvailable('openai'));
    }

    public function test_a_malformed_bridge_entry_does_not_report_a_package_or_factory(): void
    {
        $registry = new BridgeRegistry(['openai' => ['factory' => '', 'package' => '']]);

        self::assertNull($registry->getFactoryClass('openai'));
        self::assertNull($registry->getPackage('openai'));
        self::assertFalse($registry->isAvailable('openai'));
    }
}
