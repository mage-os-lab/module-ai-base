<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Client;

use Magento\Framework\Exception\LocalizedException;

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
 * Only the universal options are translated (see CANONICAL_OPTIONS). Everything else passes through
 * verbatim, which keeps provider-specific features (Anthropic's `thinking`, Ollama's `keep_alive`,
 * structured output) reachable for a consumer that has deliberately picked its backend.
 *
 * Dialects are wired in `di.xml` and the bridge registry says which dialect a service code speaks,
 * so a third party registering a provider declares it in the same entry as its bridge.
 */
class OptionNormalizer
{
    /**
     * Provider-neutral option names this class translates.
     *
     * Deliberately small: these four are the ones every provider models, so they are the ones a
     * consumer can set without knowing which backend an administrator configured.
     */
    private const CANONICAL_OPTIONS = ['max_tokens', 'temperature', 'top_p', 'stop'];

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
     * @param BridgeRegistry $bridgeRegistry Says which dialect each service code speaks
     * @param array $dialects Dialect name => ['map' => [], 'lists' => [], 'defaults' => []]
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
     * @param array $options
     * @return array
     * @throws LocalizedException When an option has no equivalent at the target provider
     */
    public function normalize(string $serviceCode, array $options): array
    {
        $dialect = $this->dialects[$this->bridgeRegistry->getDialect($serviceCode) ?? ''] ?? null;
        if (!is_array($dialect)) {
            return $options;
        }

        foreach (self::CANONICAL_OPTIONS as $canonical) {
            $options = $this->applyOption($serviceCode, $dialect, $options, $canonical);
        }

        return $options;
    }

    /**
     * Move one canonical option onto its provider name, or apply the provider's required default.
     *
     * @param string $serviceCode
     * @param array $dialect
     * @param array $options
     * @param string $canonical
     * @return array
     * @throws LocalizedException
     */
    private function applyOption(string $serviceCode, array $dialect, array $options, string $canonical): array
    {
        $target = $dialect[self::KEY_MAP][$canonical] ?? null;

        if (!array_key_exists($canonical, $options)) {
            return $this->applyDefault($dialect, $options, $canonical, $target);
        }

        $value = $options[$canonical];
        unset($options[$canonical]);

        if ($target === null) {
            throw new LocalizedException(__(
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
     * @param array $dialect
     * @param array $options
     * @param string $canonical
     * @param string|null $target
     * @return array
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
     * Put a value into the shape the provider expects it in.
     *
     * @param array $dialect
     * @param string $canonical
     * @param mixed $value
     * @return mixed
     */
    private function castValue(array $dialect, string $canonical, mixed $value): mixed
    {
        return $this->wantsList($dialect, $canonical) ? (array) $value : $this->toNumberIfNumeric($value);
    }

    /**
     * Whether this provider wants the option as an array of strings.
     *
     * Both `di.xml` shapes count. `map` reads its item names, so a `lists` entry written the same
     * way (`<item name="stop">stop</item>`) is the natural thing for a third party to copy, and
     * reading only one of the two would leave the other silently doing nothing.
     *
     * @param array $dialect
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
