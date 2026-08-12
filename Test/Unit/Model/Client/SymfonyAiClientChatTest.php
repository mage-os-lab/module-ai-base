<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Client;

use Magento\Framework\Exception\LocalizedException;
use MageOS\AiBase\Api\Data\FinishReason as AiBaseFinishReason;
use MageOS\AiBase\Api\Data\MessageRole;
use MageOS\AiBase\Api\Data\StreamChunkType;
use MageOS\AiBase\Api\PlatformAwareInterface;
use MageOS\AiBase\Model\Chat\ChatMessage;
use MageOS\AiBase\Model\Chat\ChatRequest;
use MageOS\AiBase\Model\Chat\ToolCall as AiBaseToolCall;
use MageOS\AiBase\Model\Chat\ToolDefinition;
use MageOS\AiBase\Model\Client\BridgeRegistry;
use MageOS\AiBase\Model\Client\OptionNormalizer;
use MageOS\AiBase\Model\Client\SymfonyAiClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\FinishReason\FinishReason;
use Symfony\AI\Platform\FinishReason\FinishReasonCase;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\Role;
use Symfony\AI\Platform\Metadata\Metadata;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Test\InMemoryPlatform;
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

    /**
     * ToolCallComplete signals that *all* of the turn's tool calls are finished and carries them
     * together, so a turn asking for two tools arrives as one delta. Taking only the first would
     * mean the second tool is never run and never answered, while the buffered path returns both.
     */
    public function test_streaming_surfaces_every_tool_call_of_a_parallel_tool_turn(): void
    {
        $platform = new FakePlatform(new FakeResult(null, null, [
            new ToolCallComplete([
                new ToolCall('toolu_01', 'get_orders', ['status' => 'pending']),
                new ToolCall('toolu_02', 'get_customers', ['group' => 'wholesale']),
            ]),
        ]));

        $stream = $this->client($platform)->streamChat($this->helloRequest());
        $chunks = iterator_to_array($stream, false);

        self::assertSame(
            [StreamChunkType::ToolCall, StreamChunkType::ToolCall],
            array_map(static fn ($c) => $c->getType(), $chunks),
            'One chunk per call, so getToolCall() keeps meaning exactly one call.'
        );
        self::assertSame('get_orders', $chunks[0]->getToolCall()?->getName());
        self::assertSame('get_customers', $chunks[1]->getToolCall()?->getName());

        $names = array_map(static fn ($c) => $c->getName(), $stream->getReturn()->getToolCalls());
        self::assertSame(['get_orders', 'get_customers'], $names);
    }

    public function test_streaming_wraps_a_provider_failure_in_a_localized_exception(): void
    {
        $platform = new FakePlatform(null, new \RuntimeException('connection reset'));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('connection reset');

        iterator_to_array($this->client($platform)->streamChat($this->helloRequest()));
    }

    public function test_reports_the_service_and_model_it_was_built_for(): void
    {
        $client = $this->client(new FakePlatform(new FakeResult(new TextResult('Hi'))));

        self::assertSame('openai', $client->getServiceCode());
        self::assertSame('_row_1', $client->getServiceId());
        self::assertSame('gpt-4o', $client->getModel());
    }

    /**
     * The escape hatch: a consumer reaching for symfony/ai-agent or any other part of the platform
     * this module does not mirror takes the instance it already built, credentials resolved and
     * bridge selected, rather than assembling one itself from raw configuration.
     */
    public function test_hands_out_the_platform_it_was_built_with(): void
    {
        $platform = new FakePlatform(new FakeResult(new TextResult('Hi')));
        $client = $this->client($platform);

        self::assertInstanceOf(PlatformAwareInterface::class, $client);
        self::assertSame($platform, $client->getPlatform());
    }

    /**
     * The fake platform above is duck-typed, so it cannot show that what comes back out satisfies
     * Symfony's own contract. A consumer handing this to `new Agent(...)`, whose first parameter
     * is typed `PlatformInterface`, depends on exactly that.
     */
    public function test_the_platform_it_hands_out_satisfies_symfonys_own_contract(): void
    {
        $platform = new InMemoryPlatform('Hi there');
        $client = new SymfonyAiClient($platform, 'gpt-4o', 'openai', '_row_1', $this->optionNormalizer());

        self::assertInstanceOf(PlatformInterface::class, $client->getPlatform());

        $result = $client->getPlatform()->invoke(
            $client->getModel(),
            new MessageBag(Message::ofUser('Hello')),
            $client->normalizeOptions(['max_tokens' => 400]),
        );

        self::assertSame('Hi there', $result->asText());
    }

    /**
     * Calling the platform directly opts out of every translation chat() does, so the one piece
     * worth keeping has to be reachable on its own.
     */
    public function test_offers_the_option_translation_separately_for_direct_platform_calls(): void
    {
        $client = $this->client(new FakePlatform(new FakeResult(new TextResult('Hi'))));

        self::assertSame(
            ['max_output_tokens' => 400],
            $client->normalizeOptions(['max_tokens' => 400])
        );
    }

    public function test_the_option_translation_matches_what_chat_applies_internally(): void
    {
        $platform = new FakePlatform(new FakeResult(new TextResult('Hi')));
        $client = $this->client($platform, 'anthropic');

        $client->chat($this->helloRequest(), ['max_tokens' => 400, 'stop' => 'END']);

        self::assertSame(
            $platform->options,
            $client->normalizeOptions(['max_tokens' => 400, 'stop' => 'END']),
            'A consumer calling the platform directly must be able to reproduce the same payload.'
        );
    }

    /**
     * A truncated answer is indistinguishable from a finished one without this, and every provider
     * words it differently, so the normalized case is what a consumer can branch on.
     */
    public function test_reports_why_the_model_stopped_in_both_normalized_and_raw_form(): void
    {
        $metadata = new Metadata();
        $metadata->add('finish_reason', new FinishReason(FinishReasonCase::LENGTH, 'max_tokens'));
        $platform = new FakePlatform(new FakeResult(new TextResult('Truncat'), $metadata));

        $response = $this->client($platform)->chat($this->helloRequest());

        self::assertSame(AiBaseFinishReason::Length, $response->getFinishReason());
        self::assertSame('max_tokens', $response->getRawFinishReason());
    }

    /**
     * The platform normalizes the reason itself for every bundled bridge, but a third-party bridge
     * may store the provider's bare wording. Without the fallback a consumer branching on
     * FinishReason::Length treats a truncated answer as a finished one.
     */
    #[DataProvider('bareProviderFinishReasonProvider')]
    public function test_translates_a_bare_provider_stop_reason_a_bridge_did_not_normalize(
        string $raw,
        AiBaseFinishReason $expected,
    ): void {
        $metadata = new Metadata();
        $metadata->add('finish_reason', $raw);
        $platform = new FakePlatform(new FakeResult(new TextResult('Truncat'), $metadata));

        $response = $this->client($platform)->chat($this->helloRequest());

        self::assertSame($expected, $response->getFinishReason());
        self::assertSame($raw, $response->getRawFinishReason());
    }

    /**
     * @return array<string, array{0: string, 1: AiBaseFinishReason}>
     */
    public static function bareProviderFinishReasonProvider(): array
    {
        return [
            'OpenAI truncation' => ['length', AiBaseFinishReason::Length],
            'Anthropic truncation' => ['max_tokens', AiBaseFinishReason::Length],
            'Google truncation, upper case' => ['MAX_TOKENS', AiBaseFinishReason::Length],
            'Anthropic clean stop' => ['end_turn', AiBaseFinishReason::Stop],
            'OpenAI tool call' => ['tool_calls', AiBaseFinishReason::ToolCall],
            'Anthropic refusal' => ['refusal', AiBaseFinishReason::ContentFilter],
            'a wording nobody maps' => ['something_new', AiBaseFinishReason::Other],
        ];
    }

    public function test_reports_no_finish_reason_when_the_provider_sent_none(): void
    {
        $platform = new FakePlatform(new FakeResult(new TextResult('Hi')));

        $response = $this->client($platform)->chat($this->helloRequest());

        self::assertNull($response->getFinishReason());
        self::assertNull($response->getRawFinishReason());
    }

    /**
     * The output-token limit is spelled differently by every provider, and OpenAI-compatible
     * endpoints reject unknown body fields outright, so passing one option through unchanged is a
     * hard failure or a silently different cap depending on which backend an administrator picked.
     */
    #[DataProvider('outputTokenLimitProvider')]
    public function test_spells_the_output_token_limit_the_way_the_provider_does(
        string $serviceCode,
        string $expectedKey,
    ): void {
        $platform = new FakePlatform(new FakeResult(new TextResult('Hi')));

        $this->client($platform, $serviceCode)->chat($this->helloRequest(), ['max_tokens' => 400]);

        self::assertArrayNotHasKey('max_tokens', array_diff_key($platform->options, ['max_tokens' => null]));
        self::assertSame(400, $platform->options[$expectedKey] ?? null);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function outputTokenLimitProvider(): array
    {
        return [
            'OpenAI Responses API' => ['openai', 'max_output_tokens'],
            'Anthropic Messages API' => ['anthropic', 'max_tokens'],
            'Gemini generationConfig' => ['google', 'maxOutputTokens'],
            'Ollama options' => ['ollama', 'num_predict'],
            'OpenAI-compatible chat completions' => ['deepseek', 'max_tokens'],
        ];
    }

    /**
     * Anthropic rejects a request without max_tokens, so the one call that works everywhere else
     * would fail there alone unless the client supplies the limit the others default themselves.
     */
    public function test_supplies_the_output_token_limit_anthropic_requires(): void
    {
        $platform = new FakePlatform(new FakeResult(new TextResult('Hi')));

        $this->client($platform, 'anthropic')->chat($this->helloRequest());

        self::assertSame(4096, $platform->options['max_tokens'] ?? null);
    }

    public function test_does_not_override_an_option_the_caller_addressed_to_the_provider(): void
    {
        $platform = new FakePlatform(new FakeResult(new TextResult('Hi')));

        $this->client($platform, 'anthropic')->chat($this->helloRequest(), ['max_tokens' => 100]);

        self::assertSame(100, $platform->options['max_tokens'] ?? null);
    }

    public function test_wraps_a_stop_sequence_for_providers_that_want_a_list(): void
    {
        $platform = new FakePlatform(new FakeResult(new TextResult('Hi')));

        $this->client($platform, 'anthropic')->chat($this->helloRequest(), ['stop' => 'END']);

        self::assertSame(['END'], $platform->options['stop_sequences'] ?? null);
        self::assertArrayNotHasKey('stop', $platform->options);
    }

    /**
     * Dropping an option the provider cannot honour would leave a caller believing a limit is in
     * force that never reaches the wire.
     */
    public function test_refuses_an_option_the_provider_has_no_equivalent_for(): void
    {
        $platform = new FakePlatform(new FakeResult(new TextResult('Hi')));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('"stop" option is not supported by AI service "openai"');

        $this->client($platform)->chat($this->helloRequest(), ['stop' => 'END']);
    }

    public function test_passes_provider_specific_options_through_untouched(): void
    {
        $platform = new FakePlatform(new FakeResult(new TextResult('Hi')));

        $this->client($platform, 'anthropic')->chat($this->helloRequest(), ['thinking' => ['type' => 'enabled']]);

        self::assertSame(['type' => 'enabled'], $platform->options['thinking'] ?? null);
    }

    /**
     * A streaming tool loop has to append the assistant turn before the next iteration, and the
     * pieces of that turn arrive spread across the deltas. Rebuilding it per consumer is the
     * bookkeeping this client exists to remove.
     */
    public function test_streaming_returns_the_assembled_turn(): void
    {
        $metadata = new Metadata();
        $metadata->add('finish_reason', new FinishReason(FinishReasonCase::TOOL_CALL, 'tool_use'));
        $metadata->add('token_usage', new TokenUsage(promptTokens: 120, completionTokens: 45));
        $platform = new FakePlatform(new FakeResult(null, $metadata, [
            new TextDelta('Let me '),
            new TextDelta('look'),
            new ThinkingDelta('weighing options'),
            new ToolCallComplete([new ToolCall('toolu_01', 'get_orders', ['status' => 'pending'])]),
        ]));

        $stream = $this->client($platform)->streamChat($this->helloRequest());
        iterator_to_array($stream, false);
        $turn = $stream->getReturn();

        self::assertSame('Let me look', $turn->getText(), 'Thinking is reasoning, not the answer.');
        self::assertTrue($turn->hasToolCalls());
        self::assertSame('get_orders', $turn->getToolCalls()[0]->getName());
        self::assertSame(45, $turn->getUsage()?->getCompletionTokens());
        self::assertSame(AiBaseFinishReason::ToolCall, $turn->getFinishReason());
    }

    /**
     * The platform lifts token counts out of the delta sequence into the result metadata, so a
     * client only watching deltas reports no usage for any streamed call at all.
     */
    public function test_streaming_yields_the_token_counts_the_platform_kept_out_of_the_deltas(): void
    {
        $metadata = new Metadata();
        $metadata->add('token_usage', new TokenUsage(promptTokens: 120, completionTokens: 45));
        $platform = new FakePlatform(new FakeResult(null, $metadata, [new TextDelta('Hi')]));

        $chunks = iterator_to_array($this->client($platform)->streamChat($this->helloRequest()), false);

        $types = array_map(static fn ($c) => $c->getType(), $chunks);
        self::assertSame([StreamChunkType::Text, StreamChunkType::Usage], $types);
        self::assertSame(120, $chunks[1]->getUsage()?->getPromptTokens());
    }

    public function test_streaming_reports_usage_once_when_a_bridge_also_sends_it_as_a_delta(): void
    {
        $metadata = new Metadata();
        $metadata->add('token_usage', new TokenUsage(promptTokens: 120, completionTokens: 45));
        $platform = new FakePlatform(new FakeResult(null, $metadata, [
            new TextDelta('Hi'),
            new TokenUsage(promptTokens: 120, completionTokens: 45),
        ]));

        $chunks = iterator_to_array($this->client($platform)->streamChat($this->helloRequest()), false);

        $usageChunks = array_filter($chunks, static fn ($c) => $c->getType() === StreamChunkType::Usage);
        self::assertCount(1, $usageChunks);
    }

    private function client(FakePlatform $platform, string $serviceCode = 'openai'): SymfonyAiClient
    {
        return new SymfonyAiClient($platform, 'gpt-4o', $serviceCode, '_row_1', $this->optionNormalizer());
    }

    /**
     * A normalizer wired the way di.xml wires it, so the mappings under test are the shipped ones.
     */
    private function optionNormalizer(): OptionNormalizer
    {
        return new OptionNormalizer(
            new BridgeRegistry([
                'openai' => ['dialect' => 'openai_responses'],
                'anthropic' => ['dialect' => 'anthropic_messages'],
                'google' => ['dialect' => 'gemini'],
                'ollama' => ['dialect' => 'ollama'],
                'deepseek' => ['dialect' => 'openai_chat'],
            ]),
            [
                'openai_responses' => ['map' => [
                    'max_tokens' => 'max_output_tokens',
                    'temperature' => 'temperature',
                    'top_p' => 'top_p',
                ]],
                'openai_chat' => ['map' => [
                    'max_tokens' => 'max_tokens',
                    'temperature' => 'temperature',
                    'top_p' => 'top_p',
                    'stop' => 'stop',
                ]],
                'anthropic_messages' => [
                    'map' => [
                        'max_tokens' => 'max_tokens',
                        'temperature' => 'temperature',
                        'top_p' => 'top_p',
                        'stop' => 'stop_sequences',
                    ],
                    'lists' => ['stop'],
                    'defaults' => ['max_tokens' => 4096],
                ],
                'gemini' => [
                    'map' => [
                        'max_tokens' => 'maxOutputTokens',
                        'temperature' => 'temperature',
                        'top_p' => 'topP',
                        'stop' => 'stopSequences',
                    ],
                    'lists' => ['stop'],
                ],
                'ollama' => [
                    'map' => [
                        'max_tokens' => 'num_predict',
                        'temperature' => 'temperature',
                        'top_p' => 'top_p',
                        'stop' => 'stop',
                    ],
                    'lists' => ['stop'],
                ],
            ]
        );
    }

    private function helloRequest(): ChatRequest
    {
        return new ChatRequest([new ChatMessage(MessageRole::User, 'Hello')]);
    }

    /**
     * The model is a property of the work, not of the account: the same key serves a chat
     * assistant and a bulk summariser that wants something cheaper. Before this, the second one
     * meant configuring the same credentials a second time.
     */
    public function test_a_caller_can_run_one_call_against_a_different_model(): void
    {
        $platform = new FakePlatform(new FakeResult(new TextResult('Hi')));

        $this->client($platform)->chat($this->helloRequest(), ['model' => 'gpt-4o-mini']);

        self::assertSame('gpt-4o-mini', $platform->model);
    }

    public function test_the_configured_model_is_used_when_the_caller_names_none(): void
    {
        $platform = new FakePlatform(new FakeResult(new TextResult('Hi')));

        $client = $this->client($platform);
        $client->chat($this->helloRequest());

        self::assertSame($client->getModel(), $platform->model);
    }

    /**
     * The platform takes the model as its own argument. Left in the options it would also reach
     * the request body, where a provider either rejects the unknown field or, worse, honours it.
     */
    public function test_the_model_option_does_not_also_reach_the_request_body(): void
    {
        $platform = new FakePlatform(new FakeResult(new TextResult('Hi')));

        $this->client($platform)->chat($this->helloRequest(), ['model' => 'gpt-4o-mini']);

        self::assertArrayNotHasKey('model', $platform->options);
    }

    public function test_streaming_honours_the_same_override(): void
    {
        $platform = new FakePlatform(new FakeResult(new TextResult('Hi')));

        $stream = $this->client($platform)->streamChat($this->helloRequest(), ['model' => 'o1-mini']);
        iterator_to_array($stream);

        self::assertSame('o1-mini', $platform->model);
    }

    /**
     * A caller naming a model meant it. Falling back to the configured one would hide the mistake
     * and bill a model they did not ask for.
     */
    public function test_an_unusable_model_override_is_refused_rather_than_ignored(): void
    {
        $platform = new FakePlatform(new FakeResult(new TextResult('Hi')));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('must be a model name');

        $this->client($platform)->chat($this->helloRequest(), ['model' => '   ']);
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
