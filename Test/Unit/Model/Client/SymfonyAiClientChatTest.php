<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Client;

use Magento\Framework\Exception\LocalizedException;
use MageOS\AiBase\Api\Data\MessageRole;
use MageOS\AiBase\Api\Data\StreamChunkType;
use MageOS\AiBase\Model\Chat\ChatMessage;
use MageOS\AiBase\Model\Chat\ChatRequest;
use MageOS\AiBase\Model\Chat\ToolCall as AiBaseToolCall;
use MageOS\AiBase\Model\Chat\ToolDefinition;
use MageOS\AiBase\Model\Client\SymfonyAiClient;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\Role;
use Symfony\AI\Platform\Metadata\Metadata;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\AI\Platform\TokenUsage\TokenUsage;

/**
 * symfony/ai-platform is a soft dependency of this module, so these run only where it is
 * installed. Skipping beats failing: an install without the bridges is a supported setup.
 */
final class SymfonyAiClientChatTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(TextResult::class)) {
            self::markTestSkipped('symfony/ai-platform is not installed.');
        }
    }

    public function test_sends_the_configured_model_and_the_conversation(): void
    {
        $platform = new FakePlatform(new FakeResult(new TextResult('Hi there')));
        $request = new ChatRequest([
            new ChatMessage(MessageRole::System, 'You are terse.'),
            new ChatMessage(MessageRole::User, 'Hello'),
        ]);

        $this->client($platform)->chat($request);

        self::assertSame('gpt-4o', $platform->model);
        self::assertInstanceOf(MessageBag::class, $platform->messages);

        $roles = array_map(static fn ($m) => $m->getRole(), $platform->messages->getMessages());
        self::assertSame([Role::System, Role::User], $roles);
    }

    public function test_returns_the_assistant_text(): void
    {
        $platform = new FakePlatform(new FakeResult(new TextResult('Hi there')));

        $response = $this->client($platform)->chat($this->helloRequest());

        self::assertSame('Hi there', $response->getText());
        self::assertFalse($response->hasToolCalls());
    }

    /**
     * The first iteration of a tool loop returns tool calls and no text at all. asText() throws on
     * that shape, so a client reaching for it blindly breaks on the most ordinary tool response.
     */
    public function test_returns_tool_calls_when_the_model_asked_for_tools_and_wrote_no_text(): void
    {
        $platform = new FakePlatform(new FakeResult(
            new ToolCallResult([new ToolCall('toolu_01', 'get_orders', ['status' => 'pending'])])
        ));

        $response = $this->client($platform)->chat($this->helloRequest());

        self::assertSame('', $response->getText());
        self::assertTrue($response->hasToolCalls());
        self::assertSame('toolu_01', $response->getToolCalls()[0]->getId());
        self::assertSame('get_orders', $response->getToolCalls()[0]->getName());
        self::assertSame(['status' => 'pending'], $response->getToolCalls()[0]->getArguments());
    }

    public function test_returns_both_text_and_tool_calls_when_the_model_sent_both(): void
    {
        $platform = new FakePlatform(new FakeResult(new MultiPartResult([
            new TextResult('Let me look'),
            new ToolCallResult([new ToolCall('toolu_01', 'get_orders', [])]),
        ])));

        $response = $this->client($platform)->chat($this->helloRequest());

        self::assertSame('Let me look', $response->getText());
        self::assertTrue($response->hasToolCalls());
    }

    public function test_reports_token_usage_from_the_result_metadata(): void
    {
        $metadata = new Metadata();
        $metadata->add('token_usage', new TokenUsage(promptTokens: 120, completionTokens: 45));
        $platform = new FakePlatform(new FakeResult(new TextResult('Hi'), $metadata));

        $usage = $this->client($platform)->chat($this->helloRequest())->getUsage();

        self::assertSame(120, $usage?->getPromptTokens());
        self::assertSame(45, $usage?->getCompletionTokens());
    }

    public function test_reports_no_usage_when_the_provider_sent_none(): void
    {
        $platform = new FakePlatform(new FakeResult(new TextResult('Hi')));

        self::assertNull($this->client($platform)->chat($this->helloRequest())->getUsage());
    }

    public function test_passes_tool_definitions_to_the_provider(): void
    {
        $platform = new FakePlatform(new FakeResult(new TextResult('Hi')));
        $request = new ChatRequest(
            [new ChatMessage(MessageRole::User, 'Hello')],
            [new ToolDefinition('get_orders', 'Lists orders', ['type' => 'object'])],
        );

        $this->client($platform)->chat($request);

        $tools = $platform->options['tools'] ?? [];
        self::assertCount(1, $tools);
        self::assertInstanceOf(Tool::class, $tools[0]);
        self::assertSame('get_orders', $tools[0]->getName());
        self::assertSame('Lists orders', $tools[0]->getDescription());
        self::assertSame(['type' => 'object'], $tools[0]->getParameters());
    }

    public function test_sends_no_tools_key_when_none_were_offered(): void
    {
        $platform = new FakePlatform(new FakeResult(new TextResult('Hi')));

        $this->client($platform)->chat($this->helloRequest());

        self::assertArrayNotHasKey('tools', $platform->options);
    }

    /**
     * A tool result has to go back as the provider's own tool-result turn, paired with the call.
     */
    public function test_maps_a_tool_result_back_to_a_tool_call_message(): void
    {
        $platform = new FakePlatform(new FakeResult(new TextResult('Three orders.')));
        $call = new AiBaseToolCall('toolu_01', 'get_orders', ['status' => 'pending']);
        $request = (new ChatRequest([new ChatMessage(MessageRole::User, 'Which are pending?')]))
            ->withMessage(new ChatMessage(MessageRole::Assistant, '', [$call]))
            ->withToolResult($call, '{"count":3}');

        $this->client($platform)->chat($request);

        $messages = $platform->messages->getMessages();
        self::assertSame(Role::Assistant, $messages[1]->getRole());
        self::assertSame(Role::ToolCall, $messages[2]->getRole());
        self::assertSame('toolu_01', $messages[2]->getToolCall()->getId());
        self::assertSame('get_orders', $messages[2]->getToolCall()->getName());
    }

    public function test_wraps_a_provider_failure_in_a_localized_exception(): void
    {
        $platform = new FakePlatform(null, new \RuntimeException('402 Payment Required'));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('402 Payment Required');

        $this->client($platform)->chat($this->helloRequest());
    }

    public function test_complete_returns_plain_text_for_a_single_prompt(): void
    {
        $platform = new FakePlatform(new FakeResult(new TextResult('A fine description.')));

        self::assertSame('A fine description.', $this->client($platform)->complete('Describe this'));

        $messages = $platform->messages->getMessages();
        self::assertCount(1, $messages);
        self::assertSame(Role::User, $messages[0]->getRole());
    }

    public function test_streaming_asks_the_provider_to_stream(): void
    {
        $platform = new FakePlatform(new FakeResult(null, null, [new TextDelta('Hi')]));

        iterator_to_array($this->client($platform)->streamChat($this->helloRequest()));

        self::assertTrue($platform->options['stream'] ?? false);
    }

    /**
     * The bridge already accumulates tool_use blocks and decodes their JSON, so a completed call
     * arrives whole. Consumers hand-parsing SSE do this themselves and get it subtly wrong.
     */
    public function test_streaming_maps_every_delta_kind_and_ignores_the_rest(): void
    {
        $platform = new FakePlatform(new FakeResult(null, null, [
            new TextDelta('Hel'),
            new TextDelta('lo'),
            new ThinkingStart(),
            new ThinkingDelta('weighing options'),
            new ToolCallComplete([new ToolCall('toolu_01', 'get_orders', ['status' => 'pending'])]),
            new TokenUsage(promptTokens: 120, completionTokens: 45),
        ]));

        $chunks = iterator_to_array($this->client($platform)->streamChat($this->helloRequest()), false);

        $types = array_map(static fn ($c) => $c->getType(), $chunks);
        self::assertSame(
            [
                StreamChunkType::Text,
                StreamChunkType::Text,
                StreamChunkType::Thinking,
                StreamChunkType::ToolCall,
                StreamChunkType::Usage,
            ],
            $types,
            'ThinkingStart carries no payload and must not surface as an empty chunk.'
        );
        self::assertSame('Hel', $chunks[0]->getText());
        self::assertSame('weighing options', $chunks[2]->getText());
        self::assertSame('get_orders', $chunks[3]->getToolCall()?->getName());
        self::assertSame(45, $chunks[4]->getUsage()?->getCompletionTokens());
    }

    public function test_streaming_wraps_a_provider_failure_in_a_localized_exception(): void
    {
        $platform = new FakePlatform(null, new \RuntimeException('connection reset'));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('connection reset');

        iterator_to_array($this->client($platform)->streamChat($this->helloRequest()));
    }

    private function client(FakePlatform $platform): SymfonyAiClient
    {
        return new SymfonyAiClient($platform, 'gpt-4o', 'openai');
    }

    private function helloRequest(): ChatRequest
    {
        return new ChatRequest([new ChatMessage(MessageRole::User, 'Hello')]);
    }
}

/**
 * Stand-in for a symfony/ai Platform. Duck-typed, because the client accepts the platform as a
 * plain object so this module never hard-requires the library.
 */
final class FakePlatform
{
    public string $model = '';
    public mixed $messages = null;
    public array $options = [];

    public function __construct(
        private readonly ?FakeResult $result = null,
        private readonly ?\Throwable $failure = null,
    ) {
    }

    public function invoke(string $model, mixed $messages, array $options = []): FakeResult
    {
        $this->model = $model;
        $this->messages = $messages;
        $this->options = $options;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->result;
    }
}

/**
 * Stand-in for a DeferredResult, which is impractical to construct directly.
 */
final class FakeResult
{
    public function __construct(
        private readonly mixed $result = null,
        private readonly ?Metadata $metadata = null,
        private readonly array $deltas = [],
    ) {
    }

    public function getResult(): mixed
    {
        return $this->result;
    }

    public function getMetadata(): Metadata
    {
        return $this->metadata ?? new Metadata();
    }

    public function asStream(): \Generator
    {
        yield from $this->deltas;
    }
}
