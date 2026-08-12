<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Integration\Model\Config;

use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\TypePool;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AiBase\Model\Config\Backend\EncryptedServices;
use PHPUnit\Framework\TestCase;

/**
 * Every config path this module stores credentials in must be registered `sensitive`.
 *
 * Sensitive paths are excluded from `bin/magento app:config:dump`. Without the registration the
 * value is written into `app/etc/config.php`, which is commonly committed, and the field then
 * becomes read-only in the admin because deployment config wins over the database.
 *
 * Both halves are asked of the running application rather than of the XML. `TypePool` runs each
 * registered value through `FILTER_VALIDATE_BOOLEAN` and silently ignores anything falsy, so a
 * path registered as `0` or with an empty node is in the file and absent from the pool. And the
 * credential-bearing fields are discovered from the config structure, so a second encrypted field
 * added later fails this test until it is registered too.
 */
#[AppArea('adminhtml')]
final class SensitiveConfigPathTest extends TestCase
{
    public function test_every_credential_bearing_path_is_excluded_from_config_dump(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $typePool = $objectManager->get(TypePool::class);

        $credentialPaths = $this->credentialBearingPaths($objectManager->get(Structure::class));
        self::assertNotSame(
            [],
            $credentialPaths,
            'No field using the encrypting backend model was found, so this test now guards nothing.'
        );

        $exposed = array_values(array_filter(
            $credentialPaths,
            static fn (string $path): bool => !$typePool->isPresent($path, TypePool::TYPE_SENSITIVE),
        ));

        self::assertSame(
            [],
            $exposed,
            'These paths store credentials but are not sensitive, so app:config:dump writes them '
            . 'into app/etc/config.php and the admin field becomes read-only.'
        );
    }

    /**
     * Config paths whose field is wired to the encrypting backend model.
     *
     * @param Structure $structure
     * @return array<int, string>
     */
    private function credentialBearingPaths(Structure $structure): array
    {
        return array_values($structure->getFieldPathsByAttribute('backend_model', EncryptedServices::class));
    }
}
