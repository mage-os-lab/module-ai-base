<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Etc;

use PHPUnit\Framework\TestCase;

/**
 * The admin form, the option source, the sensitive-data processor and the model-list refresh
 * controller all need the same set of registered providers, and a provider present in one and
 * missing from another degrades silently rather than failing: a raw machine code in a dropdown an
 * administrator has to choose from, or credentials falling back to a field-name heuristic.
 *
 * They read one registry now, so the way that drift comes back is someone re-introducing a second
 * `services` argument somewhere. That is what this guards.
 */
final class ConfiguredServiceWiringTest extends TestCase
{
    private const DI_XML = __DIR__ . '/../../../src/etc/di.xml';

    private const REGISTRY = 'MageOS\\AiBase\\Model\\ServiceRegistry';

    public function test_the_registry_is_the_only_place_providers_are_listed(): void
    {
        $di = new \SimpleXMLElement((string) file_get_contents(self::DI_XML));
        $owners = array_map(
            static fn (\SimpleXMLElement $argument): string => (string) $argument->xpath('../..')[0]['name'],
            $di->xpath('//type/arguments/argument[@name="services"]') ?: []
        );

        self::assertSame(
            [self::REGISTRY],
            $owners,
            'A second provider list has to be kept in sync by hand, and nothing fails when it is not.'
        );
    }

    public function test_every_registered_provider_declares_the_code_it_is_keyed_by(): void
    {
        $mismatched = [];
        foreach ($this->registeredServices() as $code => $class) {
            $service = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
            if ($service->getCode() !== $code) {
                $mismatched[$code] = $service->getCode();
            }
        }

        self::assertSame(
            [],
            $mismatched,
            'The registry keys providers by getCode(), so an item name that disagrees with it is '
            . 'misleading to anyone reading di.xml.'
        );
    }

    /**
     * @return array<string, class-string>
     */
    private function registeredServices(): array
    {
        $di = new \SimpleXMLElement((string) file_get_contents(self::DI_XML));
        $items = $di->xpath(
            sprintf('//type[@name="%s"]/arguments/argument[@name="services"]/item', self::REGISTRY)
        ) ?: [];

        $services = [];
        foreach ($items as $item) {
            $services[(string) $item['name']] = trim((string) $item);
        }

        return $services;
    }
}
