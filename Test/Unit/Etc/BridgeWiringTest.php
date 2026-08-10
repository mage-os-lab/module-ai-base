<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Etc;

use PHPUnit\Framework\TestCase;

/**
 * The admin form tells administrators which composer package makes a provider available,
 * so the wiring behind that claim has to be complete and has to match what is published.
 */
final class BridgeWiringTest extends TestCase
{
    private const DI_XML = __DIR__ . '/../../../src/etc/di.xml';
    private const COMPOSER_JSON = __DIR__ . '/../../../composer.json';

    public function test_every_bridge_declares_both_a_factory_and_a_package(): void
    {
        $incomplete = [];
        foreach ($this->bridges() as $code => $bridge) {
            if (($bridge['factory'] ?? '') === '' || ($bridge['package'] ?? '') === '') {
                $incomplete[] = $code;
            }
        }

        self::assertSame(
            [],
            $incomplete,
            'A bridge without a package name leaves the admin form unable to say what to install.'
        );
    }

    /**
     * The dialect decides how max_tokens and friends are spelled on the wire. A bridge without one
     * gets the caller's options passed through untouched, which is the silent mis-cap the
     * normalizer exists to prevent, so a bundled bridge must always name a declared dialect.
     */
    public function test_every_bridge_declares_a_dialect_the_normalizer_knows(): void
    {
        $dialects = $this->declaredDialects();
        $unknown = [];
        foreach ($this->bridges() as $code => $bridge) {
            if (!in_array($bridge['dialect'] ?? '', $dialects, true)) {
                $unknown[$code] = $bridge['dialect'] ?? '';
            }
        }

        self::assertSame([], $unknown, 'Declared dialects: ' . implode(', ', $dialects));
    }

    /**
     * Every dialect has to spell the option that most often decides whether a request is even
     * accepted; the rest are optional per provider.
     */
    public function test_every_dialect_maps_the_output_token_limit(): void
    {
        $di = new \SimpleXMLElement((string) file_get_contents(self::DI_XML));
        $without = [];
        foreach ($this->declaredDialects() as $dialect) {
            $mapped = $di->xpath(sprintf(
                '//type[@name="MageOS\\AiBase\\Model\\Client\\OptionNormalizer"]'
                . '/arguments/argument[@name="dialects"]/item[@name="%s"]'
                . '/item[@name="map"]/item[@name="max_tokens"]',
                $dialect
            )) ?: [];
            if ($mapped === []) {
                $without[] = $dialect;
            }
        }

        self::assertSame([], $without);
    }

    /**
     * The option names themselves, not merely that some name is present.
     *
     * These are the values verified against the bridge packages at symfony/ai-platform 0.12, and a
     * wrong one is a 400 from the provider for an unknown body field rather than anything a test
     * of shape would notice. Every other test in this suite reads structure; this one is the only
     * thing standing between a typo here and every call to that provider failing in production.
     *
     * @dataProvider shippedDialectProvider
     */
    public function test_each_dialect_spells_the_universal_options_the_way_its_provider_does(
        string $dialect,
        array $expectedMap,
    ): void {
        self::assertSame($expectedMap, $this->dialectSection($dialect, 'map'));
    }

    /**
     * @return array<string, array{0: string, 1: array<string, string>}>
     */
    public static function shippedDialectProvider(): array
    {
        return [
            'OpenAI-compatible chat completions' => ['openai_chat', [
                'max_tokens' => 'max_tokens',
                'temperature' => 'temperature',
                'top_p' => 'top_p',
                'stop' => 'stop',
            ]],
            // The Responses API renamed the limit and dropped stop sequences entirely.
            'OpenAI and Azure Responses API' => ['openai_responses', [
                'max_tokens' => 'max_output_tokens',
                'temperature' => 'temperature',
                'top_p' => 'top_p',
            ]],
            'Anthropic Messages API' => ['anthropic_messages', [
                'max_tokens' => 'max_tokens',
                'temperature' => 'temperature',
                'top_p' => 'top_p',
                'stop' => 'stop_sequences',
            ]],
            // Gemini nests these under generationConfig and spells them in camelCase.
            'Gemini generationConfig' => ['gemini', [
                'max_tokens' => 'maxOutputTokens',
                'temperature' => 'temperature',
                'top_p' => 'topP',
                'stop' => 'stopSequences',
            ]],
            'Ollama nested options' => ['ollama', [
                'max_tokens' => 'num_predict',
                'temperature' => 'temperature',
                'top_p' => 'top_p',
                'stop' => 'stop',
            ]],
        ];
    }

    /**
     * Anthropic is the one bundled provider that rejects a request omitting the output limit, so
     * the default that covers for it has to actually be wired, and has to be a number.
     */
    public function test_anthropic_carries_the_output_token_default_its_api_requires(): void
    {
        $defaults = $this->dialectSection('anthropic_messages', 'defaults');

        self::assertArrayHasKey('max_tokens', $defaults);
        self::assertTrue(is_numeric($defaults['max_tokens']), 'Anthropic rejects a non-numeric limit.');
    }

