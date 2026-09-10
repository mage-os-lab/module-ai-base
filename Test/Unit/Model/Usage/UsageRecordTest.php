<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Usage;

use MageOS\AiBase\Api\Data\UsageRecordInterface;
use MageOS\AiBase\Model\Usage\UsageRecord;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MageOS\AiBase\Model\Usage\UsageRecord
 */
final class UsageRecordTest extends TestCase
{
    public function test_it_exposes_the_service_row_id_and_service_code_it_was_recorded_against(): void
    {
        $record = new UsageRecord(
            serviceId: '_row_a',
            serviceCode: 'anthropic',
            model: 'claude-opus-4',
            storeId: 1,
        );

        self::assertSame('_row_a', $record->getServiceId());
        self::assertSame('anthropic', $record->getServiceCode());
    }

    public function test_it_exposes_the_model_the_call_ran_against(): void
    {
        $record = new UsageRecord(
            serviceId: '_row_a',
            serviceCode: 'anthropic',
            model: 'claude-opus-4',
            storeId: 1,
        );

        self::assertSame('claude-opus-4', $record->getModel());
    }

    public function test_it_exposes_the_consumer_that_made_the_call(): void
    {
        $record = new UsageRecord(
            serviceId: '_row_a',
            serviceCode: 'anthropic',
            model: 'claude-opus-4',
            storeId: 1,
            consumer: 'sales_skill',
        );

        self::assertSame('sales_skill', $record->getConsumer());
    }

    public function test_it_reports_the_unknown_consumer_constant_when_no_consumer_was_named(): void
    {
        $record = new UsageRecord(
            serviceId: '_row_a',
            serviceCode: 'anthropic',
            model: 'claude-opus-4',
            storeId: 1,
        );

        self::assertSame(UsageRecordInterface::CONSUMER_UNKNOWN, $record->getConsumer());
    }

    public function test_it_exposes_input_output_and_total_token_counts_as_integers(): void
    {
        $record = new UsageRecord(
            serviceId: '_row_a',
            serviceCode: 'anthropic',
            model: 'claude-opus-4',
            storeId: 1,
            inputTokens: 120,
            outputTokens: 45,
            totalTokens: 165,
        );

        self::assertSame(120, $record->getInputTokens());
        self::assertSame(45, $record->getOutputTokens());
        self::assertSame(165, $record->getTotalTokens());
    }

    public function test_it_exposes_cached_and_reasoning_token_counts_as_nullable_integers(): void
    {
        $withCounts = new UsageRecord(
            serviceId: '_row_a',
            serviceCode: 'anthropic',
            model: 'claude-opus-4',
            storeId: 1,
            cachedTokens: 30,
            reasoningTokens: 18,
        );
        $withoutCounts = new UsageRecord(
            serviceId: '_row_a',
            serviceCode: 'anthropic',
            model: 'claude-opus-4',
            storeId: 1,
        );

        self::assertSame(30, $withCounts->getCachedTokens());
        self::assertSame(18, $withCounts->getReasoningTokens());
        self::assertNull($withoutCounts->getCachedTokens());
        self::assertNull($withoutCounts->getReasoningTokens());
    }

    public function test_it_reports_whether_the_call_was_streamed(): void
    {
        $streamed = new UsageRecord(
            serviceId: '_row_a',
            serviceCode: 'anthropic',
            model: 'claude-opus-4',
            storeId: 1,
            streamed: true,
        );
        $notStreamed = new UsageRecord(
            serviceId: '_row_a',
            serviceCode: 'anthropic',
            model: 'claude-opus-4',
            storeId: 1,
        );

        self::assertTrue($streamed->isStreamed());
        self::assertFalse($notStreamed->isStreamed());
    }

    public function test_it_reports_a_null_id_for_a_record_that_has_not_been_saved(): void
    {
        $record = new UsageRecord(
            serviceId: '_row_a',
            serviceCode: 'anthropic',
            model: 'claude-opus-4',
            storeId: 1,
        );

        self::assertNull($record->getId());
    }

    public function test_it_reports_the_admin_store_as_zero_rather_than_null(): void
    {
        $record = new UsageRecord(
            serviceId: '_row_a',
            serviceCode: 'anthropic',
            model: 'claude-opus-4',
            storeId: 0,
        );

        self::assertSame(0, $record->getStoreId());
    }
}
