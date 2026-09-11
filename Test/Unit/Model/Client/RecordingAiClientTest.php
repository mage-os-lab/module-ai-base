<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Client;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreExtensionInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\AiBase\Api\AiClientInterface;
use MageOS\AiBase\Api\Data\ChatRequestInterface;
use MageOS\AiBase\Api\Data\ChatResponseInterface;
use MageOS\AiBase\Api\Data\MessageRole;
use MageOS\AiBase\Api\Data\StreamChunkInterface;
use MageOS\AiBase\Api\Data\StreamChunkType;
use MageOS\AiBase\Api\Data\UsageRecordInterface;
use MageOS\AiBase\Api\PlatformAwareInterface;
use MageOS\AiBase\Api\UsageRecordRepositoryInterface;
use MageOS\AiBase\Model\Chat\ChatMessage;
use MageOS\AiBase\Model\Chat\ChatRequest;
use MageOS\AiBase\Model\Chat\ChatResponse;
use MageOS\AiBase\Model\Chat\StreamChunk;
use MageOS\AiBase\Model\Chat\TokenUsage;
use MageOS\AiBase\Model\Client\RecordingAiClient;
use MageOS\AiBase\Model\Client\RecordingPlatformAwareAiClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * @covers \MageOS\AiBase\Model\Client\RecordingAiClient
 * @covers \MageOS\AiBase\Model\Client\RecordingPlatformAwareAiClient
 *
 * Exercises the decorators against {@see FakeAiClient}, {@see FakeUsageRecordRepository},
 * {@see FakeStoreManager} and {@see FakeLogger}, in-memory stand-ins for the collaborators per this
 * codebase's fakes-over-mocks convention, kept next to the test that uses them.
 */
final class RecordingAiClientTest extends TestCase
{
    private FakeUsageRecordRepository $repository;

    private FakeLogger $logger;

    protected function setUp(): void
    {
        $this->repository = new FakeUsageRecordRepository();
        $this->logger = new FakeLogger();
    }

    public function test_it_returns_the_wrapped_client_response_unchanged_from_chat(): void
    {
        $response = new ChatResponse('Hi there', [], new TokenUsage(10, 5));
        $delegate = new FakeAiClient(chatResponse: $response);

        $result = $this->subject($delegate)->chat($this->request());

        self::assertSame($response, $result);
    }

    public function test_it_records_one_usage_row_for_a_buffered_chat_call(): void
    {
        $delegate = new FakeAiClient(chatResponse: new ChatResponse('Hi', [], new TokenUsage(10, 5)));

        $this->subject($delegate)->chat($this->request());

        self::assertCount(1, $this->repository->getSavedRecords());
    }

    public function test_it_records_the_service_id_and_service_code_from_the_wrapped_client(): void
    {
        $delegate = new FakeAiClient(
            chatResponse: new ChatResponse('Hi', [], new TokenUsage(10, 5)),
            serviceCode: 'openai',
            serviceId: '_row9',
        );

        $this->subject($delegate)->chat($this->request());

        $saved = $this->repository->getSavedRecords()[0];
        self::assertSame('openai', $saved->getServiceCode());
        self::assertSame('_row9', $saved->getServiceId());
    }

    public function test_it_records_the_configured_model_when_the_call_named_none(): void
    {
        $delegate = new FakeAiClient(
            chatResponse: new ChatResponse('Hi', [], new TokenUsage(10, 5)),
            model: 'claude-sonnet',
        );

        $this->subject($delegate)->chat($this->request());

        self::assertSame('claude-sonnet', $this->repository->getSavedRecords()[0]->getModel());
    }

    public function test_it_records_the_model_the_call_overrode_with_the_model_option(): void
    {
        $delegate = new FakeAiClient(
            chatResponse: new ChatResponse('Hi', [], new TokenUsage(10, 5)),
            model: 'claude-sonnet',
        );

        $this->subject($delegate)->chat(
            $this->request(),
            [AiClientInterface::OPTION_MODEL => 'gpt-4o-mini'],
        );

        self::assertSame('gpt-4o-mini', $this->repository->getSavedRecords()[0]->getModel());
    }

