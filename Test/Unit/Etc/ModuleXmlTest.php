<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Etc;

use PHPUnit\Framework\TestCase;

/**
 * The module load-order declaration in module.xml.
 *
 * The usage listing (task 015) is this module's first layout and first UI component; without
 * Magento_Ui ahead of this module in the sequence, both could be read before Magento_Ui's own
 * definitions are in place.
 */
final class ModuleXmlTest extends TestCase
{
    public function test_it_sequences_magento_ui_ahead_of_this_module(): void
    {
        $module = simplexml_load_file(__DIR__ . '/../../../src/etc/module.xml');
        $sequenced = array_map(
            static fn (\SimpleXMLElement $module): string => (string) $module['name'],
            $module->xpath('//sequence/module'),
        );

        self::assertContains('Magento_Ui', $sequenced);
    }

    public function test_it_requires_magento_ui_in_composer_json(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../../../composer.json'), true);

        self::assertArrayHasKey('magento/module-ui', $composer['require']);
    }
}
