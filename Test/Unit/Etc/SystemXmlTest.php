<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Etc;

use PHPUnit\Framework\TestCase;

/**
 * Asserts against the parsed `system.xml` file directly rather than through Magento's config
 * structure. Reading `system.xml` into that structure only happens for the adminhtml area (see
 * the module's own CLAUDE.md), which needs a running Magento install with the integration test
 * framework; these three requirements only need the raw XML shape, which is cheaper to check here.
 */
final class SystemXmlTest extends TestCase
{
    private \SimpleXMLElement $system;

    protected function setUp(): void
    {
        $path = dirname(__DIR__, 3) . '/src/etc/adminhtml/system.xml';
        $this->system = new \SimpleXMLElement((string) file_get_contents($path));
    }

    public function test_exposes_the_usage_group_in_the_adminhtml_system_configuration(): void
    {
        $groups = $this->system->xpath('//section[@id="mageos_ai"]/group[@id="usage"]');

        self::assertNotNull($groups);
        self::assertCount(1, $groups);
    }

    public function test_declares_the_cron_expression_field_with_a_default_schedule(): void
    {
        $fields = $this->system->xpath('//section[@id="mageos_ai"]/group[@id="usage"]/field[@id="cron_expr"]');
        self::assertNotNull($fields);
        self::assertCount(1, $fields);

        $configXmlPath = dirname(__DIR__, 3) . '/src/etc/config.xml';
        $config = new \SimpleXMLElement((string) file_get_contents($configXmlPath));
        $default = $config->xpath('//default/mageos_ai/usage/cron_expr');

        self::assertNotNull($default);
        self::assertCount(1, $default);
        self::assertSame('0 3 * * *', (string) $default[0]);
    }

    public function test_offers_the_usage_fields_at_default_scope_only(): void
    {
        $group = $this->system->xpath('//section[@id="mageos_ai"]/group[@id="usage"]')[0];

        self::assertSame('1', (string) $group['showInDefault']);
        self::assertSame('', (string) $group['showInWebsite']);
        self::assertSame('', (string) $group['showInStore']);

        foreach ($group->field as $field) {
            self::assertSame('1', (string) $field['showInDefault'], (string) $field['id'] . ' showInDefault');
            self::assertSame('', (string) $field['showInWebsite'], (string) $field['id'] . ' showInWebsite');
            self::assertSame('', (string) $field['showInStore'], (string) $field['id'] . ' showInStore');
        }
    }
}