    public function test_it_records_the_consumer_named_on_the_individual_call_over_the_client_level_one(): void
    {
        $delegate = new FakeAiClient(
            chatResponse: new ChatResponse('Hi', [], new TokenUsage(10, 5)),
            consumer: 'client_level',
        );

        $this->subject($delegate)->chat(
            $this->request(),
            [AiClientInterface::OPTION_CONSUMER => 'per_call'],
        );

        self::assertSame('per_call', $this->repository->getSavedRecords()[0]->getConsumer());
    }

    public function test_it_records_the_unknown_consumer_when_neither_the_call_nor_the_client_named_one(): void
    {
        $delegate = new FakeAiClient(
            chatResponse: new ChatResponse('Hi', [], new TokenUsage(10, 5)),
            consumer: UsageRecordInterface::CONSUMER_UNKNOWN,
        );

        $this->subject($delegate)->chat($this->request());

        self::assertSame(
            UsageRecordInterface::CONSUMER_UNKNOWN,
            $this->repository->getSavedRecords()[0]->getConsumer(),
        );
    }

    public function test_it_does_not_pass_the_consumer_option_on_to_the_wrapped_client(): void
    {
        $delegate = new FakeAiClient(chatResponse: new ChatResponse('Hi', [], new TokenUsage(10, 5)));

        $this->subject($delegate)->chat(
            $this->request(),
            [AiClientInterface::OPTION_CONSUMER => 'chat_widget'],
        );

        self::assertArrayNotHasKey(AiClientInterface::OPTION_CONSUMER, $delegate->chatCalls[0]['options']);
    }

    public function test_it_records_the_admin_store_when_there_is_no_current_store(): void
    {
        $delegate = new FakeAiClient(chatResponse: new ChatResponse('Hi', [], new TokenUsage(10, 5)));

        $this->subject($delegate, new FakeStoreManager(null))->chat($this->request());

        self::assertSame(0, $this->repository->getSavedRecords()[0]->getStoreId());
    }

    public function test_it_records_cached_and_reasoning_token_counts_when_the_response_carries_them(): void
    {
        $usage = new TokenUsage(100, 50, null, 20, 10);
        $delegate = new FakeAiClient(chatResponse: new ChatResponse('Hi', [], $usage));

        $this->subject($delegate)->chat($this->request());

        $saved = $this->repository->getSavedRecords()[0];
        self::assertSame(20, $saved->getCachedTokens());
        self::assertSame(10, $saved->getReasoningTokens());
    }

    public function test_it_records_no_row_when_the_response_carries_no_usage(): void
    {
        $delegate = new FakeAiClient(chatResponse: new ChatResponse('Hi'));

        $this->subject($delegate)->chat($this->request());

        self::assertCount(0, $this->repository->getSavedRecords());
    }

    public function test_it_records_no_row_when_the_wrapped_client_throws(): void
    {
        $delegate = new FakeAiClient();
        $delegate->givenChatThrows(new LocalizedException(__('boom')));

        try {
            $this->subject($delegate)->chat($this->request());
            self::fail('Expected the wrapped exception to propagate.');
        } catch (LocalizedException) {
        }

        self::assertCount(0, $this->repository->getSavedRecords());
    }

    public function test_it_rethrows_the_wrapped_client_exception_unchanged(): void
    {
        $delegate = new FakeAiClient();
        $exception = new LocalizedException(__('boom'));
        $delegate->givenChatThrows($exception);

        try {
            $this->subject($delegate)->chat($this->request());
            self::fail('Expected the wrapped exception to propagate.');
        } catch (LocalizedException $caught) {
            self::assertSame($exception, $caught);
        }
    }

    public function test_it_yields_every_stream_chunk_the_wrapped_client_produced(): void
    {
        $chunks = [
            new StreamChunk(StreamChunkType::Text, 'Hi'),
            new StreamChunk(StreamChunkType::Text, ' there'),
        ];
        $delegate = new FakeAiClient();
        $delegate->givenStreamChunks(...$chunks);

        $seen = [];
        foreach ($this->subject($delegate)->streamChat($this->request()) as $chunk) {
            $seen[] = $chunk;
        }

        self::assertSame($chunks, $seen);
    }

