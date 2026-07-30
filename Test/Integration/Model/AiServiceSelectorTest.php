<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Integration\Model;

use Magento\Framework\App\Config;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AiBase\Api\AiServiceSelectorInterface;
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
}
