<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Integration\Model\Config;

use Magento\Config\Model\Config as ConfigModel;
use Magento\Config\Model\Config\Reader\Source\Deployed\SettingChecker;
use Magento\Framework\App\Config as AppConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\TestCase;

/**
 * What the admin form is up against when the services value lives in deployment configuration.
 *
 * `bin/magento app:config:dump` writes every path registered `sensitive` into `app/etc/env.php`,
 * this one included, and `config:set --lock-env` puts it there deliberately. Deployment
 * configuration then wins over the database, and `Magento\Config\Model\Config` skips the field on
 * save without raising anything: the section still answers "You saved the configuration" and stores
 * nothing. An administrator sees adding a provider, renaming one and deleting one all appear to
 * work and none of them survive the reload.
 *
 * The form's read-only notice exists for exactly this, so the state it reports is pinned here
 * rather than assumed. `MageOS\AiBase\Test\Unit\Block\Adminhtml\Configuration\ServicesReadOnlyTest`
 * covers the half that reads it back off the element.
 *
 * Only the setting checker's view of deployment configuration is replaced, not the shared
 * `DeploymentConfig` itself: everything else in the save path reads that file for real, and an
 * install-wide stub would change far more than the one path this is about.
 */
#[AppArea('adminhtml')]
final class DeploymentLockedConfigurationTest extends TestCase
{
    private const CONFIG_PATH = 'mageos_ai/services/configuration';

    private ObjectManagerInterface $objectManager;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
    }

    protected function tearDown(): void
    {
        $objectManager = $this->objectManager;
        if ($objectManager instanceof ObjectManager) {
            $objectManager->removeSharedInstance(SettingChecker::class);
        }

        $this->objectManager->get(WriterInterface::class)->delete(self::CONFIG_PATH);
        $this->objectManager->get(AppConfig::class)->clean();
    }

    public function test_a_locked_path_is_reported_read_only(): void
    {
        $this->lockPathInDeploymentConfig();

        self::assertTrue(
            $this->objectManager->get(SettingChecker::class)->isReadOnly(self::CONFIG_PATH, 'default'),
            'The admin form has no way to know it cannot save, so it would present itself as editable.'
        );
    }

    /**
     * The symptom, in the save path the admin form posts into: no exception, no message, no write.
     */
    public function test_a_save_is_discarded_without_complaint_while_the_path_is_locked(): void
    {
        $this->saveServices(['_before' => ['openai' => ['api_key' => 'sk-before', 'model' => 'gpt-4o']]]);
        $stored = $this->storedValue();
        self::assertNotSame('', $stored, 'Nothing was stored, so the next assertion would prove nothing.');

        $this->lockPathInDeploymentConfig();
        $this->saveServices(['_after' => ['anthropic' => ['api_key' => 'sk-after', 'model' => 'claude-sonnet-4-6']]]);

        self::assertSame($stored, $this->storedValue(), 'A locked field was written after all.');
    }

    /**
     * An install that never dumped its configuration must keep saving, or the notice would be the
     * only thing the form ever showed.
     */
    public function test_a_path_absent_from_deployment_config_stays_editable(): void
    {
        self::assertFalse(
            $this->objectManager->get(SettingChecker::class)->isReadOnly(self::CONFIG_PATH, 'default')
        );

        $this->saveServices(['_row' => ['openai' => ['api_key' => 'sk-editable', 'model' => 'gpt-4o']]]);

        self::assertNotSame('', $this->storedValue());
    }

    /**
     * Put the services path into deployment configuration, the way `app:config:dump` does.
     *
     * @return void
     */
    private function lockPathInDeploymentConfig(): void
    {
        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturnCallback(
            static fn ($key = null, $defaultValue = null) => $key === 'system/default/' . self::CONFIG_PATH
                ? '{"_deployed":{"openai":{"api_key":"sk-deployed","model":"gpt-4o"}}}'
                : $defaultValue
        );

        $objectManager = $this->objectManager;
        self::assertInstanceOf(ObjectManager::class, $objectManager, 'The test framework object manager is required.');
        $objectManager->addSharedInstance(
            $objectManager->create(SettingChecker::class, ['config' => $deploymentConfig]),
            SettingChecker::class
        );
    }

    /**
     * Save a services configuration the way the admin form posts it.
     *
     * @param array<string, array<string, array<string, string>>> $rows
     * @return void
     */
    private function saveServices(array $rows): void
    {
        $config = $this->objectManager->create(ConfigModel::class);
        $config->setSection('mageos_ai');
        $config->setGroups(['services' => ['fields' => ['configuration' => ['value' => $rows]]]]);
        $config->save();

        $this->objectManager->get(AppConfig::class)->clean();
    }

    /**
     * @return string
     */
    private function storedValue(): string
    {
        return (string) $this->objectManager->get(ScopeConfigInterface::class)->getValue(self::CONFIG_PATH);
    }
}