    public function test_it_returns_the_wrapped_turn_from_the_finished_stream_generator(): void
    {
        $turn = new ChatResponse('Hi there');
        $delegate = new FakeAiClient();
        $delegate->givenStreamChunks(new StreamChunk(StreamChunkType::Text, 'Hi there'));
        $delegate->givenStreamReturn($turn);

        $stream = $this->subject($delegate)->streamChat($this->request());
        foreach ($stream as $chunk) {
        }

        self::assertSame($turn, $stream->getReturn());
    }

    public function test_it_records_one_usage_row_marked_as_streamed_when_the_stream_finishes(): void
    {
        $delegate = new FakeAiClient();
        $delegate->givenStreamChunks(
            new StreamChunk(StreamChunkType::Text, 'Hi'),
            new StreamChunk(StreamChunkType::Usage, '', null, new TokenUsage(10, 5)),
        );

        $stream = $this->subject($delegate)->streamChat($this->request());
        foreach ($stream as $chunk) {
        }

        $saved = $this->repository->getSavedRecords();
        self::assertCount(1, $saved);
        self::assertTrue($saved[0]->isStreamed());
    }

    public function test_it_records_the_usage_seen_so_far_when_the_caller_abandons_the_stream_after_a_usage_chunk(): void
    {
        $delegate = new FakeAiClient();
        $delegate->givenStreamChunks(
            new StreamChunk(StreamChunkType::Text, 'Hi'),
            new StreamChunk(StreamChunkType::Usage, '', null, new TokenUsage(10, 5)),
            new StreamChunk(StreamChunkType::Text, 'more'),
        );

        $stream = $this->subject($delegate)->streamChat($this->request());
        foreach ($stream as $chunk) {
            if ($chunk->getType() === StreamChunkType::Usage) {
                break;
            }
        }
        unset($stream);

        $saved = $this->repository->getSavedRecords();
        self::assertCount(1, $saved);
        self::assertSame(10, $saved[0]->getInputTokens());
    }

    public function test_it_records_nothing_when_the_caller_abandons_the_stream_before_any_usage_arrived(): void
    {
        $delegate = new FakeAiClient();
        $delegate->givenStreamChunks(
            new StreamChunk(StreamChunkType::Text, 'Hi'),
            new StreamChunk(StreamChunkType::Usage, '', null, new TokenUsage(10, 5)),
        );

        $stream = $this->subject($delegate)->streamChat($this->request());
        foreach ($stream as $chunk) {
            break;
        }
        unset($stream);

        self::assertCount(0, $this->repository->getSavedRecords());
    }

    public function test_it_records_exactly_one_row_for_a_complete_call(): void
    {
        $delegate = new FakeAiClient(chatResponse: new ChatResponse('Hi', [], new TokenUsage(10, 5)));

        $this->subject($delegate)->complete('Hello');

        self::assertCount(1, $this->repository->getSavedRecords());
    }

    public function test_it_returns_the_wrapped_client_text_unchanged_from_complete(): void
    {
        $delegate = new FakeAiClient(chatResponse: new ChatResponse('Hi there', [], new TokenUsage(10, 5)));

        $result = $this->subject($delegate)->complete('Hello');

        self::assertSame('Hi there', $result);
    }

    public function test_it_returns_the_wrapped_platform_untouched_from_get_platform(): void
    {
        $platform = new \stdClass();
        $delegate = new FakePlatformAwareAiClient($platform);

        $subject = new RecordingPlatformAwareAiClient(
            $delegate,
            $this->repository,
            new FakeStoreManager(1),
            $this->logger,
        );

        self::assertSame($platform, $subject->getPlatform());
    }

    public function test_it_forwards_option_normalisation_to_the_wrapped_client(): void
    {
        $delegate = new FakePlatformAwareAiClient(new \stdClass());

        $subject = new RecordingPlatformAwareAiClient(
            $delegate,
            $this->repository,
            new FakeStoreManager(1),
            $this->logger,
        );

        self::assertSame(['normalized' => true, 'max_tokens' => 400], $subject->normalizeOptions(['max_tokens' => 400]));
    }

    public function test_it_is_not_platform_aware_when_the_wrapped_client_is_not(): void
    {
        $subject = $this->subject(new FakeAiClient());

        self::assertNotInstanceOf(PlatformAwareInterface::class, $subject);
    }

