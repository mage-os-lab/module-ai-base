<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Client;

/**
 * Translates the handful of options every provider has into the name the target provider uses.
 *
 * Options reach a provider's request body almost untouched, and OpenAI-compatible endpoints reject
 * unknown body fields outright. So `['max_tokens' => 400]` is not one option: it is
 * `max_output_tokens` on OpenAI's and Azure's Responses API, `max_tokens` on Anthropic and the
 * chat-completions providers, `maxOutputTokens` on Google, and `num_predict` on Ollama. Passing the
 * raw array through means moving a configured row from one provider to another either fails with a
 * 400 or silently generates under a different cap than the one the caller set — from configuration
 * that did not change. Anthropic makes it worse by requiring `max_tokens`, so the same call that
 * worked everywhere else fails there with nothing to point at.
 *
 * Only the universal options are translated (see MAPPED_OPTIONS and VALUE_MAPPED_OPTIONS).
 * Everything else passes through verbatim, which keeps provider-specific features (Anthropic's
 * `thinking`, Ollama's `keep_alive`, structured output) reachable for a consumer that has
 * deliberately picked its backend.
 *
 * `tool_choice` and `reasoning_effort` need more than a rename: `required` is Anthropic's `any`,
 * and a single canonical value can spread across several target fields (Anthropic's
 * `reasoning_effort` sets both `thinking` and `output_config`). `values` in a dialect covers that:
 * canonical value => the fragment of request keys it becomes, merged into the options rather than
 * assigned to one renamed key. Forcing one named tool is the one canonical value that is not a
 * plain string (`['tool' => '<name>']`), so its fragment carries the TOOL_NAME_PLACEHOLDER token
 * wherever the provider expects the name, substituted in at translation time.
 *
 * Dialects are wired in `di.xml` and the bridge registry says which dialect a service code speaks,
 * so a third party registering a provider declares it in the same entry as its bridge.
 *
 * `lists` is keyed loosely on purpose: `di.xml` lets a third party write either `<item name="stop">stop</item>`
 * or a bare list entry, and wantsList() honours both, so the shape has to allow both.
 *
 * @phpstan-type Dialect array{
 *     map?: array<string,string>,
 *     lists?: array<array-key, string>,
 *     defaults?: array<string,mixed>,
 *     values?: array<string, array<string, array<string,mixed>>>,
 *     ignore?: array<array-key, string>
 * }
 * @phpstan-type RequestOptions array<string,mixed>
 */
class OptionNormalizer
{
    /**
     * Provider-neutral option names translated by renaming a single key.
     *
     * Deliberately small: these four are the ones every provider models with one field each, so
     * they are the ones a consumer can set without knowing which backend an administrator
     * configured.
     */
    private const MAPPED_OPTIONS = ['max_tokens', 'temperature', 'top_p', 'stop'];

    /**
     * Provider-neutral option names whose value, not only its key, differs per provider.
     *
     * Keyed by option, listing the canonical values each accepts. `required` becomes Anthropic's
     * `any`; a single value can expand into several target keys. See the `values` dialect key this
     * class reads for them.
     *
     * The value list is what tells a canonical request apart from a provider-native one. For
     * `tool_choice` the canonical name is also the provider's own on Anthropic, OpenAI, Azure and
     * every OpenAI-compatible endpoint, so `['type' => 'tool', 'name' => 'x']` is a caller who
     * addressed Anthropic directly, and it passes through untouched like any other native option.
     */
    private const VALUE_MAPPED_OPTIONS = [
        'tool_choice' => ['auto', 'none', 'required', self::VALUE_KEY_TOOL],
        'reasoning_effort' => ['none', 'low', 'medium', 'high'],
    ];

    /**
     * Dialect key holding canonical option name => provider option name.
     */
    private const KEY_MAP = 'map';

    /**
     * Dialect key listing canonical options the provider expects as an array of strings.
     */
    private const KEY_LISTS = 'lists';

