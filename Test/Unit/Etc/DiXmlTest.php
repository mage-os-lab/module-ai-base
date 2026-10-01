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

    /**
     * The opencode server's message endpoint has no sampling options, and every consumer sets
     * them without knowing which backend was picked; this module's own Test Connection sends
     * `max_tokens`. So the `opencode_server` dialect maps none and ignores all four. Losing either
     * half fails: a mapping would send a body field the server ignores anyway, and a missing
     * `ignore` entry turns a harmless option back into an error on every call.
     */
    public function test_the_opencode_server_dialect_maps_nothing_and_ignores_every_universal_option(): void
    {
        $dialect = $this->config->xpath(
            '//type[@name="MageOS\AiBase\Model\Client\BridgeRegistry"]/arguments/argument[@name="bridges"]'
            . '/item[@name="opencode-custom"]/item[@name="dialect"]',
        )[0] ?? null;
        self::assertSame('opencode_server', (string) $dialect);

        $base = '//type[@name="MageOS\AiBase\Model\Client\OptionNormalizer"]/arguments/argument[@name="dialects"]'
            . '/item[@name="opencode_server"]';

        $map = $this->config->xpath($base . '/item[@name="map"]');
        self::assertCount(1, $map, 'The opencode_server dialect must declare a map.');
        self::assertCount(0, $map[0]->children(), 'The opencode_server map must stay empty.');

        $ignored = array_map(
            static fn (\SimpleXMLElement $item): string => (string) $item['name'],
            $this->config->xpath($base . '/item[@name="ignore"]/item') ?: [],
        );
        sort($ignored);
        self::assertSame(['max_tokens', 'stop', 'temperature', 'top_p'], $ignored);
    }
}
