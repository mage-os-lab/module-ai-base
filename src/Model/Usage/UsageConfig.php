<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Usage;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Typed reader for the `mageos_ai/usage` config group.
 *
 * Nothing else in the module is meant to touch `ScopeConfigInterface` or a raw `usage/*` path
 * string directly: the recording decorator asks {@see isEnabled()} before it writes a row, and
 * the cleanup job asks the two retention getters before it deletes anything. Every read resolves
 * at default scope with no scope argument, the same way {@see \MageOS\AiBase\Model\ModelList\Storage}
 * does, because the consumers are cron and CLI, neither of which has a website or store in play.
 */
class UsageConfig
{
    /**
     * Path of the toggle that makes the recording decorator a no-op when unset.
     */
    private const CONFIG_PATH_ENABLED = 'mageos_ai/usage/enabled';

    /**
     * Path of the number of days a raw usage row is kept before the cleanup job deletes it.
     */
    private const CONFIG_PATH_RETENTION_DAYS = 'mageos_ai/usage/retention_days';

    /**
     * Path of the number of days an aggregated daily usage row is kept before the cleanup job
     * deletes it. Held far longer than the raw rows, since the daily aggregate is what powers
     * long-running spend trends after the detail behind it is gone.
     */
    private const CONFIG_PATH_DAILY_RETENTION_DAYS = 'mageos_ai/usage/daily_retention_days';

    /**
     * Fallback for {@see getRetentionDays()} when the stored value is not a positive integer,
     * matching the `config.xml` default. Falling back rather than trusting the raw config value
     * is what stops a cleared or hand-edited field from becoming a retention of zero days, which
     * the cleanup job would read as "delete every row on the next run".
     */
    private const DEFAULT_RETENTION_DAYS = 30;

    /**
     * Fallback for {@see getDailyRetentionDays()} when the stored value is not a positive
     * integer, matching the `config.xml` default.
     */
    private const DEFAULT_DAILY_RETENTION_DAYS = 730;

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    /**
     * Whether the recording decorator should write a usage row for an AI call.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::CONFIG_PATH_ENABLED);
    }

    /**
     * Number of days a raw usage row is kept before the cleanup job deletes it.
     *
     * @return int
     */
    public function getRetentionDays(): int
    {
        return $this->readPositiveDays(self::CONFIG_PATH_RETENTION_DAYS, self::DEFAULT_RETENTION_DAYS);
    }

    /**
     * Number of days an aggregated daily usage row is kept before the cleanup job deletes it.
     *
     * @return int
     */
    public function getDailyRetentionDays(): int
    {
        return $this->readPositiveDays(self::CONFIG_PATH_DAILY_RETENTION_DAYS, self::DEFAULT_DAILY_RETENTION_DAYS);
    }

    /**
     * Reads a day count from config, falling back to $default for anything that is not a
     * positive integer: an empty value, a hand-edited non-numeric string, or a zero/negative
     * number an administrator typed to mean "keep nothing", which the cleanup job would
     * otherwise read as "delete everything".
     *
     * @param string $path
     * @param int $default
     * @return int
     */
    private function readPositiveDays(string $path, int $default): int
    {
        $storedValue = $this->scopeConfig->getValue($path);
        if (!is_numeric($storedValue)) {
            return $default;
        }

        $days = (int) $storedValue;

        return $days > 0 ? $days : $default;
    }
}
