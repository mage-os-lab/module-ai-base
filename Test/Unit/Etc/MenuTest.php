<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Etc;

use PHPUnit\Framework\TestCase;

/**
 * The declarative admin menu tree in adminhtml/menu.xml: where the usage entry lands, what it
 * points at, and which resource guards it.
 *
 * Parsed as plain XML rather than resolved through Magento's menu builder, since the tree itself
 * carries no behaviour to exercise, only structure to get right.
 */
final class MenuTest extends TestCase
{
    public function test_it_registers_a_menu_item_pointing_at_the_usage_index_action(): void
    {
        $item = $this->findUsageMenuItem();

        self::assertNotNull($item);
        self::assertSame('mageos_ai/usage/index', (string) $item['action']);
    }

    public function test_it_guards_the_menu_item_with_the_usage_acl_resource(): void
    {
        $item = $this->findUsageMenuItem();

        self::assertNotNull($item);
        self::assertSame('MageOS_AiBase::usage', (string) $item['resource']);
    }

    public function test_it_adds_an_ai_heading_under_the_reports_menu(): void
    {
        $group = $this->findMenuItemById('MageOS_AiBase::report_ai');

        self::assertNotNull($group);
        // menu.xsd sets a three-character minimum on a title, so the heading cannot be "AI".
        self::assertSame('Mage-OS AI', (string) $group['title']);
        self::assertSame('Magento_Reports::report', (string) $group['parent']);
        self::assertSame('MageOS_AiBase::reports', (string) $group['resource']);
        self::assertNull($group['action'], 'A heading is not itself a page.');
    }

    public function test_it_places_the_menu_item_in_the_reports_menu(): void
    {
        $item = $this->findUsageMenuItem();

        self::assertNotNull($item);
        self::assertSame('MageOS_AiBase::report_ai', (string) $item['parent']);
        self::assertSame('mageos_ai/usage/index', (string) $item['action']);
    }

    public function test_it_validates_against_the_menu_schema(): void
    {
        $document = new \DOMDocument();
        $document->load(__DIR__ . '/../../../src/etc/adminhtml/menu.xml');

        self::assertTrue($document->schemaValidate(
            (new \Magento\Framework\Config\Dom\UrnResolver())->getRealPath('urn:magento:module:Magento_Backend:etc/menu.xsd')
        ));
    }

    /**
     * A menu entry by its declared id, so a test can assert on the heading as well as the item.
     *
     * @param string $id
     * @return \SimpleXMLElement|null
     */
    private function findMenuItemById(string $id): ?\SimpleXMLElement
    {
        $menu = simplexml_load_file(__DIR__ . '/../../../src/etc/adminhtml/menu.xml');
        $matches = $menu->xpath(sprintf("//add[@id='%s']", $id));

        return $matches[0] ?? null;
    }

    private function findUsageMenuItem(): ?\SimpleXMLElement
    {
        $menu = simplexml_load_file(__DIR__ . '/../../../src/etc/adminhtml/menu.xml');
        $matches = $menu->xpath("//add[@action='mageos_ai/usage/index']");

        return $matches[0] ?? null;
    }
}
