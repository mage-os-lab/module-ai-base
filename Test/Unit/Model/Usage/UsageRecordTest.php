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

    public function test_it_exposes_the_reasoning_token_count_as_a_nullable_integer(): void
    {
        $withCount = new UsageRecord(
            serviceId: '_row_a',
            serviceCode: 'anthropic',
            model: 'claude-opus-4',
            storeId: 1,
            reasoningTokens: 18,
        );
        $withoutCount = new UsageRecord(
            serviceId: '_row_a',
            serviceCode: 'anthropic',
            model: 'claude-opus-4',
            storeId: 1,
        );

        self::assertSame(18, $withCount->getReasoningTokens());
        self::assertNull($withoutCount->getReasoningTokens());
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

    public function test_it_keeps_null_token_counts_when_the_provider_reported_none(): void
    {
        $record = new UsageRecord(
            serviceId: '_row_a',
            serviceCode: 'anthropic',
            model: 'claude-opus-4',
            storeId: 1,
            inputTokens: null,
            outputTokens: null,
            totalTokens: null,
        );

        self::assertNull($record->getInputTokens());
        self::assertNull($record->getOutputTokens());
        self::assertNull($record->getTotalTokens());
    }

    public function test_it_exposes_cache_read_and_write_tokens(): void
    {
        $withCounts = new UsageRecord(
            serviceId: '_row_a',
            serviceCode: 'anthropic',
            model: 'claude-opus-4',
            storeId: 1,
            cacheReadTokens: 30,
            cacheWriteTokens: 12,
        );
        $withoutCounts = new UsageRecord(
            serviceId: '_row_a',
            serviceCode: 'anthropic',
            model: 'claude-opus-4',
            storeId: 1,
        );

        self::assertSame(30, $withCounts->getCacheReadTokens());
        self::assertSame(12, $withCounts->getCacheWriteTokens());
        self::assertNull($withoutCounts->getCacheReadTokens());
        self::assertNull($withoutCounts->getCacheWriteTokens());
    }

    public function test_it_reports_whether_the_call_failed(): void
    {
        $failed = new UsageRecord(
            serviceId: '_row_a',
            serviceCode: 'anthropic',
            model: 'claude-opus-4',
            storeId: 1,
            failed: true,
        );
        $succeeded = new UsageRecord(
            serviceId: '_row_a',
            serviceCode: 'anthropic',
            model: 'claude-opus-4',
            storeId: 1,
        );

        self::assertTrue($failed->isFailed());
        self::assertFalse($succeeded->isFailed());
    }
}
