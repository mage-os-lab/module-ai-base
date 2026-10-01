<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Client;

use Magento\Framework\Exception\LocalizedException;
use MageOS\AiBase\Model\Client\AiRequestNotSentException;
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
     * Nobody paid for this call: it never left this class, so the recording decorator (task 007)
     * needs to tell it apart from a call the provider actually rejected.
     */
    public function test_it_throws_request_not_sent_for_an_unsupported_option(): void
    {
        $this->expectException(AiRequestNotSentException::class);

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

    /**
     * Anthropic has no "required" tool choice of its own; its API calls the same concept "any".
     * Proves the value, not only the option name, gets translated.
     */
    public function test_translates_a_scalar_tool_choice_value_the_provider_spells_differently(): void
    {
        $normalized = $this->subject()->normalize('anthropic', ['tool_choice' => 'required']);

        self::assertSame(['type' => 'any'], $normalized['tool_choice']);
    }

    /**
     * Forcing one named tool is the one canonical value that is not a plain string: the caller
     * hands over `['tool' => 'name']` and the target shape has to carry that name to wherever the
     * provider expects it, nested arbitrarily deep.
     */
    public function test_substitutes_the_tool_name_into_the_providers_own_shape(): void
    {
        $normalized = $this->subject()->normalize('anthropic', ['tool_choice' => ['tool' => 'classify_product']]);

        self::assertSame(['type' => 'tool', 'name' => 'classify_product'], $normalized['tool_choice']);
    }

    /**
     * Chat-completions dialects nest the tool name one level deeper than Anthropic does, so this
     * also proves the placeholder substitution walks into nested arrays, not just the top level.
     */
    public function test_substitutes_the_tool_name_into_a_nested_provider_shape(): void
    {
        $normalizer = new OptionNormalizer(
            new BridgeRegistry(['openrouter' => ['dialect' => 'openai_chat']]),
            [
                'openai_chat' => [
                    'values' => [
                        'tool_choice' => [
                            'tool' => ['tool_choice' => ['type' => 'function', 'function' => ['name' => '{{name}}']]],
                        ],
                    ],
                ],
            ]
        );

        $normalized = $normalizer->normalize('openrouter', ['tool_choice' => ['tool' => 'classify_product']]);

        self::assertSame(
            ['type' => 'function', 'function' => ['name' => 'classify_product']],
            $normalized['tool_choice']
        );
    }

    /**
     * A single canonical option spreading across two top-level request fields (Anthropic's
     * `thinking` plus `output_config`) is exactly why value translation cannot be a rename: one
     * key in, several keys out.
     */
    public function test_a_value_mapped_option_can_expand_into_several_target_keys(): void
    {
        $normalized = $this->subject()->normalize('anthropic', ['reasoning_effort' => 'low']);

        self::assertSame(['type' => 'adaptive'], $normalized['thinking']);
        self::assertSame(['effort' => 'low'], $normalized['output_config']);
    }

    /**
     * `none` only needs to disable thinking; there is nothing sensible to put in `output_config`,
     * so that key must not appear at all.
     */
    public function test_a_value_mapped_option_can_set_only_some_of_its_possible_keys(): void
    {
        $normalized = $this->subject()->normalize('anthropic', ['reasoning_effort' => 'none']);

        self::assertSame(['type' => 'disabled'], $normalized['thinking']);
        self::assertArrayNotHasKey('output_config', $normalized);
    }

    /**
     * `auto` is every provider's own default, so a dialect that never translates tool_choice at
     * all (Ollama has no such concept) is left alone rather than failing a call that only asked
     * for what the provider already does anyway.
     */
    public function test_auto_tool_choice_is_a_silent_no_op_where_the_provider_has_no_equivalent(): void
    {
        $normalized = $this->subjectWithOllama()->normalize('ollama', ['tool_choice' => 'auto']);

        self::assertArrayNotHasKey('tool_choice', $normalized);
    }

    /**
     * Unlike `auto`, `required` asks for behaviour Ollama cannot provide, so silently dropping it
     * would leave the caller believing a tool call is forced when none is.
     */
    public function test_a_non_auto_tool_choice_is_refused_where_the_provider_has_no_equivalent(): void
    {
        $this->expectException(AiRequestNotSentException::class);
        $this->expectExceptionMessage('"required" value of the "tool_choice" option is not supported by AI service "ollama"');

        $this->subjectWithOllama()->normalize('ollama', ['tool_choice' => 'required']);
    }

    /**
     * Ollama's `think` field is the reasoning-effort equivalent, spelled as a plain boolean for
     * "none" and a level string otherwise.
     */
    public function test_reasoning_effort_is_translated_for_a_provider_with_its_own_vocabulary(): void
    {
        $normalized = $this->subjectWithOllama()->normalize('ollama', ['reasoning_effort' => 'none']);

        self::assertSame(false, $normalized['think']);
    }

    /**
     * Same rule as the four original options: addressing the provider's own field directly wins
     * over the neutral one, even when the neutral option expands into that same field.
     */
    public function test_the_providers_own_field_wins_over_a_value_mapped_option(): void
    {
        $normalized = $this->subject()->normalize('anthropic', [
            'reasoning_effort' => 'high',
            'thinking' => ['type' => 'enabled'],
        ]);

        self::assertSame(['type' => 'enabled'], $normalized['thinking']);
        self::assertSame(['effort' => 'high'], $normalized['output_config']);
    }

    /**
     * `tool_choice` is also Anthropic's own option name, so a caller who already forces a tool in
     * Anthropic's shape addressed the provider directly. That reached the wire untouched before
     * the option became canonical, and has to keep doing so.
     */
    public function test_a_provider_native_tool_choice_passes_through_untouched(): void
    {
        $native = ['type' => 'tool', 'name' => 'get_orders'];

        $normalized = $this->subject()->normalize('anthropic', ['tool_choice' => $native]);

        self::assertSame($native, $normalized['tool_choice']);
    }

    /**
     * A string outside the canonical set is a provider-native value too (OpenAI's "minimal"
     * effort), not a request for something the provider lacks, so it is not refused.
     */
    public function test_a_non_canonical_string_value_passes_through_untouched(): void
    {
        $normalized = $this->subjectWithOllama()->normalize('ollama', ['reasoning_effort' => 'minimal']);

        self::assertSame(['reasoning_effort' => 'minimal'], $normalized);
    }

    /**
     * Ollama has no tool_choice translation, but a native value is still the caller's business:
     * only a canonical value the dialect cannot express is refused.
     */
    public function test_a_provider_native_tool_choice_is_not_refused_where_no_translation_exists(): void
    {
        $native = ['type' => 'function', 'function' => ['name' => 'get_orders']];

        $normalized = $this->subjectWithOllama()->normalize('ollama', ['tool_choice' => $native]);

        self::assertSame($native, $normalized['tool_choice']);
    }

    /**
     * A provider with no equivalent for an option, and where dropping it is harmless, lists it
     * under `ignore`. Consumers set these options without knowing which backend an administrator
     * picked — this module's own Test Connection sends `max_tokens` — so refusing them would make
     * the provider unusable for all of them.
     *
     * @param string $option
     * @param mixed $value
     */
    #[\PHPUnit\Framework\Attributes\TestWith(['max_tokens', 400])]
    #[\PHPUnit\Framework\Attributes\TestWith(['temperature', 0.2])]
    #[\PHPUnit\Framework\Attributes\TestWith(['top_p', 0.9])]
    #[\PHPUnit\Framework\Attributes\TestWith(['stop', 'END'])]
    public function test_an_ignored_option_is_dropped_without_an_error(string $option, mixed $value): void
    {
        self::assertSame(['stream' => true], $this->subject()->normalize('opencode-custom', [$option => $value, 'stream' => true]));
    }

    /**
     * Only the options a dialect lists are dropped. An option with no mapping that the dialect
     * does not ignore is still refused, so a provider that genuinely cannot honour a setting keeps
     * saying so.
     */
    public function test_an_unmapped_option_that_is_not_ignored_is_still_refused(): void
    {
        $this->expectException(AiRequestNotSentException::class);
        $this->expectExceptionMessage('"stop"');

        $this->subject()->normalize('openai', ['stop' => 'END']);
    }

    /**
     * Ignoring is per dialect: another provider's mapping of the same option is untouched.
     */
    public function test_ignoring_an_option_in_one_dialect_leaves_other_dialects_alone(): void
    {
        self::assertSame(['max_output_tokens' => 16], $this->subject()->normalize('openai', ['max_tokens' => 16]));
    }

    private function subject(): OptionNormalizer
    {
        return new OptionNormalizer(
            new BridgeRegistry([
                'openai' => ['dialect' => 'openai_responses'],
                'anthropic' => ['dialect' => 'anthropic_messages'],
                'opencode-custom' => ['dialect' => 'opencode_server'],
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
                    'values' => [
                        'tool_choice' => [
                            'auto' => ['tool_choice' => ['type' => 'auto']],
                            'none' => ['tool_choice' => ['type' => 'none']],
                            'required' => ['tool_choice' => ['type' => 'any']],
                            'tool' => ['tool_choice' => ['type' => 'tool', 'name' => '{{name}}']],
                        ],
                        'reasoning_effort' => [
                            'none' => ['thinking' => ['type' => 'disabled']],
                            'low' => ['thinking' => ['type' => 'adaptive'], 'output_config' => ['effort' => 'low']],
                            'medium' => ['thinking' => ['type' => 'adaptive'], 'output_config' => ['effort' => 'medium']],
                            'high' => ['thinking' => ['type' => 'adaptive'], 'output_config' => ['effort' => 'high']],
                        ],
                    ],
                ],
                // What di.xml declares: nothing to map onto, all four dropped.
                'opencode_server' => ['map' => [], 'ignore' => ['max_tokens', 'temperature', 'top_p', 'stop']],
            ]
        );
    }

    /**
     * A separate fixture rather than folding into subject(): Ollama is the one dialect that
     * declares no tool_choice translation at all, which is the exact shape the no-op and refusal
     * tests need to exercise.
     */
    private function subjectWithOllama(): OptionNormalizer
    {
        return new OptionNormalizer(
            new BridgeRegistry(['ollama' => ['dialect' => 'ollama']]),
            [
                'ollama' => [
                    'values' => [
                        'reasoning_effort' => [
                            'none' => ['think' => false],
                            'low' => ['think' => 'low'],
                            'medium' => ['think' => 'medium'],
                            'high' => ['think' => 'high'],
                        ],
                    ],
                ],
            ]
        );
    }
}
