<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Config\Backend;

use Magento\Cron\Model\ScheduleFactory;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;

/**
 * Validates `mageos_ai/usage/cron_expr` before it is stored.
 *
 * The field is read by Magento's own scheduler through the `config_path` in `crontab.xml`, and
 * `Magento\Cron\Observer\ProcessCronQueueObserver::_generateJobs()` neither validates it nor
 * catches what it throws: an expression Magento cannot parse takes down the generation of every
 * job in the `default` group, not just this one. An administrator's typo in an AI cleanup field
 * must not stop order emails and index refreshes from being scheduled, so the value is rejected
 * at save time, where the administrator can still see and fix it.
 *
 * Validation goes through `Magento\Cron\Model\Schedule` itself rather than a regular expression
 * of our own, so what saves here is exactly what the scheduler can parse, with no second
 * definition of "valid" to drift out of step with core.
 */
class CronExpression extends Value
{
    /**
     * Sample values the five expression fields are matched against purely to make
     * {@see \Magento\Cron\Model\Schedule::matchCronExpression()} parse each one. Any value in
     * range works: a parse error throws, and whether the sample happens to match is irrelevant.
     */
    private const PARSE_PROBE_VALUES = [0, 0, 1, 1, 0];

    /**
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param ScheduleFactory $scheduleFactory Supplies core's own expression parser
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array<string,mixed> $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly ScheduleFactory $scheduleFactory,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * Rejects an expression the scheduler could not parse.
     *
     * An empty value is allowed and means the job is never scheduled, which is what
     * `ProcessCronQueueObserver::_generateJobs()` already does with one: it skips the job rather
     * than failing. Cleanup then only happens when an administrator runs it, which is a decision
     * they are entitled to make.
     *
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave(): self
    {
        $value = trim(is_scalar($this->getValue()) ? (string) $this->getValue() : '');
        if ($value === '') {
            $this->setValue('');

            return parent::beforeSave();
        }

        $this->assertParsable($value);
        $this->setValue($value);

        return parent::beforeSave();
    }

    /**
     * Runs $expression through core's parser.
     *
     * Turns what the scheduler would have thrown at generation time into a validation error
     * against this field.
     *
     * @param string $expression
     * @return void
     * @throws LocalizedException
     */
    private function assertParsable(string $expression): void
    {
        $schedule = $this->scheduleFactory->create();

        try {
            $schedule->setCronExpr($expression);
            /** @var array<int,string> $fields */
            $fields = $schedule->getCronExprArr();
            foreach (self::PARSE_PROBE_VALUES as $field => $probe) {
                $schedule->matchCronExpression($fields[$field], $probe);
            }
        } catch (\Exception $e) {
            throw new LocalizedException(
                __(
                    'The cleanup cron schedule "%1" is not a cron expression Magento can read'
                    . ' (%2). Use five fields, for example "0 3 * * *" for daily at 03:00.',
                    $expression,
                    $e->getMessage()
                ),
                $e
            );
        }
    }
}
