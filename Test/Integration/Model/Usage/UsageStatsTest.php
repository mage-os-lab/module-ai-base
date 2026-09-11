<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Integration\Model\Usage;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AiBase\Api\Data\Period;
use MageOS\AiBase\Api\UsageStatsInterface;
use MageOS\AiBase\Model\ResourceModel\Usage\UsageDaily;
use MageOS\AiBase\Model\ResourceModel\Usage\UsageLog;
use PHPUnit\Framework\TestCase;

/**
 * Proves {@see \MageOS\AiBase\Model\Usage\UsageStats}'s union of the raw and daily tables against
 * a real database.
 *
 * The unit suite (`Test/Unit/Model/Usage/UsageStatsTest.php`) proves the merge logic against
 * fakes of both repositories; what only a real database can prove is that a call recorded raw and
 * the same call rolled up into the daily table, with the raw row then pruned exactly the way
 * `UsageMaintenance` (task 011) prunes it, produce the identical total either side of that
 * transition — the requirement this whole task exists for.
 */
final class UsageStatsTest extends TestCase
{
    private const RAW_TABLE = 'mageos_ai_usage_log';
    private const DAILY_TABLE = 'mageos_ai_usage_daily';

    private ObjectManagerInterface $objectManager;
    private UsageLog $rawResource;
    private UsageDaily $dailyResource;
    private ResourceConnection $resourceConnection;
    private UsageStatsInterface $subject;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
        $this->rawResource = $this->objectManager->get(UsageLog::class);
        $this->dailyResource = $this->objectManager->get(UsageDaily::class);
        $this->resourceConnection = $this->objectManager->get(ResourceConnection::class);
        $this->subject = $this->objectManager->get(UsageStatsInterface::class);
        $this->truncateTables();
    }

    protected function tearDown(): void
    {
        $this->truncateTables();
    }

    public function test_it_totals_the_same_whether_a_call_is_still_raw_or_already_rolled_up(): void
    {
        $this->rawResource->insert($this->rawRow(['created_at' => '2026-01-10 12:00:00', 'total_tokens' => 15]));

        $period = $this->period('2026-01-01 00:00:00', '2026-02-01 00:00:00');
        $whileRaw = $this->subject->getTotals($period)->getTotalTokens();

        $this->rollUpDay('2026-01-10');
        $afterRollUp = $this->subject->getTotals($period)->getTotalTokens();

        self::assertSame(15, $whileRaw);
        self::assertSame(15, $afterRollUp);
    }

    public function test_it_unions_a_period_spanning_both_tables_without_double_counting(): void
    {
        $this->rawResource->insert($this->rawRow(['created_at' => '2026-01-05 08:00:00', 'total_tokens' => 10]));
        $this->rawResource->insert($this->rawRow(['created_at' => '2026-01-20 08:00:00', 'total_tokens' => 20]));

        $this->rollUpDay('2026-01-05');

        $totals = $this->subject->getTotals($this->period('2026-01-01 00:00:00', '2026-02-01 00:00:00'));

        self::assertSame(30, $totals->getTotalTokens());
        self::assertSame(2, $totals->getCalls());
    }

    /**
     * Aggregates every raw row on $usageDate into the daily table and prunes it out of the raw
     * table, the same sequence `UsageMaintenance` (task 011) runs per day.
     *
     * @param string $usageDate `Y-m-d`.
     */
    private function rollUpDay(string $usageDate): void
    {
        $dayStart = new \DateTimeImmutable($usageDate . ' 00:00:00', new \DateTimeZone('UTC'));
        $dayEnd = $dayStart->modify('+1 day');

        $rows = $this->rawResource->aggregateRange($dayStart, $dayEnd, $usageDate);
        if ($rows !== []) {
            $this->dailyResource->upsertAggregates($rows);
        }

        $this->rawResource->deleteBatch($dayEnd, 1000);
    }

    private function period(string $start, string $end): Period
    {
        return Period::between(
            new \DateTimeImmutable($start, new \DateTimeZone('UTC')),
            new \DateTimeImmutable($end, new \DateTimeZone('UTC'))
        );
    }

    /**
     * @param array<string,int|string|null> $overrides
     * @return array<string,int|string|null>
     */
    private function rawRow(array $overrides = []): array
    {
        return array_merge(
            [
                'created_at' => '2026-01-15 12:00:00',
                'service_id' => '_row1',
                'service_code' => 'anthropic',
                'model' => 'claude-sonnet',
                'consumer' => 'chat',
                'store_id' => 0,
                'input_tokens' => 10,
                'output_tokens' => 5,
                'total_tokens' => 15,
                'cached_tokens' => null,
                'reasoning_tokens' => null,
                'streamed' => 0,
            ],
            $overrides
        );
    }

    private function truncateTables(): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->delete($this->resourceConnection->getTableName(self::RAW_TABLE));
        $connection->delete($this->resourceConnection->getTableName(self::DAILY_TABLE));
    }
}