    public function test_it_logs_and_swallows_a_repository_failure_without_breaking_the_call(): void
    {
        $this->repository->givenSaveFails(new \RuntimeException('database is down'));
        $delegate = new FakeAiClient(chatResponse: new ChatResponse('Hi there', [], new TokenUsage(10, 5)));

        $result = $this->subject($delegate)->chat($this->request());

        self::assertSame('Hi there', $result->getText());
        self::assertCount(1, $this->logger->getRecords());
    }

    public function test_it_swallows_a_repository_failure_raised_while_recording_an_abandoned_stream(): void
    {
        $this->repository->givenSaveFails(new \RuntimeException('database is down'));
        $delegate = new FakeAiClient();
        $delegate->givenStreamChunks(
            new StreamChunk(StreamChunkType::Text, 'Hi'),
            new StreamChunk(StreamChunkType::Usage, '', null, new TokenUsage(10, 5)),
        );

        $stream = $this->subject($delegate)->streamChat($this->request());
        foreach ($stream as $chunk) {
            if ($chunk->getType() === StreamChunkType::Usage) {
                break;
            }
        }
        unset($stream);

        self::assertCount(1, $this->logger->getRecords());
    }

    private function subject(FakeAiClient $delegate, ?StoreManagerInterface $storeManager = null): RecordingAiClient
    {
        return new RecordingAiClient(
            $delegate,
            $this->repository,
            $storeManager ?? new FakeStoreManager(1),
            $this->logger,
        );
    }

    private function request(): ChatRequestInterface
    {
        return new ChatRequest([new ChatMessage(MessageRole::User, 'Hello')]);
    }
}

/**
 * In-memory stand-in for {@see AiClientInterface}, kept next to the test that uses it per this
 * codebase's fakes-over-mocks convention.
 */
class FakeAiClient implements AiClientInterface
{
    /**
     * @var list<array{request: ChatRequestInterface, options: array<string,mixed>}>
     */
    public array $chatCalls = [];

    /**
     * @var list<array{request: ChatRequestInterface, options: array<string,mixed>}>
     */
    public array $streamChatCalls = [];

    private ?\Throwable $chatException = null;

    /**
     * @var list<StreamChunkInterface>
     */
    private array $streamChunks = [];

    private ?ChatResponseInterface $streamReturn = null;

    public function __construct(
        private readonly ChatResponseInterface $chatResponse = new ChatResponse(),
        private readonly string $serviceCode = 'anthropic',
        private readonly string $serviceId = '_row1',
        private readonly string $model = 'claude-sonnet',
        private readonly string $consumer = UsageRecordInterface::CONSUMER_UNKNOWN,
    ) {
    }

    public function chat(ChatRequestInterface $request, array $options = []): ChatResponseInterface
    {
        $this->chatCalls[] = ['request' => $request, 'options' => $options];
        if ($this->chatException !== null) {
            throw $this->chatException;
        }

        return $this->chatResponse;
    }

    public function streamChat(ChatRequestInterface $request, array $options = []): \Generator
    {
        $this->streamChatCalls[] = ['request' => $request, 'options' => $options];

        foreach ($this->streamChunks as $chunk) {
            yield $chunk;
        }

        return $this->streamReturn ?? new ChatResponse();
    }

    public function complete(string $prompt, array $options = []): string
    {
        return $this->chatResponse->getText();
    }

    public function getServiceCode(): string
    {
        return $this->serviceCode;
    }

    public function getServiceId(): string
    {
        return $this->serviceId;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getConsumer(): string
    {
        return $this->consumer;
    }

    public function givenChatThrows(\Throwable $exception): void
    {
        $this->chatException = $exception;
    }

    public function givenStreamChunks(StreamChunkInterface ...$chunks): void
    {
        $this->streamChunks = $chunks;
    }

    public function givenStreamReturn(ChatResponseInterface $response): void
    {
        $this->streamReturn = $response;
    }
}

/**
 * {@see FakeAiClient} for a wrapped client that also implements {@see PlatformAwareInterface}.
 */
class FakePlatformAwareAiClient extends FakeAiClient implements PlatformAwareInterface
{
    public function __construct(private readonly object $platform)
    {
        parent::__construct();
    }

