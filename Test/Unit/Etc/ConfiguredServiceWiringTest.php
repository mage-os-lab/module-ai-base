<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Etc;

use PHPUnit\Framework\TestCase;

/**
 * The option source labels a configured row with its provider's display name, which it can only
 * do for providers it was given. A provider registered in the admin form but missing here is not
 * a crash: it degrades to a raw machine code in a list an administrator has to choose from.
 */
final class ConfiguredServiceWiringTest extends TestCase
{
    private const DI_XML = __DIR__ . '/../../../src/etc/di.xml';

    public function test_the_option_source_knows_every_provider_the_admin_form_offers(): void
    {
        self::assertSame(
            $this->registeredServiceCodes('MageOS\\AiBase\\Block\\Adminhtml\\Configuration\\Services'),
            $this->registeredServiceCodes('MageOS\\AiBase\\Model\\Config\\Source\\ConfiguredService'),
            'Providers missing from the option source show up as raw codes when an administrator '
            . 'picks a configured service.'
        );
    }

    /**
     * @param string $type
     * @return array<int, string>
     */
    private function registeredServiceCodes(string $type): array
    {
        $di = new \SimpleXMLElement((string) file_get_contents(self::DI_XML));
        $items = $di->xpath(
            sprintf('//type[@name="%s"]/arguments/argument[@name="services"]/item', $type)
        ) ?: [];

        return array_map(static fn ($item): string => (string) $item['name'], $items);
    }
}
