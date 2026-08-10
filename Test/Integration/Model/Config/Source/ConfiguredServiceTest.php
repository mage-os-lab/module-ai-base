<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Integration\Model\Config\Source;

use Magento\Framework\App\Config as AppConfig;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AiBase\Model\Config\Source\ConfiguredService;
use MageOS\AiBase\Model\Config\Source\ConfiguredServiceWithAutomatic;
use MageOS\AiBase\Model\ServiceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The option source consumer modules point their own `system.xml` at, reading real stored rows.
 *
 * The unit test of this class hands it a registry it builds itself, so it cannot tell whether the
 * source and the admin form are looking at the same providers. That is the failure worth catching:
 * a provider registered for the form but not for the source is not an error, it is a raw machine
 * code in an administrator's dropdown.
 */
final class ConfiguredServiceTest extends TestCase
{
    private const CONFIG_PATH = 'mageos_ai/services/configuration';

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

    public function test_labels_a_configured_row_with_its_provider_name_and_model(): void
    {
        $this->storeServices([
            '_row1' => ['openai' => ['api_key' => 'k', 'model' => 'gpt-4o']],
        ]);

        $options = $this->objectManager->create(ConfiguredService::class)->toOptionArray();

        self::assertCount(1, $options);
        self::assertSame('_row1', $options[0]['value'], 'The stored value has to be the row id.');
        self::assertStringContainsString('OpenAI', (string) $options[0]['label']);
        self::assertStringContainsString('gpt-4o', (string) $options[0]['label']);
    }

    /**
     * Every provider the admin form offers has to be nameable here too, or picking one leaves the
     * administrator reading a machine code.
     */
    public function test_names_every_provider_the_admin_form_can_configure(): void
    {
        $registry = $this->objectManager->get(ServiceRegistry::class);
        $codes = array_keys($registry->getAll());

        $rows = [];
        foreach ($codes as $index => $code) {
            $rows['_row' . $index] = [$code => ['model' => 'a-model']];
        }
        $this->storeServices($rows);

        $labels = array_column(
            $this->objectManager->create(ConfiguredService::class)->toOptionArray(),
            'label',
            'value'
        );

        foreach ($codes as $index => $code) {
            self::assertStringStartsWith(
                (string) $registry->get($code)?->getName(),
                (string) $labels['_row' . $index],
                "The row of '$code' fell back to its raw service code."
            );
        }
    }

    /**
     * Two rows of the same provider and model are a legitimate setup (two accounts, two billing
     * owners), and an administrator cannot choose between two identical labels.
     */
    public function test_numbers_rows_that_would_otherwise_share_a_label(): void
    {
        $this->storeServices([
            '_row1' => ['openai' => ['api_key' => 'k1', 'model' => 'gpt-4o']],
            '_row2' => ['openai' => ['api_key' => 'k2', 'model' => 'gpt-4o']],
        ]);

        $labels = array_map(
            static fn (array $option): string => (string) $option['label'],
            $this->objectManager->create(ConfiguredService::class)->toOptionArray(),
        );

        self::assertCount(2, array_unique($labels), "Both rows are labelled '{$labels[0]}'.");
    }

    /**
     * The "Automatic" option is what a consumer's field falls back to, so it has to be offered even
     * with nothing configured, and it has to carry the empty value that means "decide at runtime".
     */
    public function test_the_automatic_variant_offers_an_empty_valued_option_with_nothing_configured(): void
    {
        $options = $this->objectManager->create(ConfiguredServiceWithAutomatic::class)->toOptionArray();

        self::assertNotSame([], $options);
        self::assertSame('', $options[0]['value']);
    }

    /**
     * @param array<string, array<string, array<string, string>>> $rows
     * @return void
     */
    private function storeServices(array $rows): void
    {
        $this->objectManager->get(WriterInterface::class)->save(
            self::CONFIG_PATH,
            json_encode($rows, JSON_THROW_ON_ERROR)
        );
        $this->objectManager->get(AppConfig::class)->clean();
    }
}
