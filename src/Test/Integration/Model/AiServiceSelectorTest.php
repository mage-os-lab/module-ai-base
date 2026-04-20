<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Integration\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AiBase\Api\AiServiceSelectorInterface;
use PHPUnit\Framework\TestCase;

final class AiServiceSelectorTest extends TestCase
{
    public function test_round_trips_configuration_through_scope_config(): void
    {
        $objectManager = Bootstrap::getObjectManager();

        /** @var WriterInterface $configWriter */
        $configWriter = $objectManager->get(WriterInterface::class);
        $json = json_encode([
            '_row1' => ['openai'    => ['apikey' => 'sk-test', 'model' => 'gpt-4o']],
            '_row2' => ['anthropic' => ['apikey' => 'sk-ant',  'model' => 'claude-sonnet-4-6']],
        ], JSON_THROW_ON_ERROR);
        $configWriter->save('mageos_ai/services/configuration', $json);

        /** @var ScopeConfigInterface $scopeConfig */
        $scopeConfig = $objectManager->get(ScopeConfigInterface::class);
        $scopeConfig->clean();

        /** @var AiServiceSelectorInterface $selector */
        $selector = $objectManager->get(AiServiceSelectorInterface::class);
        $services = $selector->getAll();

        self::assertCount(2, $services);
        self::assertSame('openai', $services[0]->getCode());
        self::assertSame(['apikey' => 'sk-test', 'model' => 'gpt-4o'], $services[0]->getConfiguration());
        self::assertSame('anthropic', $services[1]->getCode());

        $openAiOnly = $selector->getByCode('openai');
        self::assertCount(1, $openAiOnly);

        $configWriter->delete('mageos_ai/services/configuration');
    }
}
