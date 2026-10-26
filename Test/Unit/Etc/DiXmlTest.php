<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Etc;

use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * Guards the parts of di.xml whose mistakes only show up as a provider rejecting the request.
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
     * symfony/ai's Gemini bridge nests every option under generationConfig except `tool_config`,
     * which it lifts to the top of the request. Spelled `toolConfig`, a tool choice stays nested
     * and Gemini answers every call that sets one with a 400.
     */
    public function test_every_gemini_tool_choice_lands_under_the_key_the_bridge_lifts_out(): void
    {
        $fragments = $this->config->xpath(
            '//type[@name="MageOS\AiBase\Model\Client\OptionNormalizer"]/arguments/argument[@name="dialects"]'
            . '/item[@name="gemini"]/item[@name="values"]/item[@name="tool_choice"]/item',
        );
        self::assertNotEmpty($fragments);

        foreach ($fragments as $fragment) {
            $keys = array_map(
                static fn (SimpleXMLElement $item): string => (string) $item['name'],
                $fragment->xpath('item'),
            );

            self::assertSame(['tool_config'], $keys, (string) $fragment['name']);
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
            static fn (SimpleXMLElement $item): string => (string) $item['name'],
            $this->config->xpath($base . '/item[@name="ignore"]/item') ?: [],
        );
        sort($ignored);
        self::assertSame(['max_tokens', 'stop', 'temperature', 'top_p'], $ignored);
    }
}