    /**
     * Dialect key holding values applied when the caller supplied none.
     */
    private const KEY_DEFAULTS = 'defaults';

    /**
     * Dialect key holding canonical option name => canonical value => target request fragment.
     */
    private const KEY_VALUES = 'values';

    /**
     * Lookup key, within a VALUE_MAPPED_OPTIONS entry of `values`, for the "force this named tool"
     * shape of `tool_choice`. The only canonical value that is not a plain string.
     */
    private const VALUE_KEY_TOOL = 'tool';

    /**
     * Token inside a `values` fragment that gets replaced with the requested tool's name.
     */
    private const TOOL_NAME_PLACEHOLDER = '{{name}}';

    /**
     * Canonical option => the one value every provider treats as its own default.
     *
     * A dialect that declares no translation at all for the option (Ollama has no tool_choice
     * equivalent) is left alone when the caller asked for this value, rather than refused for a
     * call that only asked for what the provider already does anyway.
     */
    private const NEUTRAL_DEFAULT_VALUES = ['tool_choice' => 'auto'];

    /**
     * Dialect key listing canonical options the provider has no equivalent for and that are
     * dropped without an error, rather than refused.
     */
    private const KEY_IGNORE = 'ignore';

    /**
     * @param BridgeRegistry $bridgeRegistry Says which dialect each service code speaks
     * @param array<string,Dialect> $dialects Dialect name => ['map' => [], 'lists' => [], 'defaults' => []]
     */
    public function __construct(
        private readonly BridgeRegistry $bridgeRegistry,
        private readonly array $dialects = [],
    ) {
    }

    /**
     * Rewrite the universal options for the given service, leaving everything else alone.
     *
     * A service code with no declared dialect is passed through untouched rather than guessed at:
     * a third-party provider this module knows nothing about is better served raw than mangled.
     *
     * @param string $serviceCode
     * @param RequestOptions $options
     * @return RequestOptions
     * @throws AiRequestNotSentException When an option has no equivalent at the target provider and
     *         the provider's dialect does not list it under `ignore`
     */
    public function normalize(string $serviceCode, array $options): array
    {
        $dialect = $this->dialects[$this->bridgeRegistry->getDialect($serviceCode) ?? ''] ?? null;
        if (!is_array($dialect)) {
            return $options;
        }

        foreach (self::MAPPED_OPTIONS as $canonical) {
            $options = $this->applyOption($serviceCode, $dialect, $options, $canonical);
        }

        foreach (array_keys(self::VALUE_MAPPED_OPTIONS) as $canonical) {
            $options = $this->applyValueOption($serviceCode, $dialect, $options, $canonical);
        }

        return $options;
    }

    /**
     * Move one canonical option onto its provider name, or apply the provider's required default.
     *
     * @param string $serviceCode
     * @param Dialect $dialect
     * @param RequestOptions $options
     * @param string $canonical
     * @return RequestOptions
     * @throws AiRequestNotSentException
     */
    private function applyOption(string $serviceCode, array $dialect, array $options, string $canonical): array
    {
        $target = $dialect[self::KEY_MAP][$canonical] ?? null;

        if (!array_key_exists($canonical, $options)) {
            return $this->applyDefault($dialect, $options, $canonical, $target);
        }

        $value = $options[$canonical];
        unset($options[$canonical]);

        // A provider with nothing to map the option onto, but where dropping it is harmless: the
        // answer may be longer or less deterministic than asked for, never wrong. Refusing instead
        // would break every consumer that sets the option without knowing which backend an
        // administrator configured, including this module's own Test Connection.
        if ($target === null && $this->ignores($dialect, $canonical)) {
            return $options;
        }

        if ($target === null) {
            throw new AiRequestNotSentException(__(
                'The "%1" option is not supported by AI service "%2". '
                . 'Remove it, or send the provider\'s own option instead.',
                $canonical,
                $serviceCode
            ));
        }

        // The caller naming the provider's own option alongside the neutral one addressed this
        // provider deliberately and more precisely, so it wins, exactly as it does against a
        // required default below. Overruling it would silently send a cap they did not write.
        if (array_key_exists($target, $options)) {
            return $options;
        }

        $options[$target] = $this->castValue($dialect, $canonical, $value);

        return $options;
    }

