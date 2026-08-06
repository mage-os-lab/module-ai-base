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
}
