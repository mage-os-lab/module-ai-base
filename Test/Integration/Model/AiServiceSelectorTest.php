<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Integration\Model;

use Magento\Framework\App\Config;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AiBase\Api\AiServiceSelectorInterface;
use MageOS\AiBase\Model\Config\Source\ConfiguredService;
use PHPUnit\Framework\TestCase;

final class AiServiceSelectorTest extends TestCase
{
    private ObjectManagerInterface $objectManager;
    private WriterInterface $configWriter;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
        $this->configWriter = $this->objectManager->get(WriterInterface::class);
    }

    protected function tearDown(): void
    {
        $this->configWriter->delete('mageos_ai/services/configuration');
    }

    public function test_round_trips_configuration_through_scope_config(): void
    {
        $json = json_encode([
            '_row1' => ['openai'    => ['api_key' => 'sk-test', 'model' => 'gpt-4o']],
            '_row2' => ['anthropic' => ['api_key' => 'sk-ant',  'model' => 'claude-sonnet-4-6']],
        ], JSON_THROW_ON_ERROR);
        $this->configWriter->save('mageos_ai/services/configuration', $json);

        $this->objectManager->get(Config::class)->clean();

        /** @var AiServiceSelectorInterface $selector */
        $selector = $this->objectManager->get(AiServiceSelectorInterface::class);

        $services = $selector->getAll();
        self::assertCount(2, $services);
        self::assertSame('openai', $services[0]->getCode());
        self::assertSame(['api_key' => 'sk-test', 'model' => 'gpt-4o'], $services[0]->getConfiguration());
        self::assertSame('anthropic', $services[1]->getCode());

        $openAiOnly = $selector->getByCode('openai');
        self::assertCount(1, $openAiOnly);
    }

    /**
     * A consumer module stores the row id an administrator picked, so the id it reads back later
     * has to be the same one the stored configuration carries.
     */
    public function test_resolves_a_stored_row_id_back_to_its_service(): void
    {
        $json = json_encode([
            '_1712345678901_901' => ['openai' => ['api_key' => 'sk-one', 'model' => 'gpt-4o']],
            '_1712345678902_902' => ['openai' => ['api_key' => 'sk-two', 'model' => 'o1-mini']],
        ], JSON_THROW_ON_ERROR);
        $this->configWriter->save('mageos_ai/services/configuration', $json);

        $this->objectManager->get(Config::class)->clean();

        /** @var AiServiceSelectorInterface $selector */
        $selector = $this->objectManager->get(AiServiceSelectorInterface::class);

        self::assertSame('_1712345678901_901', $selector->getAll()[0]->getId());

        $second = $selector->getById('_1712345678902_902');
        self::assertNotNull($second);
        self::assertSame('openai', $second->getCode());
        self::assertSame('o1-mini', $second->getConfiguration()['model']);

        self::assertNull($selector->getById('_never_saved'));
    }

    /**
     * The option source is what a consumer module's system.xml field points at, so it has to be
     * constructible from DI with the provider list this module wires for it.
     */
    public function test_option_source_lists_configured_rows_with_provider_names(): void
    {
        $json = json_encode([
            '_1712345678901_901' => ['openai' => ['api_key' => 'sk-one', 'model' => 'gpt-4o']],
        ], JSON_THROW_ON_ERROR);
        $this->configWriter->save('mageos_ai/services/configuration', $json);

        $this->objectManager->get(Config::class)->clean();

        $options = $this->objectManager->create(ConfiguredService::class)->toOptionArray();

        self::assertCount(1, $options);
        self::assertSame('_1712345678901_901', $options[0]['value']);
        self::assertStringStartsWith('OpenAI (gpt-4o', (string) $options[0]['label']);
    }

    /**
     * A disabled row is configuration an administrator wants kept but not called. It has to stay in
     * the stored value, keeping its id and its credentials, while disappearing from every lookup:
     * a consumer that still holds its id must not be handed a service nobody meant to be used.
     */
    public function test_a_disabled_row_is_kept_in_storage_and_withheld_from_every_lookup(): void
    {
        $json = json_encode([
            '_enabled_row' => ['openai' => ['api_key' => 'sk-live', 'model' => 'gpt-4o', '_enabled' => '1']],
            '_disabled_row' => ['openai' => ['api_key' => 'sk-paused', 'model' => 'gpt-4o', '_enabled' => '0']],
        ], JSON_THROW_ON_ERROR);
        $this->configWriter->save('mageos_ai/services/configuration', $json);
        $this->objectManager->get(Config::class)->clean();

        /** @var AiServiceSelectorInterface $selector */
        $selector = $this->objectManager->get(AiServiceSelectorInterface::class);

        self::assertCount(1, $selector->getAll());
        self::assertSame('_enabled_row', $selector->getAll()[0]->getId());
        self::assertCount(1, $selector->getByCode('openai'));
        self::assertNull($selector->getById('_disabled_row'), 'A stored id must not resolve to a disabled row.');

        self::assertStringContainsString(
            '_disabled_row',
            (string) $this->objectManager->get(\Magento\Framework\App\Config\ScopeConfigInterface::class)
                ->getValue('mageos_ai/services/configuration'),
            'The row itself has to survive, or disabling one would destroy its credentials.'
        );
    }

    /**
     * Every row saved before this setting existed carries no value for it, and those rows worked.
     */
    public function test_a_row_without_the_setting_is_still_handed_out(): void
    {
        $json = json_encode([
            '_legacy_row' => ['openai' => ['api_key' => 'sk-old', 'model' => 'gpt-4o']],
        ], JSON_THROW_ON_ERROR);
        $this->configWriter->save('mageos_ai/services/configuration', $json);
        $this->objectManager->get(Config::class)->clean();

        self::assertCount(1, $this->objectManager->get(AiServiceSelectorInterface::class)->getAll());
    }
}
