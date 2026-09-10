<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Usage;

use Magento\Framework\App\Config\ScopeConfigInterface;
use MageOS\AiBase\Model\Usage\UsageConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MageOS\AiBase\Model\Usage\UsageConfig
 */
final class UsageConfigTest extends TestCase
{
    private ScopeConfigInterface&MockObject $scopeConfig;
    private UsageConfig $subject;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->subject = new UsageConfig($this->scopeConfig);
    }

    public function test_reports_tracking_as_enabled_when_the_config_flag_is_set(): void
    {
        $this->scopeConfig->method('isSetFlag')
            ->with('mageos_ai/usage/enabled')
            ->willReturn(true);

        self::assertTrue($this->subject->isEnabled());
    }

    public function test_reports_tracking_as_disabled_when_the_config_flag_is_unset(): void
    {
        $this->scopeConfig->method('isSetFlag')
            ->with('mageos_ai/usage/enabled')
            ->willReturn(false);

        self::assertFalse($this->subject->isEnabled());
    }

    public function test_returns_the_configured_raw_retention_in_days(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('mageos_ai/usage/retention_days')
            ->willReturn('45');

        self::assertSame(45, $this->subject->getRetentionDays());
    }

    public function test_returns_the_configured_daily_retention_in_days(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('mageos_ai/usage/daily_retention_days')
            ->willReturn('900');

        self::assertSame(900, $this->subject->getDailyRetentionDays());
    }

    #[DataProvider('nonPositiveRetentionValueProvider')]
    public function test_falls_back_to_the_default_retention_when_the_stored_value_is_not_a_positive_integer(
        string|null $storedValue,
    ): void {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn($storedValue);
        $subject = new UsageConfig($scopeConfig);

        self::assertSame(30, $subject->getRetentionDays());
        self::assertSame(730, $subject->getDailyRetentionDays());
    }

    /**
     * @return array<string,array{0:string|null}>
     */
    public static function nonPositiveRetentionValueProvider(): array
    {
        return [
            'zero' => ['0'],
            'negative' => ['-5'],
            'non-numeric' => ['not-a-number'],
            'unset' => [null],
        ];
    }
}
