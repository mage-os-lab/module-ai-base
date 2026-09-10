<?php

declare(strict_types=1);

namespace MageOS\AiBase\Cron;

use MageOS\AiBase\Model\Usage\UsageMaintenance;
use Psr\Log\LoggerInterface;

/**
 * Scheduled entry point for {@see UsageMaintenance}, wired up by `etc/crontab.xml` on the
 * schedule at `mageos_ai/usage/cron_expr`.
 *
 * Deliberately thin: every decision about what a run actually does — the roll-up/prune order, the
 * retention windows, the local-day bucketing — lives in {@see UsageMaintenance}, which is
 * unit-testable without a scheduler. This class only starts a run and turns what it returns into
 * a log line an administrator chasing table growth can read.
 */
class RollUpUsage
{
    /**
     * @param UsageMaintenance $usageMaintenance Does the work and reports what it did
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly UsageMaintenance $usageMaintenance,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Invoked by Magento_Cron on the configured schedule.
     *
     * A maintenance failure is logged with context and rethrown rather than swallowed: unlike the
     * recording path there is no live admin request behind this call to protect, so Magento should
     * mark the scheduled run errored.
     *
     * @return void
     */
    public function execute(): void
    {
        try {
            $result = $this->usageMaintenance->run();
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf('AI usage roll-up failed: %s', $e->getMessage()),
                ['exception' => $e],
            );
            throw $e;
        }

        $this->logger->info(sprintf(
            'AI usage roll-up: aggregated %d row(s), deleted %d raw row(s), pruned %d daily row(s).',
            $result->getAggregatedRows(),
            $result->getDeletedRows(),
            $result->getPrunedDailyRows(),
        ));
    }
}