    /**
     * Every option a provider wants as an array has to be declared, or a single stop sequence
     * reaches it as a bare string and is rejected or ignored.
     */
    public function test_every_dialect_declaring_a_stop_sequence_also_declares_its_shape(): void
    {
        $missing = [];
        foreach (['anthropic_messages' => 1, 'gemini' => 1, 'ollama' => 1] as $dialect => $ignored) {
            $lists = $this->dialectSection($dialect, 'lists');
            if (!in_array('stop', $lists, true) && !array_key_exists('stop', $lists)) {
                $missing[] = $dialect;
            }
        }

        self::assertSame([], $missing);
    }

    /**
     * One `map`/`lists`/`defaults` block of a dialect, as name => value.
     *
     * @param string $dialect
     * @param string $section
     * @return array<string, string>
     */
    private function dialectSection(string $dialect, string $section): array
    {
        $di = new \SimpleXMLElement((string) file_get_contents(self::DI_XML));
        $items = $di->xpath(sprintf(
            '//type[@name="MageOS\\AiBase\\Model\\Client\\OptionNormalizer"]'
            . '/arguments/argument[@name="dialects"]/item[@name="%s"]/item[@name="%s"]/item',
            $dialect,
            $section
        )) ?: [];

        $values = [];
        foreach ($items as $item) {
            $values[(string) $item['name']] = trim((string) $item);
        }

        return $values;
    }

    /**
     * @return array<int, string>
     */
    private function declaredDialects(): array
    {
        $di = new \SimpleXMLElement((string) file_get_contents(self::DI_XML));
        $items = $di->xpath(
            '//type[@name="MageOS\\AiBase\\Model\\Client\\OptionNormalizer"]'
            . '/arguments/argument[@name="dialects"]/item'
        ) ?: [];

        return array_map(static fn ($item): string => (string) $item['name'], $items);
    }

    public function test_every_bridge_belongs_to_a_registered_service(): void
    {
        $orphans = array_values(array_diff(array_keys($this->bridges()), $this->registeredServiceCodes()));

        self::assertSame(
            [],
            $orphans,
            'These bridges are wired to service codes that no registered provider uses.'
        );
    }

    /**
     * Every package the admin form can tell someone to install must also be listed in `suggest`,
     * so `composer suggest` and the admin form cannot drift apart.
     */
    public function test_every_bridge_package_is_listed_in_composer_suggest(): void
    {
        $suggested = array_keys(
            json_decode((string) file_get_contents(self::COMPOSER_JSON), true)['suggest'] ?? []
        );
        $packages = array_values(array_unique(array_column($this->bridges(), 'package')));

        self::assertSame([], array_values(array_diff($packages, $suggested)));
    }

    public function test_no_longer_suggests_the_platform_package_alone(): void
    {
        $suggested = array_keys(
            json_decode((string) file_get_contents(self::COMPOSER_JSON), true)['suggest'] ?? []
        );

        self::assertNotContains(
            'symfony/ai-platform',
            $suggested,
            'The platform package ships no bridges since 0.12; suggesting it alone sends people to a dead end.'
        );
    }

    /**
     * Every provider this module ships must have a bridge, so no bundled provider can be
     * offered in the admin form that the built-in client is unable to use.
     *
     * This is a policy for bundled providers only. Third parties may still register a provider
     * with no bridge; the form marks those unsupported rather than offering an install command.
     */
    public function test_every_bundled_provider_has_a_bridge(): void
    {
        $withoutBridge = array_values(array_diff($this->registeredServiceCodes(), array_keys($this->bridges())));

        self::assertSame(
            [],
            $withoutBridge,
            'These providers ship in the admin form but cannot be used through the built-in client. '
            . 'Either wire a bridge for them or do not register them.'
        );
    }

    /**
     * @return array<string, array{factory: string, package: string}>
     */
    private function bridges(): array
    {
        $di = new \SimpleXMLElement((string) file_get_contents(self::DI_XML));
        $items = $di->xpath(
            '//type[@name="MageOS\\AiBase\\Model\\Client\\BridgeRegistry"]'
            . '/arguments/argument[@name="bridges"]/item'
        ) ?: [];

        $bridges = [];
        foreach ($items as $item) {
            $entry = [];
            foreach ($item->item as $child) {
                $entry[(string) $child['name']] = trim((string) $child);
            }
            $bridges[(string) $item['name']] = $entry;
        }

        return $bridges;
    }

    /**
     * @return array<int, string>
     */
    private function registeredServiceCodes(): array
    {
        $di = new \SimpleXMLElement((string) file_get_contents(self::DI_XML));
        $items = $di->xpath(
            '//type[@name="MageOS\\AiBase\\Model\\ServiceRegistry"]'
            . '/arguments/argument[@name="services"]/item'
        ) ?: [];

        return array_map(static fn ($item): string => (string) $item['name'], $items);
    }
}
