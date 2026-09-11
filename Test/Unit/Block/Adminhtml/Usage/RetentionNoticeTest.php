<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Block\Adminhtml\Usage;

use Magento\Framework\App\Config\ScopeConfigInterface;
use MageOS\AiBase\Block\Adminhtml\Usage\RetentionNotice;
use MageOS\AiBase\Model\Usage\UsageConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MageOS\AiBase\Block\Adminhtml\Usage\RetentionNotice
 *
 * `Magento\Backend\Block\Template::__construct()` resolves collaborators through
 * `ObjectManager::getInstance()`, which is unavailable here, so the block is built through
 * reflection and given only the property the methods under test read — the same approach
 * {@see DashboardTest} documents.
 */
final class RetentionNoticeTest extends TestCase
{
    public function test_it_states_the_configured_raw_retention_rather_than_the_default(): void
    {
        $block = $this->blockWithRetention(90, 730);

        self::assertSame('90 days', (string) $block->getRawRetentionLabel());
    }

    public function test_it_states_the_configured_daily_retention(): void
    {
        $block = $this->blockWithRetention(30, 365);

        self::assertSame('365 days', (string) $block->getDailyRetentionLabel());
    }

    public function test_it_says_day_rather_than_days_for_a_single_day_window(): void
    {
        $block = $this->blockWithRetention(1, 1);

        self::assertSame('1 day', (string) $block->getRawRetentionLabel());
        self::assertSame('1 day', (string) $block->getDailyRetentionLabel());
    }

    /**
     * @param int $rawDays
     * @param int $dailyDays
     * @return RetentionNotice
     */
    private function blockWithRetention(int $rawDays, int $dailyDays): RetentionNotice
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path) => match ($path) {
                'mageos_ai/usage/retention_days' => (string) $rawDays,
                'mageos_ai/usage/daily_retention_days' => (string) $dailyDays,
                default => null,
            }
        );

        $reflection = new \ReflectionClass(RetentionNotice::class);
        $block = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('usageConfig')->setValue($block, new UsageConfig($scopeConfig));

        return $block;
    }
}
