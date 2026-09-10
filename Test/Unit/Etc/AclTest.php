<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Etc;

use PHPUnit\Framework\TestCase;

/**
 * The declarative permission tree in acl.xml: which resources exist, and how they nest.
 *
 * Parsed as plain XML rather than resolved through Magento's Acl builder, since the tree itself
 * carries no behaviour to exercise, only structure to get right.
 */
final class AclTest extends TestCase
{
    public function test_it_declares_a_usage_acl_resource_separate_from_the_configuration_resource(): void
    {
        $usage = $this->findResourceById('MageOS_AiBase::usage');
        $configuration = $this->findResourceById('MageOS_AiBase::configuration');

        self::assertNotNull($usage);
        self::assertNotNull($configuration);
        self::assertNotSame($usage, $configuration);
        self::assertSame('Mage-OS AI Usage', (string) $usage['title']);
    }

    public function test_it_groups_the_ai_reports_under_their_own_resource(): void
    {
        $group = $this->findResourceById('MageOS_AiBase::reports');

        self::assertNotNull($group);
        self::assertSame('Magento_Reports::report', (string) $group->xpath('..')[0]['id']);
    }

    public function test_it_declares_the_reports_branch_inside_the_admin_root(): void
    {
        $reports = $this->findResourceById('Magento_Reports::report');

        self::assertNotNull($reports);

        // Magento_Reports declares this resource under Magento_Backend::admin. Naming it anywhere
        // else does not move it: acl.xml files merge into one document under a unique-id
        // constraint, so a second position is a duplicate and the merged ACL fails to build.
        self::assertSame('Magento_Backend::admin', (string) $reports->xpath('..')[0]['id']);
    }

    public function test_it_nests_the_usage_acl_resource_under_the_reports_branch(): void
    {
        $usage = $this->findResourceById('MageOS_AiBase::usage');

        self::assertNotNull($usage);
        self::assertSame('MageOS_AiBase::reports', (string) $usage->xpath('..')[0]['id']);
    }

    public function test_it_validates_against_the_acl_schema(): void
    {
        $document = new \DOMDocument();
        $document->load(__DIR__ . '/../../../src/etc/acl.xml');

        self::assertTrue($document->schemaValidate(
            (new \Magento\Framework\Config\Dom\UrnResolver())->getRealPath('urn:magento:framework:Acl/etc/acl.xsd')
        ));
    }

    private function findResourceById(string $id): ?\SimpleXMLElement
    {
        $acl = simplexml_load_file(__DIR__ . '/../../../src/etc/acl.xml');
        $matches = $acl->xpath("//resource[@id='" . $id . "']");

        return $matches[0] ?? null;
    }
}
