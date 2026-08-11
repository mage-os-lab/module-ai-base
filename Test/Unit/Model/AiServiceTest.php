<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model;

use MageOS\AiBase\Api\Data\AiServiceInterface;
use MageOS\AiBase\Model\AiService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A configured row's own answers about itself.
 *
 * The two reserved keys are read here rather than by every consumer, so what counts as "named" and
 * what counts as "off" is decided once.
 */
final class AiServiceTest extends TestCase
{
    /**
     * The setting did not always exist. Every row saved before it carries no value, and those rows
     * were working: reading a missing value as "off" would silently stop configured integrations
     * on upgrade, which is the one outcome this default exists to prevent.
     */
    public function test_a_row_saved_before_this_setting_existed_is_enabled(): void
    {
        $service = new AiService('_row_a', 'openai', ['api_key' => 'k', 'model' => 'gpt-4o']);

        self::assertTrue($service->isEnabled());
    }

    /**
     * @param mixed $stored
     */
    #[DataProvider('disablingValueProvider')]
    public function test_an_explicit_negative_disables_the_row(mixed $stored): void
    {
        $service = new AiService('_row_a', 'openai', [AiServiceInterface::CONFIGURATION_ENABLED => $stored]);

        self::assertFalse($service->isEnabled());
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function disablingValueProvider(): array
    {
        return [
            'the string the form posts' => ['0'],
            'an integer zero' => [0],
            'a boolean false' => [false],
            'the word false' => ['false'],
        ];
    }

    /**
     * @param mixed $stored
     */
    #[DataProvider('enablingValueProvider')]
    public function test_anything_else_leaves_the_row_working(mixed $stored): void
    {
        $service = new AiService('_row_a', 'openai', [AiServiceInterface::CONFIGURATION_ENABLED => $stored]);

        self::assertTrue($service->isEnabled());
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function enablingValueProvider(): array
    {
        return [
            'the string the form posts' => ['1'],
            'an integer one' => [1],
            'a boolean true' => [true],
            'a hand-edited value nobody planned for' => ['yes'],
        ];
    }

    public function test_a_row_reports_the_name_an_administrator_gave_it(): void
    {
        $service = new AiService('_row_a', 'openai', [AiServiceInterface::CONFIGURATION_LABEL => '  Chat AI  ']);

        self::assertSame('Chat AI', $service->getLabel());
    }

    public function test_an_unnamed_row_reports_no_name_rather_than_the_provider_code(): void
    {
        self::assertNull((new AiService('_row_a', 'openai', []))->getLabel());
        self::assertNull((new AiService('_row_b', 'openai', ['_label' => '   ']))->getLabel());
    }
}