    /**
     * Apply a provider-required value the caller left out, e.g. Anthropic's mandatory max_tokens.
     *
     * Skipped when the caller already set the provider's own option name: they addressed this
     * provider directly, and overruling that with a default would be the surprise this class is
     * meant to prevent.
     *
     * @param Dialect $dialect
     * @param RequestOptions $options
     * @param string $canonical
     * @param string|null $target
     * @return RequestOptions
     */
    private function applyDefault(array $dialect, array $options, string $canonical, ?string $target): array
    {
        $default = $dialect[self::KEY_DEFAULTS][$canonical] ?? null;
        if ($default === null || $target === null || array_key_exists($target, $options)) {
            return $options;
        }

        $options[$target] = $this->castValue($dialect, $canonical, $default);

        return $options;
    }

    /**
     * Rewrite a value-mapped option into the request fragment its canonical value stands for.
     *
     * Unlike applyOption(), the target is not one renamed key: it is whatever set of keys the
     * dialect's `values` table says this value becomes, merged into the request. That is what
     * lets Anthropic's `reasoning_effort: low` set both `thinking` and `output_config` from one
     * canonical option.
     *
     * A value that is not one of the option's canonical values is the provider's own and is left
     * exactly as the caller wrote it, the same escape hatch every unmapped option already has.
     *
     * @param string $serviceCode
     * @param Dialect $dialect
     * @param RequestOptions $options
     * @param string $canonical
     * @return RequestOptions
     * @throws AiRequestNotSentException When a canonical value has no translation and is not the neutral default
     */
    private function applyValueOption(string $serviceCode, array $dialect, array $options, string $canonical): array
    {
        if (!array_key_exists($canonical, $options)) {
            return $options;
        }

        [$lookupKey, $toolName] = $this->resolveValueLookup($options[$canonical]);
        if (!in_array($lookupKey, self::VALUE_MAPPED_OPTIONS[$canonical] ?? [], true)) {
            return $options;
        }

        unset($options[$canonical]);
        $fragment = $dialect[self::KEY_VALUES][$canonical][$lookupKey] ?? null;

        if ($fragment === null) {
            if ($lookupKey === (self::NEUTRAL_DEFAULT_VALUES[$canonical] ?? null)) {
                return $options;
            }

            throw new AiRequestNotSentException(__(
                'The "%1" value of the "%2" option is not supported by AI service "%3". '
                . 'Remove it, or send the provider\'s own option instead.',
                $lookupKey,
                $canonical,
                $serviceCode
            ));
        }

        foreach ($this->substituteToolName($this->castNumericStrings($fragment), $toolName) as $key => $fragmentValue) {
            if (!array_key_exists($key, $options)) {
                $options[$key] = $fragmentValue;
            }
        }

        return $options;
    }

    /**
     * Which entry of a `values` table a caller's raw option value looks up.
     *
     * Also returns the tool name to substitute in when it is the "force this named tool" shape.
     * Anything that is neither a string nor that shape yields an empty key, which no option
     * declares as canonical, so it passes through as provider-native.
     *
     * @param mixed $value
     * @return array{0: string, 1: string|null}
     */
    private function resolveValueLookup(mixed $value): array
    {
        if (is_array($value) && is_string($value[self::VALUE_KEY_TOOL] ?? null)) {
            return [self::VALUE_KEY_TOOL, $value[self::VALUE_KEY_TOOL]];
        }

        return [is_string($value) ? $value : '', null];
    }

    /**
     * Replace TOOL_NAME_PLACEHOLDER wherever it appears inside a `values` fragment.
     *
     * @param array<string,mixed> $fragment
     * @param string|null $toolName
     * @return array<string,mixed>
     */
    private function substituteToolName(array $fragment, ?string $toolName): array
    {
        if ($toolName === null) {
            return $fragment;
        }

        return array_map(fn (mixed $item): mixed => $this->substituteValue($item, $toolName), $fragment);
    }