    public function getPlatform(): object
    {
        return $this->platform;
    }

    /**
     * @inheritdoc
     */
    public function normalizeOptions(array $options): array
    {
        return ['normalized' => true] + $options;
    }
}

/**
 * In-memory stand-in for {@see UsageRecordRepositoryInterface}, kept next to the test that uses it.
 * Only save() is exercised by {@see RecordingAiClient}; every other method belongs to the admin
 * grid and the stats layer built by later tasks and is never called here.
 */
class FakeUsageRecordRepository implements UsageRecordRepositoryInterface
{
    /**
     * @var list<UsageRecordInterface>
     */
    private array $saved = [];

    private ?\Throwable $saveException = null;

    public function save(UsageRecordInterface $record): void
    {
        if ($this->saveException !== null) {
            throw $this->saveException;
        }

        $this->saved[] = $record;
    }

    public function givenSaveFails(\Throwable $exception): void
    {
        $this->saveException = $exception;
    }

    /**
     * @return list<UsageRecordInterface>
     */
    public function getSavedRecords(): array
    {
        return $this->saved;
    }

    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function deleteOlderThan(\DateTimeInterface $cutoff): int
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function getOldestRecordedAt(): ?\DateTimeImmutable
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function aggregateRange(\DateTimeInterface $from, \DateTimeInterface $to, string $usageDate): array
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function sumRange(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        ?string $consumer = null,
        ?int $storeId = null
    ): array
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function groupRange(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        string $groupBy,
        ?int $storeId = null
    ): array
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function getDistinctConsumers(): array
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function seriesRange(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        string $granularity,
        ?int $storeId = null
    ): array
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }
    /**
     * Not exercised by this test's subject; present so the fake satisfies the interface.
     *
     * @return array<int,array<string,int|string|null>>
     */
    public function seriesRangeGrouped(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        string $granularity,
        string $groupBy,
        ?int $storeId = null
    ): array {
        return [];
    }
}

/**
 * In-memory stand-in for {@see StoreManagerInterface}, kept next to the test that uses it. Only
 * getStore() is exercised by {@see RecordingAiClient}.
 */
class FakeStoreManager implements StoreManagerInterface
{
    public function __construct(private readonly ?int $storeId)
    {
    }

    public function getStore($storeId = null)
    {
        if ($this->storeId === null) {
            throw new NoSuchEntityException();
        }

        return new FakeStore($this->storeId);
    }

    public function setIsSingleStoreModeAllowed($value)
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function hasSingleStore()
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function isSingleStoreMode()
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function getStores($withDefault = false, $codeKey = false)
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function getWebsite($websiteId = null)
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function getWebsites($withDefault = false, $codeKey = false)
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function reinitStores()
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function getDefaultStoreView()
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function getGroup($groupId = null)
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function getGroups($withDefault = false)
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function setCurrentStore($store)
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }
}

/**
 * In-memory stand-in for {@see StoreInterface}, kept next to the test that uses it. Only getId()
 * is exercised by {@see RecordingAiClient}.
 */
class FakeStore implements StoreInterface
{
    public function __construct(private readonly int $id)
    {
    }

    public function getId()
    {
        return $this->id;
    }

    public function setId($id)
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function getCode()
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function setCode($code)
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function getName()
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function setName($name)
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function getWebsiteId()
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function setWebsiteId($websiteId)
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function getStoreGroupId()
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function setIsActive($isActive)
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function getIsActive()
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function setStoreGroupId($storeGroupId)
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function getExtensionAttributes()
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }

    public function setExtensionAttributes(StoreExtensionInterface $extensionAttributes)
    {
        throw new \BadMethodCallException('Not used by RecordingAiClientTest.');
    }
}

/**
 * In-memory stand-in for {@see \Psr\Log\LoggerInterface}, kept next to the test that uses it.
 */
class FakeLogger extends AbstractLogger
{
    /**
     * @var list<array{level: mixed, message: string, context: array<mixed>}>
     */
    private array $records = [];

    /**
     * @inheritdoc
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * @return list<array{level: mixed, message: string, context: array<mixed>}>
     */
    public function getRecords(): array
    {
        return $this->records;
    }
}
