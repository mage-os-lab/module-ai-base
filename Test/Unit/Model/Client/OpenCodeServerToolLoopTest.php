<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Client;

use MageOS\AiBase\Api\Data\FinishReason;
use MageOS\AiBase\Api\Data\MessageRole;
use MageOS\AiBase\Model\Chat\ChatMessage;
use MageOS\AiBase\Model\Chat\ChatRequest;
use MageOS\AiBase\Model\Chat\ToolCall;
use MageOS\AiBase\Model\Chat\ToolDefinition;
use MageOS\AiBase\Model\Client\BridgeRegistry;
use MageOS\AiBase\Model\Client\OptionNormalizer;
use MageOS\AiBase\Model\Client\SymfonyAiClient;
use MageOS\AiBase\Model\Client\UsageNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * A tool loop through the real opencode server bridge, not a fake platform.
 *
 * Regression for a user-reported failure: `MagoAssistant_Mago` offers Magento tools on every turn
 * and the bridge refused the request outright, which made the provider unusable for the one consumer
 * this module ships alongside. The refusal conflated two things — the opencode *agent* running tools
 * on its own host, which stays off, and the *model* asking Magento to run the caller's tools, which
 * is the whole point.
 *
 * Deliberately wired end to end (`ChatRequest` → `SymfonyAiClient` → the bridge → a mocked server):
 * the fake platform the rest of this file's sibling tests use cannot catch a bridge that rejects
 * what it was handed.
 */
final class OpenCodeServerToolLoopTest extends TestCase
{
    private const SESSION_ID = 'ses_test0000000000000000000';

    /**
     * A Mago-shaped tool: read-only, one enum argument.
     */
    private ToolDefinition $tool;

    /**
     * @var list<array<string,mixed>>
     */
    private array $requests = [];

    /**
     * @var array<string,mixed>
     */
    private array $answer = [];

    protected function setUp(): void
    {
        if (!class_exists(\MageOS\AiOpenCodeCustomPlatform\Factory::class)) {
            self::markTestSkipped('mage-os/library-ai-opencode-custom-platform is not installed.');
        }

        $this->tool = new ToolDefinition('get_order_count', 'Count orders in a period.', [
            'type' => 'object',
            'properties' => ['period' => ['type' => 'string', 'enum' => ['today', 'week']]],
            'required' => ['period'],
        ]);
        $this->requests = [];
        $this->answer = self::assistantMessage('Hi');
    }

    /**
     * Turn one: tools offered, the model asks for one, the consumer gets a real tool call to run.
     */
    public function test_a_request_with_tools_comes_back_as_a_tool_call_to_execute(): void
    {
        $this->answer = self::assistantMessage(
            '<tool_call>{"name":"get_order_count","arguments":{"period":"today"}}</tool_call>'
        );

        $response = $this->client()->chat(new ChatRequest(
            [new ChatMessage(MessageRole::User, 'How many orders today?')],
            [$this->tool],
        ));

        $calls = $response->getToolCalls();
        self::assertCount(1, $calls);
        self::assertSame('get_order_count', $calls[0]->getName());
        self::assertSame(['period' => 'today'], $calls[0]->getArguments());
        self::assertSame(FinishReason::ToolCall, $response->getFinishReason());

        // The caller's tools travelled in the prompt; the server's own stayed off.
        $prompt = $this->bodyOf(1);
        self::assertStringContainsString('get_order_count', (string) ($prompt['system'] ?? ''));
        self::assertSame(['*' => false], $prompt['tools'] ?? null);
    }

    /**
     * Turn two: the consumer replays its own call plus the result it produced, and gets the answer.
     * This is the half that fails if the bridge cannot render a tool-role message.
     */
    public function test_a_replayed_tool_result_produces_the_final_answer(): void
    {
        $this->answer = self::assistantMessage('You had 42 orders today.');
        $call = new ToolCall('call_1', 'get_order_count', ['period' => 'today']);

        $response = $this->client()->chat(new ChatRequest(
            [
                new ChatMessage(MessageRole::User, 'How many orders today?'),
                new ChatMessage(MessageRole::Assistant, 'Checking.', [$call]),
                new ChatMessage(MessageRole::Tool, '{"count":42}', [], $call),
            ],
            [$this->tool],
        ));

        self::assertSame('You had 42 orders today.', $response->getText());
        self::assertSame([], $response->getToolCalls());

        $prompt = (string) ($this->bodyOf(1)['parts'][0]['text'] ?? '');
        self::assertStringContainsString('{"count":42}', $prompt);
        self::assertStringContainsString('get_order_count', $prompt);
    }

    /**
     * A model that answers in prose despite the tool instructions is a formatting slip, and the
     * consumer has to receive that as a normal answer rather than an error.
     */
    public function test_a_plain_answer_to_a_request_with_tools_is_still_an_answer(): void
    {
        $this->answer = self::assistantMessage('I do not need a tool for that.');

        $response = $this->client()->chat(new ChatRequest(
            [new ChatMessage(MessageRole::User, 'Hello')],
            [$this->tool],
        ));

        self::assertSame('I do not need a tool for that.', $response->getText());
        self::assertSame([], $response->getToolCalls());
    }

    private function client(): SymfonyAiClient
    {
        return new SymfonyAiClient(
            \MageOS\AiOpenCodeCustomPlatform\Factory::createPlatform('', $this->fakeServer()),
            'anthropic/claude-haiku-4-5',
            'opencode-custom',
            '_row_1',
            new OptionNormalizer(
                new BridgeRegistry(['opencode-custom' => ['dialect' => 'opencode_server']]),
                ['opencode_server' => ['map' => [], 'ignore' => ['max_tokens', 'temperature', 'top_p', 'stop']]],
            ),
            new UsageNormalizer(new BridgeRegistry([])),
        );
    }

    private function fakeServer(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
            $json = ['response_headers' => ['content-type' => 'application/json']];

            if ($method === 'POST' && str_ends_with($url, '/session')) {
                return new MockResponse((string) json_encode(['id' => self::SESSION_ID]), $json);
            }
            if ($method === 'POST' && str_ends_with($url, '/message')) {
                return new MockResponse((string) json_encode($this->answer), $json);
            }

            return new MockResponse('true', $json);
        });
    }

    /**
     * @return array<string,mixed>
     */
    private function bodyOf(int $index): array
    {
        $body = json_decode((string) ($this->requests[$index]['options']['body'] ?? ''), true);

        return is_array($body) ? $body : [];
    }

    /**
     * @return array<string,mixed>
     */
    private static function assistantMessage(string $text): array
    {
        return [
            'info' => [
                'role' => 'assistant',
                'finish' => 'stop',
                'tokens' => ['input' => 10, 'output' => 5, 'reasoning' => 0, 'cache' => ['read' => 0, 'write' => 0]],
            ],
            'parts' => [['type' => 'text', 'text' => $text]],
        ];
    }
}
