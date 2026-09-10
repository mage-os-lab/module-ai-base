<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Integration\Model\Config;

use Magento\Config\Model\Config as ConfigModel;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Framework\App\Config as AppConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AiBase\Model\Config\Backend\CronExpression;
use PHPUnit\Framework\TestCase;

/**
 * The cleanup schedule field cannot be allowed to store something Magento's scheduler chokes on.
 *
 * `etc/crontab.xml` points this job's `config_path` at the field, and
 * `Magento\Cron\Observer\ProcessCronQueueObserver::_generateJobs()` neither validates the value
 * nor catches what `Schedule::setCronExpr()` throws for it: one unreadable expression stops every
 * job in the `default` group from being scheduled, not just this one. A typo in an AI cleanup
 * field must not take order emails and index refreshes down with it.
 *
 * Saved through the real admin config path rather than by constructing the backend model, because
 * half of what matters here is that the field is *wired* to it. The area is not decoration:
 * `system.xml` is read into the config structure for adminhtml only, and anywhere else the field
 * silently has no backend model at all and every value below would save.
 */
#[AppArea('adminhtml')]
final class CronExpressionValidationTest extends TestCase
{
    private const CONFIG_PATH = 'mageos_ai/usage/cron_expr';

    private ObjectManagerInterface $objectManager;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
    }

    protected function tearDown(): void
    {
        $this->objectManager->get(WriterInterface::class)->delete(self::CONFIG_PATH);
        $this->objectManager->get(AppConfig::class)->clean();
    }

    public function test_it_wires_the_field_to_the_validating_backend_model(): void
    {
        $field = $this->objectManager->get(Structure::class)->getElement(self::CONFIG_PATH);

        self::assertInstanceOf(Field::class, $field);
        self::assertInstanceOf(CronExpression::class, $field->getBackendModel());
    }

    public function test_it_stores_an_expression_the_scheduler_can_read(): void
    {
        $this->saveCronExpression('0 3 * * *');

        self::assertSame('0 3 * * *', $this->storedValue());
    }

    public function test_it_stores_a_step_expression(): void
    {
        $this->saveCronExpression('*/15 * * * *');

        self::assertSame('*/15 * * * *', $this->storedValue());
    }

    public function test_it_refuses_an_expression_with_too_few_fields(): void
    {
        $this->expectException(LocalizedException::class);

        $this->saveCronExpression('0 3 * *');
    }

    public function test_it_refuses_a_shorthand_magento_does_not_understand(): void
    {
        // Plenty of cron implementations take "@daily"; Magento's parser is not one of them.
        $this->expectException(LocalizedException::class);

        $this->saveCronExpression('@daily');
    }

    public function test_it_leaves_an_earlier_valid_schedule_in_place_when_a_typo_is_rejected(): void
    {
        $this->saveCronExpression('0 3 * * *');

        try {
            $this->saveCronExpression('0 3 * *');
        } catch (LocalizedException) {
            // The point of the assertion below.
        }

        self::assertSame('0 3 * * *', $this->storedValue(), 'A rejected save replaced the working schedule.');
    }

    public function test_it_allows_an_empty_schedule_meaning_the_job_is_never_scheduled(): void
    {
        // What `_generateJobs()` already does with an empty expression: skip the job. An
        // administrator who wants cleanup to happen only when they run it is entitled to that.
        $this->saveCronExpression('');

        self::assertSame('', (string) $this->storedValue());
    }

    private function saveCronExpression(string $expression): void
    {
        $config = $this->objectManager->create(ConfigModel::class);
        $config->setSection('mageos_ai');
        $config->setGroups(['usage' => ['fields' => ['cron_expr' => ['value' => $expression]]]]);
        $config->save();

        $this->objectManager->get(AppConfig::class)->clean();
    }

    private function storedValue(): ?string
    {
        $value = $this->objectManager->get(ScopeConfigInterface::class)->getValue(self::CONFIG_PATH);

        return $value === null ? null : (string) $value;
    }
}
