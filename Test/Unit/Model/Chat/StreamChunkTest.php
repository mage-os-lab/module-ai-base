<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Chat;

use MageOS\AiBase\Api\Data\StreamChunkType;
use MageOS\AiBase\Model\Chat\StreamChunk;
use MageOS\AiBase\Model\Chat\TokenUsage;
use MageOS\AiBase\Model\Chat\ToolCall;
use PHPUnit\Framework\TestCase;

final class StreamChunkTest extends TestCase
{
    public function test_it_flattens_a_text_chunk_to_its_text(): void
    {
        $chunk = new StreamChunk(StreamChunkType::Text, 'Hello');

        self::assertSame(['text' => 'Hello'], $chunk->getData());
    }

    public function test_it_flattens_a_thinking_chunk_to_its_text(): void
    {
        $chunk = new StreamChunk(StreamChunkType::Thinking, 'weighing options');

        self::assertSame(['text' => 'weighing options'], $chunk->getData());
    }

    /**
     * ThinkingStart signals that a reasoning block opened before the model wrote anything into
     * it, so there is nothing to flatten.
     */
    public function test_it_flattens_a_thinking_start_chunk_to_an_empty_payload(): void
    {
        $chunk = new StreamChunk(StreamChunkType::ThinkingStart);

        self::assertSame([], $chunk->getData());
    }

    public function test_it_flattens_a_tool_call_chunk_to_id_name_and_input(): void
    {
        $chunk = new StreamChunk(
            StreamChunkType::ToolCall,
            '',
            new ToolCall('toolu_01', 'get_orders', ['status' => 'pending']),
        );

        self::assertSame(
            ['id' => 'toolu_01', 'name' => 'get_orders', 'input' => ['status' => 'pending']],
            $chunk->getData(),
        );
    }

    /**
     * A ToolCallStart chunk carries the same shape as a completed ToolCall chunk, arguments
     * empty, so a consumer bridging to a callback stream does not need a second case for it.
     */
    public function test_it_flattens_a_tool_call_start_chunk_to_id_and_name_with_empty_input(): void
    {
        $chunk = new StreamChunk(
            StreamChunkType::ToolCallStart,
            '',
            new ToolCall('toolu_01', 'get_orders', []),
        );

        self::assertSame(
            ['id' => 'toolu_01', 'name' => 'get_orders', 'input' => []],
            $chunk->getData(),
        );
    }

    public function test_it_flattens_a_usage_chunk_to_its_token_counts(): void
    {
        $chunk = new StreamChunk(
            StreamChunkType::Usage,
            '',
            null,
            new TokenUsage(120, 45, 165),
        );

        self::assertSame(
            ['prompt_tokens' => 120, 'completion_tokens' => 45, 'total_tokens' => 165],
            $chunk->getData(),
        );
    }
}
