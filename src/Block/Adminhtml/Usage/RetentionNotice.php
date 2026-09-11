<?php

declare(strict_types=1);

namespace MageOS\AiBase\Block\Adminhtml\Usage;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use MageOS\AiBase\Model\Usage\UsageConfig;

/**
 * Explains, above the listing, why the grid does not go back further than it does.
 *
 * The grid can only ever show what is still in `mageos_ai_usage_log`, which the cron prunes to the
 * configured retention window; everything older survives as daily aggregates behind the dashboard.
 * Without saying so, an administrator looking for last quarter's calls concludes the data was lost.
 *
 * Its own block rather than a line in the dashboard template because it belongs to the listing
 * below it, not to the figures above it, and rather than the static `Element\Text` it replaces
 * because the windows it quotes are configuration and an install that changed them was being told
 * the default.
 */
class RetentionNotice extends Template
{
    /**
     * @var string
     */
    protected $_template = 'MageOS_AiBase::usage/retention-notice.phtml';

    /**
     * @param Context $context
     * @param UsageConfig $usageConfig Source of both retention windows.
     * @param array<string,mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly UsageConfig $usageConfig,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /**
     * How long individual calls are kept, phrased for a sentence.
     *
     * @return \Magento\Framework\Phrase
     */
    public function getRawRetentionLabel(): \Magento\Framework\Phrase
    {
        return $this->toDayLabel($this->usageConfig->getRetentionDays());
    }

    /**
     * How long the daily aggregates outlive them, phrased for a sentence.
     *
     * @return \Magento\Framework\Phrase
     */
    public function getDailyRetentionLabel(): \Magento\Framework\Phrase
    {
        return $this->toDayLabel($this->usageConfig->getDailyRetentionDays());
    }

    /**
     * A day count as words.
     *
     * Singular and plural are separate translatable strings rather than one with an appended "s":
     * a language that pluralises by anything other than a suffix cannot translate the latter.
     *
     * @param int $days
     * @return \Magento\Framework\Phrase
     */
    private function toDayLabel(int $days): \Magento\Framework\Phrase
    {
        return $days === 1 ? __('%1 day', $days) : __('%1 days', $days);
    }
}
