<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Etc;

use MageOS\AiBase\Model\Config\Backend\EncryptedServices;
use PHPUnit\Framework\TestCase;

/**
 * Every config field this module stores credentials in must be registered with
 * `Magento\Config\Model\Config\TypePool` as `sensitive`.
 *
 * Sensitive paths are excluded from `bin/magento app:config:dump`. Without the
 * registration the value is written into `app/etc/config.php`, which is commonly
 * committed, and the field then becomes read-only in the admin because deployment
 * config takes precedence over the database.
 *
 * The credential-bearing fields are derived from `system.xml` rather than listed
 * here, so a second encrypted field added later fails this test until it is
 * registered too.
 */
final class SensitiveConfigPathsTest extends TestCase
{
    private const SYSTEM_XML = __DIR__ . '/../../../src/etc/adminhtml/system.xml';
    private const DI_XML = __DIR__ . '/../../../src/etc/di.xml';

    public function test_every_credential_bearing_config_path_is_registered_as_sensitive(): void
    {
        $credentialPaths = $this->credentialBearingPaths();
        self::assertNotSame(
            [],
            $credentialPaths,
            'No field using the encrypting backend model was found; this test now guards nothing.'
        );

        self::assertSame(
            [],
            array_values(array_diff($credentialPaths, $this->sensitiveRegisteredPaths())),
            'These paths store credentials but are not registered sensitive in di.xml, '
            . 'so app:config:dump will write them into app/etc/config.php.'
        );
    }

    /**
     * TypePool ignores an entry whose value is not truthy under FILTER_VALIDATE_BOOLEAN,
     * so a path registered with "0" or an empty value is silently not registered at all.
     */
    public function test_registered_paths_use_a_value_type_pool_accepts(): void
    {
        $rejected = [];
        foreach ($this->sensitiveEntries() as $path => $value) {
            if (filter_var($value, FILTER_VALIDATE_BOOLEAN) !== true) {
                $rejected[] = sprintf('%s => "%s"', $path, $value);
            }
        }

        self::assertSame([], $rejected, 'TypePool silently ignores these entries.');
    }

    /**
     * Config paths whose field declares the encrypting backend model.
     *
     * @return array<int, string>
     */
    private function credentialBearingPaths(): array
    {
        $system = new \SimpleXMLElement((string)file_get_contents(self::SYSTEM_XML));
        $paths = [];

        foreach ($system->xpath('//section') ?: [] as $section) {
            foreach ($section->xpath('group') ?: [] as $group) {
                foreach ($group->xpath('field') ?: [] as $field) {
                    if (trim((string)$field->backend_model) !== EncryptedServices::class) {
                        continue;
                    }
                    $paths[] = sprintf(
                        '%s/%s/%s',
                        (string)$section['id'],
                        (string)$group['id'],
                        (string)$field['id']
                    );
                }
            }
        }

        return $paths;
    }

    /**
     * @return array<int, string>
     */
    private function sensitiveRegisteredPaths(): array
    {
        return array_keys($this->sensitiveEntries());
    }

    /**
     * Paths registered under TypePool's `sensitive` argument, mapped path => raw value.
     *
     * @return array<string, string>
     */
    private function sensitiveEntries(): array
    {
        $di = new \SimpleXMLElement((string)file_get_contents(self::DI_XML));
        $items = $di->xpath(
            '//type[@name="Magento\\Config\\Model\\Config\\TypePool"]'
            . '/arguments/argument[@name="sensitive"]/item'
        ) ?: [];

        $entries = [];
        foreach ($items as $item) {
            $entries[(string)$item['name']] = trim((string)$item);
        }

        return $entries;
    }
}
