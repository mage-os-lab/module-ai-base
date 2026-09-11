<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Etc;

use PHPUnit\Framework\TestCase;

/**
 * The declarative job registration in crontab.xml.
 *
 * Parsed as plain XML rather than resolved through Magento_Cron's scheduler, since the tree
 * itself carries no behaviour to exercise, only structure to get right: which group the job is in,
 * which class and method Magento_Cron invokes, and which config path its schedule comes from.
 */
final class CrontabTest extends TestCase
{
    public function test_it_registers_a_daily_job_in_the_default_cron_group(): void
    {
        $job = $this->findRollUpUsageJob();

        self::assertNotNull($job);
        self::assertSame('default', (string) $job->xpath('..')[0]['id']);
        self::assertSame(\MageOS\AiBase\Cron\RollUpUsage::class, (string) $job['instance']);
        self::assertSame('execute', (string) $job['method']);
    }

    public function test_it_reads_its_schedule_from_the_usage_cron_expression_config_path(): void
    {
        $job = $this->findRollUpUsageJob();

        self::assertNotNull($job);
        self::assertSame('mageos_ai/usage/cron_expr', (string) $job->config_path);
    }

    public function test_it_validates_against_the_crontab_schema(): void
    {
        $document = new \DOMDocument();
        $document->load(__DIR__ . '/../../../src/etc/crontab.xml');

        self::assertTrue($document->schemaValidate(
            (new \Magento\Framework\Config\Dom\UrnResolver())->getRealPath('urn:magento:module:Magento_Cron:etc/crontab.xsd')
        ));
    }

    private function findRollUpUsageJob(): ?\SimpleXMLElement
    {
        $crontab = simplexml_load_file(__DIR__ . '/../../../src/etc/crontab.xml');
        $matches = $crontab->xpath('//job[@instance="' . \MageOS\AiBase\Cron\RollUpUsage::class . '"]');

        return $matches[0] ?? null;
    }
}
