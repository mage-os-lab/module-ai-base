<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Etc;

use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * Guards the `BridgeRegistry` `bridges` argument in di.xml, the one place that decides which
 * provider's cache tokens the client normalizes as living outside the reported prompt count.
 */
final class DiXmlTest extends TestCase
{
    private SimpleXMLElement $config;

    protected function setUp(): void
    {
        $path = dirname(__DIR__, 3) . '/src/etc/di.xml';
        $this->config = new SimpleXMLElement((string) file_get_contents($path));
    }

    /**
     * Anthropic's Messages API is the one bridge known today whose reported prompt count
     * excludes cache reads and writes, so only its bridge entry may declare the flag true.
     */
    public function test_it_declares_cache_outside_prompt_true_for_anthropic_only(): void
    {
        $bridgeItems = $this->config->xpath(
            '//type[@name="MageOS\AiBase\Model\Client\BridgeRegistry"]/arguments/argument[@name="bridges"]/item',
        );
        self::assertNotEmpty($bridgeItems);

        foreach ($bridgeItems as $bridgeItem) {
            $serviceCode = (string) $bridgeItem['name'];
            $flag = $bridgeItem->xpath('item[@name="cache_outside_prompt"]')[0] ?? null;

            if ($serviceCode === 'anthropic') {
                self::assertNotNull($flag, $serviceCode);
                self::assertSame('true', (string) $flag);
                continue;
            }

            self::assertNull($flag, $serviceCode);
        }
    }
}