    /**
     * Replace TOOL_NAME_PLACEHOLDER in one fragment value, at any depth.
     *
     * Recurses because a provider may nest the tool's name arbitrarily deep (Anthropic takes it
     * directly, OpenAI-compatible chat completions one level further inside `function.name`).
     *
     * @param mixed $value
     * @param string $toolName
     * @return mixed
     */
    private function substituteValue(mixed $value, string $toolName): mixed
    {
        if ($value === self::TOOL_NAME_PLACEHOLDER) {
            return $toolName;
        }

        return is_array($value)
            ? array_map(fn (mixed $item): mixed => $this->substituteValue($item, $toolName), $value)
            : $value;
    }

    /**
     * Apply toNumberIfNumeric() across a `values` fragment.
     *
     * `di.xml`'s `number` interpreter yields a string the same way it does for `defaults` (see
     * toNumberIfNumeric()), and a fragment can nest a numeric tier several levels deep.
     *
     * @param array<string,mixed> $fragment
     * @return array<string,mixed>
     */
    private function castNumericStrings(array $fragment): array
    {
        return array_map(fn (mixed $item): mixed => $this->castNumericValue($item), $fragment);
    }

    /**
     * Apply toNumberIfNumeric() to one fragment value, at any depth.
     *
     * @param mixed $value
     * @return mixed
     */
    private function castNumericValue(mixed $value): mixed
    {
        return is_array($value)
            ? array_map(fn (mixed $item): mixed => $this->castNumericValue($item), $value)
            : $this->toNumberIfNumeric($value);
    }

    /**
     * Put a value into the shape the provider expects it in.
     *
     * @param Dialect $dialect
     * @param string $canonical
     * @param mixed $value
     * @return mixed
     */
    private function castValue(array $dialect, string $canonical, mixed $value): mixed
    {
        return $this->wantsList($dialect, $canonical) ? (array) $value : $this->toNumberIfNumeric($value);
    }

    /**
     * Whether this provider drops the option silently instead of refusing it.
     *
     * Both `di.xml` shapes count, for the same reason as {@see wantsList()}.
     *
     * @param Dialect $dialect
     * @param string $canonical
     * @return bool
     */
    private function ignores(array $dialect, string $canonical): bool
    {
        $ignore = $dialect[self::KEY_IGNORE] ?? [];

        return in_array($canonical, $ignore, true) || array_key_exists($canonical, $ignore);
    }

    /**
     * Whether this provider wants the option as an array of strings.
     *
     * Both `di.xml` shapes count. `map` reads its item names, so a `lists` entry written the same
     * way (`<item name="stop">stop</item>`) is the natural thing for a third party to copy, and
     * reading only one of the two would leave the other silently doing nothing.
     *
     * @param Dialect $dialect
     * @param string $canonical
     * @return bool
     */
    private function wantsList(array $dialect, string $canonical): bool
    {
        $lists = $dialect[self::KEY_LISTS] ?? [];

        return in_array($canonical, $lists, true) || array_key_exists($canonical, $lists);
    }

    /**
     * Restore the number a numeric string was meant to be.
     *
     * Values arrive as strings from two places that cannot help it: `di.xml` (Magento's `number`
     * argument interpreter hands back the raw XML node value, so a `defaults` entry of 4096 is the
     * string "4096") and `core_config_data`, which a consumer reading its own configuration passes
     * straight through. Providers type these fields: Anthropic rejects `"max_tokens": "4096"` as
     * not-an-integer, which would defeat the default that exists precisely to satisfy it.
     *
     * @param mixed $value
     * @return mixed
     */
    private function toNumberIfNumeric(mixed $value): mixed
    {
        return is_string($value) && is_numeric($value) ? $value + 0 : $value;
    }
}
