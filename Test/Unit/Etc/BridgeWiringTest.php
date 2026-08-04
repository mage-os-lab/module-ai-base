<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Etc;

use PHPUnit\Framework\TestCase;

/**
 * The admin form tells administrators which composer package makes a provider available,
 * so the wiring behind that claim has to be complete and has to match what is published.
 */
final class BridgeWiringTest extends TestCase
{
    private const DI_XML = __DIR__ . '/../../../src/etc/di.xml';
    private const COMPOSER_JSON = __DIR__ . '/../../../composer.json';

    public function test_every_bridge_declares_both_a_factory_and_a_package(): void
    {
        $incomplete = [];
        foreach ($this->bridges() as $code => $bridge) {
            if (($bridge['factory'] ?? '') === '' || ($bridge['package'] ?? '') === '') {
                $incomplete[] = $code;
            }
        }

        self::assertSame(
            [],
            $incomplete,
            'A bridge without a package name leaves the admin form unable to say what to install.'
        );
    }

    public function test_every_bridge_belongs_to_a_registered_service(): void
    {
        $orphans = array_values(array_diff(array_keys($this->bridges()), $this->registeredServiceCodes()));

        self::assertSame(
            [],
            $orphans,
            'These bridges are wired to service codes that no registered provider uses.'
        );
    }

    /**
     * Every package the admin form can tell someone to install must also be listed in `suggest`,
     * so `composer suggest` and the admin form cannot drift apart.
     */
    public function test_every_bridge_package_is_listed_in_composer_suggest(): void
    {
        $suggested = array_keys(
            json_decode((string) file_get_contents(self::COMPOSER_JSON), true)['suggest'] ?? []
        );
        $packages = array_values(array_unique(array_column($this->bridges(), 'package')));

        self::assertSame([], array_values(array_diff($packages, $suggested)));
    }

    public function test_no_longer_suggests_the_platform_package_alone(): void
    {
        $suggested = array_keys(
            json_decode((string) file_get_contents(self::COMPOSER_JSON), true)['suggest'] ?? []
        );

        self::assertNotContains(
            'symfony/ai-platform',
            $suggested,
            'The platform package ships no bridges since 0.12; suggesting it alone sends people to a dead end.'
        );
    }

    /**
     * Every provider this module ships must have a bridge, so no bundled provider can be
     * offered in the admin form that the built-in client is unable to use.
     *
     * This is a policy for bundled providers only. Third parties may still register a provider
     * with no bridge; the form marks those unsupported rather than offering an install command.
     */
    public function test_every_bundled_provider_has_a_bridge(): void
    {
        $withoutBridge = array_values(array_diff($this->registeredServiceCodes(), array_keys($this->bridges())));

        self::assertSame(
            [],
            $withoutBridge,
            'These providers ship in the admin form but cannot be used through the built-in client. '
            . 'Either wire a bridge for them or do not register them.'
        );
    }

    /**
     * @return array<string, array{factory: string, package: string}>
     */
    private function bridges(): array
    {
        $di = new \SimpleXMLElement((string) file_get_contents(self::DI_XML));
        $items = $di->xpath(
            '//type[@name="MageOS\\AiBase\\Model\\Client\\BridgeRegistry"]'
            . '/arguments/argument[@name="bridges"]/item'
        ) ?: [];

        $bridges = [];
        foreach ($items as $item) {
            $entry = [];
            foreach ($item->item as $child) {
                $entry[(string) $child['name']] = trim((string) $child);
            }
            $bridges[(string) $item['name']] = $entry;
        }

        return $bridges;
    }

    /**
     * @return array<int, string>
     */
    private function registeredServiceCodes(): array
    {
        $di = new \SimpleXMLElement((string) file_get_contents(self::DI_XML));
        $items = $di->xpath(
            '//type[@name="MageOS\\AiBase\\Block\\Adminhtml\\Configuration\\Services"]'
            . '/arguments/argument[@name="services"]/item'
        ) ?: [];

        return array_map(static fn ($item): string => (string) $item['name'], $items);
    }
}
