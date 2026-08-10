<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Integration\Model\Config;

use Magento\Config\Model\Config as ConfigModel;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Framework\App\Config as AppConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AiBase\Api\AiServiceSelectorInterface;
use MageOS\AiBase\Model\Config\Backend\EncryptedServices;
use MageOS\AiBase\Model\Config\SensitiveDataProcessor;
use PHPUnit\Framework\TestCase;

/**
 * Credentials from the admin form to the database and back, through the real save path.
 *
 * The unit test of this behaviour hands the backend model a fake encryptor whose ciphertext is
 * `enc(<plaintext>)`, which cannot be wrong about the one thing that matters: the value that
 * actually lands in `core_config_data`. It also cannot see the backend model failing to be wired
 * to the field at all, since it constructs the model itself. Saving the way the admin does exposes
 * both.
 *
 * The area is not decoration: `system.xml` is read into the config structure for adminhtml only, so
 * anywhere else the field has no backend model, Magento falls back to the plain config Value, and
 * the row array reaches the database unserialized. Saving as the admin does means being where it is.
 */
#[AppArea('adminhtml')]
final class CredentialStorageTest extends TestCase
{
    private const CONFIG_PATH = 'mageos_ai/services/configuration';
    private const API_KEY = 'sk-integration-secret';

    private ObjectManagerInterface $objectManager;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
    }

    protected function tearDown(): void
    {
        $this->objectManager->get(WriterInterface::class)->delete(self::CONFIG_PATH);
        $this->objectManager->get(AppConfig::class)->clean();
    }

    public function test_a_saved_credential_is_encrypted_at_rest_and_read_back_in_the_clear(): void
    {
        $this->saveServices([
            '_row1' => ['openai' => ['api_key' => self::API_KEY, 'model' => 'gpt-4o']],
        ]);

        self::assertStringNotContainsString(
            self::API_KEY,
            $this->storedValue(),
            'The API key is in core_config_data in plaintext.'
        );

        $services = $this->objectManager->get(AiServiceSelectorInterface::class)->getAll();
        self::assertCount(1, $services);
        self::assertSame(self::API_KEY, $services[0]->getConfiguration()['api_key']);
        self::assertSame('gpt-4o', $services[0]->getConfiguration()['model']);
    }

    /**
     * The form shows a credential as `******`, so an administrator changing the model of a saved
     * row posts that placeholder back for a key they never saw. Storing it verbatim would replace
     * every credential on the page with six asterisks the next time anyone saves anything.
     */
    public function test_saving_the_masked_placeholder_keeps_the_stored_credential(): void
    {
        $this->saveServices([
            '_row1' => ['openai' => ['api_key' => self::API_KEY, 'model' => 'gpt-4o']],
        ]);

        $this->saveServices([
            '_row1' => [
                'openai' => [
                    'api_key' => SensitiveDataProcessor::OBSCURED_PLACEHOLDER,
                    'model' => 'gpt-4o-mini',
                ],
            ],
        ]);

        $configuration = $this->objectManager->get(AiServiceSelectorInterface::class)->getAll()[0]
            ->getConfiguration();

        self::assertSame(self::API_KEY, $configuration['api_key'], 'The stored credential was lost.');
        self::assertSame('gpt-4o-mini', $configuration['model'], 'The edit that came with it was lost.');
    }

    /**
     * The form must never render a credential, decrypted or not: an administrator opening the page
     * would put every configured provider's key in their browser, and anyone who can read the page
     * source, a screen share or a support screenshot has them too.
     *
     * The backend model comes from the config structure rather than being constructed here, so this
     * also asserts that the field is wired to the class that does the masking.
     */
    public function test_the_admin_form_is_given_a_placeholder_instead_of_the_credential(): void
    {
        $this->saveServices([
            '_row1' => ['openai' => ['api_key' => self::API_KEY, 'model' => 'gpt-4o']],
        ]);

        $field = $this->objectManager->get(Structure::class)->getElement(self::CONFIG_PATH);
        self::assertInstanceOf(Field::class, $field);

        $backendModel = $field->getBackendModel();
        self::assertInstanceOf(EncryptedServices::class, $backendModel);

        $backendModel->setPath(self::CONFIG_PATH);
        $backendModel->setValue($this->storedValue());
        $backendModel->afterLoad();

        $loaded = $backendModel->getValue();
        self::assertIsArray($loaded, 'The form is handed the rows already decoded.');
        self::assertSame(
            SensitiveDataProcessor::OBSCURED_PLACEHOLDER,
            $loaded['_row1']['openai']['api_key'],
            'The admin form was handed the credential itself.'
        );
        self::assertSame('gpt-4o', $loaded['_row1']['openai']['model'], 'Non-credential fields must still render.');
    }

    /**
     * A placeholder with nothing behind it is not a credential. Storing the asterisks verbatim
     * would send them to the provider as an API key and report the resulting authentication
     * failure as the provider's problem.
     */
    public function test_a_placeholder_with_no_stored_credential_behind_it_is_not_stored(): void
    {
        $this->saveServices([
            '_new_row' => [
                'openai' => [
                    'api_key' => SensitiveDataProcessor::OBSCURED_PLACEHOLDER,
                    'model' => 'gpt-4o',
                ],
            ],
        ]);

        $configuration = $this->objectManager->get(AiServiceSelectorInterface::class)->getAll()[0]
            ->getConfiguration();

        self::assertSame('', $configuration['api_key']);
    }

    /**
     * Row ids are the identity another module stores when an administrator picks a service, so a
     * save that leaves them alone is the difference between a stored selection surviving an
     * unrelated edit and silently pointing at another provider's account.
     */
    public function test_row_ids_survive_a_save(): void
    {
        $this->saveServices([
            '_first' => ['openai' => ['api_key' => self::API_KEY, 'model' => 'gpt-4o']],
            '_second' => ['anthropic' => ['api_key' => 'sk-ant-secret', 'model' => 'claude-sonnet-4-6']],
        ]);

        $ids = array_map(
            fn ($service): string => $service->getId(),
            $this->objectManager->get(AiServiceSelectorInterface::class)->getAll(),
        );

        self::assertSame(['_first', '_second'], $ids);
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
