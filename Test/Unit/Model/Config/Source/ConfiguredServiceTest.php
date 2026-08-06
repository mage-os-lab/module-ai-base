<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Config\Source;

use MageOS\AiBase\Api\AiServiceSelectorInterface;
use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Model\AiService;
use MageOS\AiBase\Model\Client\BridgeRegistry;
use MageOS\AiBase\Model\Config\Source\ConfiguredService;
use MageOS\AiBase\Model\Config\Source\ConfiguredServiceWithAutomatic;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ConfiguredServiceTest extends TestCase
{
    private AiServiceSelectorInterface&MockObject $serviceSelector;

    protected function setUp(): void
    {
        $this->serviceSelector = $this->createMock(AiServiceSelectorInterface::class);
    }

    public function test_offers_no_options_when_nothing_is_configured(): void
    {
        $this->serviceSelector->method('getAll')->willReturn([]);

        self::assertSame([], $this->subject()->toOptionArray());
    }

    /**
     * The stored value is the row id rather than the service code, because the code cannot tell
     * two rows of the same provider apart.
     */
    public function test_uses_the_row_id_as_the_stored_value(): void
    {
        $this->serviceSelector->method('getAll')->willReturn([
            new AiService('_row_a', 'openai', ['model' => 'gpt-4o']),
            new AiService('_row_b', 'anthropic', ['model' => 'claude-sonnet-4-6']),
        ]);

        self::assertSame(
            ['_row_a', '_row_b'],
            array_column($this->subject()->toOptionArray(), 'value')
        );
    }

    public function test_labels_a_row_with_the_provider_name_and_its_configured_model(): void
    {
        $this->serviceSelector->method('getAll')->willReturn([
            new AiService('_row_a', 'openai', ['api_key' => 'k', 'model' => 'gpt-4o']),
        ]);

        self::assertSame('OpenAI (gpt-4o)', (string) $this->subject()->toOptionArray()[0]['label']);
    }

    public function test_labels_a_row_without_a_model_by_provider_name_alone(): void
    {
        $this->serviceSelector->method('getAll')->willReturn([
            new AiService('_row_a', 'openai', ['api_key' => 'k']),
            new AiService('_row_b', 'openai', ['api_key' => 'k', 'model' => '   ']),
        ]);

        $labels = array_map('strval', array_column($this->subject()->toOptionArray(), 'label'));

        self::assertSame(['OpenAI', 'OpenAI #2'], $labels);
    }

    /**
     * A row can outlive the module that registered its provider. Dropping it would silently
     * change what an administrator already picked, so it stays selectable under its raw code.
     */
    public function test_labels_a_row_of_an_unregistered_provider_with_its_raw_code(): void
    {
        $this->serviceSelector->method('getAll')->willReturn([
            new AiService('_row_a', 'somevendor', ['model' => 'some-model']),
        ]);

        self::assertSame(
            'somevendor (some-model, no client bridge available)',
            (string) $this->subject()->toOptionArray()[0]['label']
        );
    }

    /**
     * Picking a provider whose bridge is missing fails only later, when something tries to call
     * it. Saying so in the option label moves that discovery to the moment of choosing.
     */
    public function test_marks_a_row_whose_bridge_package_is_not_installed(): void
    {
        $this->serviceSelector->method('getAll')->willReturn([
            new AiService('_row_a', 'ollama', ['model' => 'llama3']),
        ]);

        self::assertSame(
            'Ollama (llama3, bridge not installed)',
            (string) $this->subject()->toOptionArray()[0]['label']
        );
    }

    public function test_leaves_a_row_unmarked_when_its_bridge_is_installed(): void
    {
        $this->serviceSelector->method('getAll')->willReturn([
            new AiService('_row_a', 'openai', ['model' => 'gpt-4o']),
        ]);

        self::assertSame('OpenAI (gpt-4o)', (string) $this->subject()->toOptionArray()[0]['label']);
    }

    /**
     * Registering the same backend twice with the same model is legitimate (two accounts, two
     * billing owners), and produces two rows an administrator has to be able to tell apart.
     */
    public function test_numbers_rows_that_would_otherwise_share_a_label(): void
    {
        $this->serviceSelector->method('getAll')->willReturn([
            new AiService('_row_a', 'openai', ['api_key' => 'k1', 'model' => 'gpt-4o']),
            new AiService('_row_b', 'openai', ['api_key' => 'k2', 'model' => 'gpt-4o']),
            new AiService('_row_c', 'openai', ['api_key' => 'k3', 'model' => 'o1-mini']),
        ]);

        $labels = array_map('strval', array_column($this->subject()->toOptionArray(), 'label'));

        self::assertSame(['OpenAI (gpt-4o)', 'OpenAI (gpt-4o) #2', 'OpenAI (o1-mini)'], $labels);
    }

    public function test_ignores_a_non_scalar_model_value(): void
    {
        $this->serviceSelector->method('getAll')->willReturn([
            new AiService('_row_a', 'openai', ['model' => ['gpt-4o']]),
        ]);

        self::assertSame('OpenAI', (string) $this->subject()->toOptionArray()[0]['label']);
    }

    public function test_automatic_variant_prepends_an_empty_valued_option(): void
    {
        $this->serviceSelector->method('getAll')->willReturn([
            new AiService('_row_a', 'openai', ['model' => 'gpt-4o']),
        ]);

        $options = $this->automaticSubject()->toOptionArray();

        self::assertCount(2, $options);
        self::assertSame('', $options[0]['value']);
        self::assertSame('Automatic (first usable service)', (string) $options[0]['label']);
        self::assertSame('_row_a', $options[1]['value']);
    }

    public function test_automatic_variant_still_offers_the_automatic_option_with_nothing_configured(): void
    {
        $this->serviceSelector->method('getAll')->willReturn([]);

        $options = $this->automaticSubject()->toOptionArray();

        self::assertCount(1, $options);
        self::assertSame('', $options[0]['value']);
    }

    private function subject(): ConfiguredService
    {
        return new ConfiguredService($this->serviceSelector, $this->services(), $this->bridges());
    }

    private function automaticSubject(): ConfiguredServiceWithAutomatic
    {
        return new ConfiguredServiceWithAutomatic($this->serviceSelector, $this->services(), $this->bridges());
    }

    /**
     * @return array<string, AiServiceConfigurationInterface>
     */
    private function services(): array
    {
        return [
            'openai' => $this->service('OpenAI'),
            'anthropic' => $this->service('Anthropic'),
            'ollama' => $this->service('Ollama'),
        ];
    }

    private function service(string $name): AiServiceConfigurationInterface
    {
        $service = $this->createMock(AiServiceConfigurationInterface::class);
        $service->method('getName')->willReturn($name);

        return $service;
    }

    private function bridges(): BridgeRegistry
    {
        return new BridgeRegistry([
            'openai' => ['factory' => InstalledBridgeFactory::class, 'package' => 'symfony/ai-open-ai-platform'],
            'anthropic' => ['factory' => InstalledBridgeFactory::class, 'package' => 'symfony/ai-anthropic-platform'],
            'ollama' => ['factory' => 'Absent\\Ollama\\Factory', 'package' => 'symfony/ai-ollama-platform'],
        ]);
    }
}

/**
 * Stand-in for an installed Symfony AI bridge Factory.
 */
final class InstalledBridgeFactory
{
    public static function createPlatform(string $apiKey): object
    {
        return new \stdClass();
    }
}
