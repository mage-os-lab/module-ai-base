<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Client;

use Magento\Framework\Exception\LocalizedException;
use MageOS\AiBase\Model\Client\BridgeRegistry;
use MageOS\AiBase\Model\Client\OptionNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * The dialect definitions under test mirror `etc/di.xml`; `Etc\BridgeWiringTest` is what keeps the
 * shipped wiring honest, this covers the translation rules themselves.
 */
final class OptionNormalizerTest extends TestCase
{
    public function test_renames_an_option_to_what_the_target_provider_calls_it(): void
    {
        $normalized = $this->subject()->normalize('openai', ['max_tokens' => 400]);

        self::assertSame(['max_output_tokens' => 400], $normalized);
    }

    public function test_leaves_an_option_alone_when_the_provider_already_spells_it_that_way(): void
    {
        $normalized = $this->subject()->normalize('anthropic', ['max_tokens' => 400]);

        self::assertSame(400, $normalized['max_tokens']);
    }

    public function test_passes_provider_specific_options_through_untouched(): void
    {
        $normalized = $this->subject()->normalize('anthropic', ['thinking' => ['type' => 'enabled']]);

        self::assertSame(['type' => 'enabled'], $normalized['thinking']);
    }

    public function test_wraps_a_single_value_for_providers_that_want_a_list(): void
    {
        $normalized = $this->subject()->normalize('anthropic', ['stop' => 'END']);

        self::assertSame(['END'], $normalized['stop_sequences']);
    }

    public function test_keeps_a_list_a_list(): void
    {
        $normalized = $this->subject()->normalize('anthropic', ['stop' => ['END', 'STOP']]);

        self::assertSame(['END', 'STOP'], $normalized['stop_sequences']);
    }

    /**
     * Asserted as an int on purpose. Magento's `number` argument interpreter hands back the raw
     * XML node value, so the di.xml default arrives here as the string "4096", and Anthropic
     * rejects `"max_tokens": "4096"` as not-an-integer — defeating the one default that exists to
     * keep its requests valid.
     */
    public function test_supplies_a_value_the_provider_requires_and_the_caller_left_out(): void
    {
        $normalized = $this->subject()->normalize('anthropic', []);

        self::assertSame(4096, $normalized['max_tokens']);
    }

    /**
     * Same problem from the other direction: everything in core_config_data is a string, and a
     * consumer passing its own configured limit straight through should not be punished for it.
     */
    public function test_a_numeric_string_from_the_caller_reaches_the_provider_as_a_number(): void
    {
        $normalized = $this->subject()->normalize('openai', ['max_tokens' => '400', 'temperature' => '0.7']);

        self::assertSame(400, $normalized['max_output_tokens']);
        self::assertSame(0.7, $normalized['temperature']);
    }

    public function test_a_non_numeric_value_is_left_exactly_as_it_was(): void
    {
        $normalized = $this->subject()->normalize('anthropic', ['stop' => '5']);

        self::assertSame(['5'], $normalized['stop_sequences'], 'A stop sequence is text, not a number.');
    }

    /**
     * The caller naming the provider's own option addressed it more precisely than the neutral
     * name did, so replacing their value would send a cap they never wrote.
     */
    public function test_an_option_named_the_providers_own_way_wins_over_the_neutral_one(): void
    {
        $normalized = $this->subject()->normalize('openai', [
            'max_output_tokens' => 100,
            'max_tokens' => 4000,
        ]);

        self::assertSame(100, $normalized['max_output_tokens']);
        self::assertArrayNotHasKey('max_tokens', $normalized);
    }

    /**
     * `map` reads its item names, so a third party copying that idiom into `lists` writes
     * `<item name="stop">stop</item>`. Reading only the values would leave it silently inert.
     */
    public function test_a_list_option_is_recognised_in_either_di_xml_shape(): void
    {
        $keyed = new OptionNormalizer(
            new BridgeRegistry(['anthropic' => ['dialect' => 'anthropic_messages']]),
            [
                'anthropic_messages' => [
                    'map' => ['stop' => 'stop_sequences'],
                    'lists' => ['stop' => 'stop'],
                ],
            ]
        );

        self::assertSame(['END'], $keyed->normalize('anthropic', ['stop' => 'END'])['stop_sequences']);
    }

    public function test_applies_no_default_where_the_provider_has_one_of_its_own(): void
    {
        self::assertSame([], $this->subject()->normalize('openai', []));
    }

    /**
     * Silently dropping it would leave the caller believing a limit is in force that never reaches
     * the wire, which is the failure this whole class exists to prevent.
     */
    public function test_refuses_an_option_the_provider_has_no_equivalent_for(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('"stop" option is not supported by AI service "openai"');

        $this->subject()->normalize('openai', ['stop' => 'END']);
    }

    /**
     * A provider this module knows nothing about is better served raw than mangled: its consumer
     * addressed it deliberately and knows its option names.
     */
    public function test_passes_everything_through_for_a_service_with_no_declared_dialect(): void
    {
        $options = ['max_tokens' => 400, 'stop' => 'END'];

        self::assertSame($options, $this->subject()->normalize('some_third_party', $options));
    }

    private function subject(): OptionNormalizer
    {
        return new OptionNormalizer(
            new BridgeRegistry([
                'openai' => ['dialect' => 'openai_responses'],
                'anthropic' => ['dialect' => 'anthropic_messages'],
                'some_third_party' => ['factory' => 'Their\\Own\\Factory'],
            ]),
            [
                'openai_responses' => ['map' => [
                    'max_tokens' => 'max_output_tokens',
                    'temperature' => 'temperature',
                    'top_p' => 'top_p',
                ]],
                'anthropic_messages' => [
                    'map' => [
                        'max_tokens' => 'max_tokens',
                        'temperature' => 'temperature',
                        'top_p' => 'top_p',
                        'stop' => 'stop_sequences',
                    ],
                    'lists' => ['stop'],
                    // A string, because that is literally what di.xml's `number` interpreter yields.
                    'defaults' => ['max_tokens' => '4096'],
                ],
            ]
        );
    }
}
