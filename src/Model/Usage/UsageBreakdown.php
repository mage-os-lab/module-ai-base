<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Usage;

use MageOS\AiBase\Api\Data\UsageBreakdownInterface;
use MageOS\AiBase\Api\Data\UsageTotalsInterface;

/**
 * Immutable value object pairing one group's label with its {@see UsageTotalsInterface}.
 *
 * Built only by {@see UsageStats}; see that class for how the group value and totals are derived.
 */
class UsageBreakdown implements UsageBreakdownInterface
{
    /**
     * @param string $groupValue {@see UsageBreakdownInterface::getGroupValue()}
     * @param UsageTotalsInterface $totals {@see UsageBreakdownInterface::getTotals()}
     */
    public function __construct(
        private readonly string $groupValue,
        private readonly UsageTotalsInterface $totals,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getGroupValue(): string
    {
        return $this->groupValue;
    }

    /**
     * @inheritdoc
     */
    public function getTotals(): UsageTotalsInterface
    {
        return $this->totals;
    }
}
