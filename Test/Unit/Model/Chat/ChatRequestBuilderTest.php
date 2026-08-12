<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Chat;

require_once __DIR__ . '/../../Stubs/ChatFactoryStubs.php';

use MageOS\AiBase\Api\ChatRequestBuilderInterface;
use MageOS\AiBase\Api\Data\ChatMessageInterfaceFactory;
use MageOS\AiBase\Api\Data\ChatRequestInterfaceFactory;
use MageOS\AiBase\Api\Data\MessageRole;
use MageOS\AiBase\Api\Data\ToolDefinitionInterfaceFactory;
use MageOS\AiBase\Model\Chat\ChatMessage;
use MageOS\AiBase\Model\Chat\ChatRequest;
use MageOS\AiBase\Model\Chat\ChatRequestBuilder;
use MageOS\AiBase\Model\Chat\ChatResponse;
use MageOS\AiBase\Model\Chat\ToolCall;
use MageOS\AiBase\Model\Chat\ToolDefinition;
use PHPUnit\Framework\TestCase;

final class ChatRequestBuilderTest extends TestCase
{
    public function test_builds_a_conversation_in_the_order_it_was_described(): void
    {
        $request = $this->builder()
            ->withSystemMessage('You are terse.')
            ->withUserMessage('Hello')
            ->build();

        $roles = array_map(static fn ($m) => $m->getRole(), $request->getMessages());
        self::assertSame([MessageRole::System, MessageRole::User], $roles);
        self::assertSame('Hello', $request->getMessages()[1]->getContent());
    }

    public function test_describes_a_tool_without_naming_a_model_class(): void
    {
        $schema = ['type' => 'object', 'properties' => ['status' => ['type' => 'string']]];

        $request = $this->builder()
            ->withUserMessage('Which orders are pending?')
            ->withTool('get_orders', 'Lists orders by status', $schema)
            ->build();

        self::assertCount(1, $request->getTools());
        self::assertSame('get_orders', $request->getTools()[0]->getName());
        self::assertSame($schema, $request->getTools()[0]->getParameters());
    }

    public function test_a_tool_taking_no_arguments_gets_the_empty_object_schema(): void
    {
        $request = $this->builder()
            ->withUserMessage('Which orders are pending?')
            ->withTool('count_orders', 'Counts every order')
            ->build();

        self::assertSame(
            ['type' => 'object', 'properties' => []],
            $request->getTools()[0]->getParameters()
        );
    }

    /**
     * The turn a tool loop most easily gets wrong: the assistant's own message has to go back in
     * before the results, and the result has to name the call it answers.
     */
    public function test_builds_a_tool_loop_turn_from_the_response_it_answers(): void
    {
        $call = new ToolCall('toolu_01', 'get_orders', ['status' => 'pending']);
        $response = new ChatResponse('Let me look', [$call]);

        $request = $this->builder()
            ->withUserMessage('Which orders are pending?')
            ->withAssistantTurn($response)
            ->withToolResult($call, '{"count":3}')
            ->build();

        $messages = $request->getMessages();
        self::assertSame(MessageRole::Assistant, $messages[1]->getRole());
        self::assertSame([$call], $messages[1]->getToolCalls());
        self::assertSame(MessageRole::Tool, $messages[2]->getRole());
        self::assertSame('toolu_01', $messages[2]->getToolCallId());
        self::assertSame('{"count":3}', $messages[2]->getContent());
    }

    /**
     * A builder holding a common preamble is worth keeping, which only works if branching off it
     * cannot reach back into the one the caller still holds.
     */
    public function test_a_builder_can_be_branched_without_touching_the_one_it_came_from(): void
    {
        $preamble = $this->builder()->withSystemMessage('You are terse.');

        $first = $preamble->withUserMessage('Hello')->build();
        $second = $preamble->withUserMessage('Goodbye')->build();

        self::assertCount(2, $first->getMessages());
        self::assertSame('Hello', $first->getMessages()[1]->getContent());
        self::assertSame('Goodbye', $second->getMessages()[1]->getContent());
    }

    public function test_refuses_to_build_a_request_with_no_messages(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->builder()->build();
    }

    private function builder(): ChatRequestBuilderInterface
    {
        return new ChatRequestBuilder(
            $this->factory(ChatRequestInterfaceFactory::class, ChatRequest::class),
            $this->factory(ChatMessageInterfaceFactory::class, ChatMessage::class),
            $this->factory(ToolDefinitionInterfaceFactory::class, ToolDefinition::class),
        );
    }

    /**
     * Mock the generated factory rather than construct it.
     *
     * Inside a Magento install the real `*InterfaceFactory` is code-generated and takes
     * `(ObjectManagerInterface $objectManager, string $instanceName)`, so `new Factory()` fatals
     * there while working fine against the no-argument stub in Test/Unit/Stubs. A mock never runs
     * the constructor, so this behaves the same either way — which is why the pre-existing
     * FieldDescriptorInterfaceFactory stub is only ever consumed through createMock() too
     * (see Test/Unit/AiServices/ServicesTest.php).
     *
     * @param class-string $factoryClass
     * @param class-string $builds
     * @return object
     */
    private function factory(string $factoryClass, string $builds): object
    {
        $factory = $this->createMock($factoryClass);
        $factory->method('create')->willReturnCallback(
            static fn (array $data = []): object => new $builds(...$data)
        );

        return $factory;
    }
}
