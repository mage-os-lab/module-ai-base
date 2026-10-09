<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Client;

use MageOS\AiBase\Model\Client\OpperFactory;
use MageOS\AiBase\Model\Client\BridgeRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\Generic\CompletionsModel;
use Symfony\AI\Platform\Bridge\Generic\Factory;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\Result\VectorResult;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Exercise the real optional bridge against mocked HTTP, without calling Opper or storing credentials.
 */
final class OpperFactoryTest extends TestCase
{
    private function requireGenericBridge(): void
    {
        if (!class_exists(Factory::class)) {
            self::markTestSkipped('The optional symfony/ai-generic-platform bridge is not installed.');
        }
    }

    public function test_chat_uses_the_opper_path_and_bearer_key(): void
    {
        $this->requireGenericBridge();
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://api.opper.ai/v3/compat/chat/completions', $url);
            self::assertContains('Authorization: Bearer op-test', $options['headers']);
            $body = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('dynamic/support', $body['model']);
            self::assertSame('Hello', $body['messages'][0]['content']);

            return new MockResponse(json_encode([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Hi'], 'finish_reason' => 'stop']],
            ], JSON_THROW_ON_ERROR), ['response_headers' => ['content-type: application/json']]);
        });
        $platform = OpperFactory::createPlatform('https://api.opper.ai', 'op-test', $httpClient);

        self::assertSame('Hi', $platform->invoke(
            'dynamic/support',
            new MessageBag(Message::ofUser('Hello')),
        )->asText());
    }

    public function test_embeddings_use_the_opper_path(): void
    {
        $this->requireGenericBridge();
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://api.opper.ai/v3/compat/embeddings', $url);
            self::assertContains('Authorization: Bearer op-test', $options['headers']);

            return new MockResponse('{"data":[{"embedding":[0.1,0.2]}]}', [
                'response_headers' => ['content-type: application/json'],
            ]);
        });
        $platform = OpperFactory::createPlatform('https://api.opper.ai', 'op-test', $httpClient);

        self::assertInstanceOf(VectorResult::class, $platform->invoke('openai/text-embedding-3-small', 'Hello')->getResult());
    }

    public function test_an_injected_catalogue_is_preserved(): void
    {
        $this->requireGenericBridge();
        $model = new CompletionsModel('custom-model', Capability::cases());
        $catalog = $this->createMock(ModelCatalogInterface::class);
        $catalog->expects(self::once())->method('getModel')->with('custom-model')->willReturn($model);
        $platform = OpperFactory::createPlatform(
            'https://api.opper.ai',
            'op-test',
            new MockHttpClient(),
            $catalog,
        );

        self::assertSame($model, $platform->getModelCatalog()->getModel('custom-model'));
    }

    public function test_adapter_is_unavailable_without_the_optional_bridge(): void
    {
        // A PHP process without Composer autoload simulates the optional package being absent.
        $source = dirname(__DIR__, 4) . '/src/Model/Client/';
        $code = 'require ' . var_export($source . 'OpperFactory.php', true) . ';'
            . 'require ' . var_export($source . 'BridgeRegistry.php', true) . ';'
            . '$registry = new \\' . BridgeRegistry::class . '(["opper" => ['
            . '"factory" => ' . var_export(OpperFactory::class, true) . ', '
            . '"package" => "symfony/ai-generic-platform"]]);'
            . 'exit($registry->isSupported("opper") && !$registry->isAvailable("opper") ? 0 : 1);';
        exec(escapeshellarg(PHP_BINARY) . ' -n -r ' . escapeshellarg($code), $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
    }
}
