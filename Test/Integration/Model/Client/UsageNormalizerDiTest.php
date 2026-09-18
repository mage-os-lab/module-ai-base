<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Integration\Model\Client;

use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AiBase\Api\Data\MessageRole;
use MageOS\AiBase\Model\Chat\ChatMessage;
use MageOS\AiBase\Model\Chat\ChatRequest;
use MageOS\AiBase\Model\Client\SymfonyAiClient;
use MageOS\AiBase\Model\Client\SymfonyAiClientFactory;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Metadata\Metadata;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\TokenUsage\TokenUsage;

/**
 * {@see \MageOS\AiBase\Model\Client\ClientFactory::buildClient()} hands the generated
 * {@see SymfonyAiClientFactory} only platform, model, serviceCode, serviceId and consumer;
 * {@see \MageOS\AiBase\Model\Client\UsageNormalizer} is never in that array. It still reaches every
 * built client because it is a required, class-typed constructor parameter: Magento's ObjectManager
 * resolves those automatically, the same way it already does OptionNormalizer, but only when the
 * parameter is required. Reading the config back through XPath, or building the class with `new`,
 * would not catch a di.xml change that dropped the wiring; going through the real ObjectManager is
 * what a production request does.
 */
final class UsageNormalizerDiTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(TextResult::class)) {
            self::markTestSkipped('symfony/ai-platform is not installed.');
        }
    }

    public function test_it_injects_the_usage_normalizer_through_di(): void
    {
        $metadata = new Metadata();
        $metadata->add('token_usage', new TokenUsage(
            promptTokens: 100,
            completionTokens: 50,
            cacheCreationTokens: 10,
            cacheReadTokens: 20,
        ));
        $platform = new FakePlatform(new FakeResult(new TextResult('Hi'), $metadata));

        $factory = Bootstrap::getObjectManager()->get(SymfonyAiClientFactory::class);

        /** @var SymfonyAiClient $client */
        $client = $factory->create([
            'platform' => $platform,
            'model' => 'claude-sonnet',
            'serviceCode' => 'anthropic',
            'serviceId' => '_row_1',
        ]);

        $response = $client->chat(new ChatRequest([new ChatMessage(MessageRole::User, 'Hi')]));

        self::assertSame(130, $response->getUsage()?->getPromptTokens());
        self::assertSame(180, $response->getUsage()?->getTotalTokens());
    }
}

/**
 * Stand-in for a symfony/ai Platform. Duck-typed, because the client accepts the platform as a
 * plain object so this module never hard-requires the library.
 */
final class FakePlatform
{
    public function __construct(
        private readonly FakeResult $result,
    ) {
    }

    public function invoke(string $model, mixed $messages, array $options = []): FakeResult
    {
        return $this->result;
    }
}

/**
 * Stand-in for a DeferredResult, which is impractical to construct directly.
 */
final class FakeResult
{
    public function __construct(
        private readonly mixed $result,
        private readonly Metadata $metadata,
    ) {
    }

    public function getResult(): mixed
    {
        return $this->result;
    }

    public function getMetadata(): Metadata
    {
        return $this->metadata;
    }
}
