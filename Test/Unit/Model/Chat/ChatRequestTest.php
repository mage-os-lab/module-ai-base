<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Chat;

use MageOS\AiBase\Api\Data\MessageRole;
use MageOS\AiBase\Model\Chat\ChatMessage;
use MageOS\AiBase\Model\Chat\ChatRequest;
use MageOS\AiBase\Model\Chat\ChatResponse;
use MageOS\AiBase\Model\Chat\ToolCall;
use MageOS\AiBase\Model\Chat\ToolDefinition;
use PHPUnit\Framework\TestCase;

final class ChatRequestTest extends TestCase
{
    public function test_carries_messages_and_tool_definitions(): void
    {
        $request = new ChatRequest(
            [new ChatMessage(MessageRole::User, 'Hello')],
            [new ToolDefinition('get_orders', 'Lists orders', ['type' => 'object'])],
        );

        self::assertCount(1, $request->getMessages());
        self::assertSame('Hello', $request->getMessages()[0]->getContent());
        self::assertSame('get_orders', $request->getTools()[0]->getName());
    }

    /**
     * A tool loop appends to the conversation on every iteration. Mutating in place would let one
     * iteration's bookkeeping leak into a request the caller still holds.
     */
    public function test_appending_a_message_leaves_the_original_request_untouched(): void
    {
        $request = new ChatRequest([new ChatMessage(MessageRole::User, 'Hello')]);

        $next = $request->withMessage(new ChatMessage(MessageRole::Assistant, 'Hi'));

        self::assertCount(1, $request->getMessages());
        self::assertCount(2, $next->getMessages());
        self::assertNotSame($request, $next);
    }

    public function test_appending_a_message_keeps_the_tool_definitions(): void
    {
        $request = new ChatRequest(
            [new ChatMessage(MessageRole::User, 'Hello')],
            [new ToolDefinition('get_orders', 'Lists orders', ['type' => 'object'])],
        );

        $next = $request->withMessage(new ChatMessage(MessageRole::Assistant, 'Hi'));

        self::assertCount(1, $next->getTools());
    }

    /**
     * Feeding a tool result back is the one step every tool loop repeats, and getting the pairing
     * wrong (result attached to the wrong call id) is invisible until the provider rejects it.
     */
    public function test_recording_a_tool_result_appends_a_tool_message_bound_to_the_call(): void
    {
        $call = new ToolCall('toolu_01', 'get_orders', ['status' => 'pending']);
        $request = new ChatRequest([new ChatMessage(MessageRole::User, 'Which orders are pending?')]);

        $next = $request->withToolResult($call, '{"count":3}');

        $message = $next->getMessages()[1];
        self::assertSame(MessageRole::Tool, $message->getRole());
        self::assertSame('toolu_01', $message->getToolCallId());
        self::assertSame('{"count":3}', $message->getContent());
    }

    /**
     * Providers want the whole call echoed back with its result, not just the id, so a tool-result
     * message that kept only the id cannot be rendered into a request at all.
     */
    public function test_a_tool_result_keeps_the_call_it_answers(): void
    {
        $call = new ToolCall('toolu_01', 'get_orders', ['status' => 'pending']);

        $message = (new ChatRequest([new ChatMessage(MessageRole::User, 'Hi')]))
            ->withToolResult($call, '{"count":3}')
            ->getMessages()[1];

        self::assertSame($call, $message->getAnsweredToolCall());
        self::assertSame('get_orders', $message->getAnsweredToolCall()?->getName());
    }

    public function test_a_message_that_answers_nothing_has_no_answered_call(): void
    {
        self::assertNull((new ChatMessage(MessageRole::User, 'Hi'))->getAnsweredToolCall());
    }

    public function test_an_assistant_message_carries_the_tool_calls_it_requested(): void
    {
        $call = new ToolCall('toolu_01', 'get_orders', []);
        $message = new ChatMessage(MessageRole::Assistant, 'Let me look', [$call]);

        self::assertSame([$call], $message->getToolCalls());
        self::assertNull($message->getToolCallId());
    }

    public function test_a_request_without_tools_reports_an_empty_tool_list(): void
    {
        self::assertSame([], (new ChatRequest([new ChatMessage(MessageRole::User, 'Hi')]))->getTools());
    }

    public function test_rejects_a_message_list_holding_something_other_than_a_message(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ChatRequest([new ChatMessage(MessageRole::User, 'Hi'), 'not-a-message']);
    }

    public function test_rejects_a_tool_list_holding_something_other_than_a_definition(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ChatRequest([new ChatMessage(MessageRole::User, 'Hi')], ['not-a-tool']);
    }

    public function test_rejects_an_empty_conversation(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ChatRequest([]);
    }

    /**
     * The assistant turn has to go back into the conversation with its tool calls attached, or the
     * provider rejects results answering calls it cannot see. Appending the text and forgetting the
     * calls is the ordinary way to get that wrong.
     */
    public function test_appends_the_models_own_turn_with_its_tool_calls_intact(): void
    {
        $call = new ToolCall('toolu_01', 'get_orders', ['status' => 'pending']);
        $request = new ChatRequest([new ChatMessage(MessageRole::User, 'Which are pending?')]);

        $next = $request->withAssistantTurn(new ChatResponse('Let me look', [$call]));

        self::assertCount(1, $request->getMessages(), 'The original request must be untouched.');
        self::assertCount(2, $next->getMessages());
        self::assertSame(MessageRole::Assistant, $next->getMessages()[1]->getRole());
        self::assertSame('Let me look', $next->getMessages()[1]->getContent());
        self::assertSame([$call], $next->getMessages()[1]->getToolCalls());
    }
}
