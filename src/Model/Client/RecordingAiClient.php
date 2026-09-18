<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Client;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\AiBase\Api\AiClientInterface;
use MageOS\AiBase\Api\Data\ChatRequestInterface;
use MageOS\AiBase\Api\Data\ChatResponseInterface;
use MageOS\AiBase\Api\Data\MessageRole;
use MageOS\AiBase\Api\Data\TokenUsageInterface;
use MageOS\AiBase\Api\UsageRecordRepositoryInterface;
use MageOS\AiBase\Model\Chat\ChatMessage;
use MageOS\AiBase\Model\Chat\ChatRequest;
use MageOS\AiBase\Model\Usage\UsageRecord;
use Psr\Log\LoggerInterface;

/**
 * Decorates a wrapped {@see AiClientInterface}, writing one usage row for every call it makes:
 * successful, failed, or successful but reporting no usage at all.
 *
 * Recording is a side effect the caller never sees: it never changes the response, and it never
 * turns a call the wrapped client already succeeded (or already failed on its own terms) into
 * something else. A failure while saving a row is logged and swallowed the same way, for the same
 * reason. {@see AiRequestNotSentException} is the one exception this class rethrows without
 * recording anything: it means the call never reached the provider, so there is nothing to bill and
 * nothing worth a row. Task 009 wraps every client the factory builds with this class, or
 * {@see RecordingPlatformAwareAiClient} when the wrapped client also implements
 * {@see \MageOS\AiBase\Api\PlatformAwareInterface}, unless usage tracking is switched off; that is
 * what makes the wrapping itself invisible to every existing consumer of AiClientInterface.
 *
 * complete() is reimplemented rather than delegated: the wrapped client's own complete() calls its
 * own chat() internally, which this class never sees, so delegating it straight through would
 * record nothing for it. Routing it through this class's own chat() instead is what makes every
 * entry point on the interface recorded exactly once.
 */
class RecordingAiClient implements AiClientInterface
{
    /**
     * @param AiClientInterface $delegate The client every call is actually made through
     * @param UsageRecordRepositoryInterface $repository
     * @param StoreManagerInterface $storeManager Names the store a call ran under; a call made
     *        outside any store scope (admin, cron, CLI) is recorded under store id 0
     * @param LoggerInterface $logger A recording failure is logged here rather than thrown, since
     *        a problem writing the log must never turn an AI call that already succeeded into one
     *        the caller sees as failed
     */
    public function __construct(
        private readonly AiClientInterface $delegate,
        private readonly UsageRecordRepositoryInterface $repository,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function chat(ChatRequestInterface $request, array $options = []): ChatResponseInterface
    {
        $model = $this->resolveModel($options);
        $consumer = $this->resolveConsumer($options);

        try {
            $response = $this->delegate->chat($request, $this->withoutConsumerOption($options));
        } catch (AiRequestNotSentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->record($model, $consumer, null, false, true);
            throw $e;
        }

        $this->record($model, $consumer, $response->getUsage(), false, false);

        return $response;
    }

    /**
     * @inheritdoc
     */
    public function streamChat(ChatRequestInterface $request, array $options = []): \Generator
    {
        $model = $this->resolveModel($options);
        $consumer = $this->resolveConsumer($options);

        $usage = null;
        $threw = false;

        try {
            $stream = $this->delegate->streamChat($request, $this->withoutConsumerOption($options));

            foreach ($stream as $chunk) {
                $usage = $chunk->getUsage() ?? $usage;
                yield $chunk;
            }

            if ($stream->valid()) {
                throw new LocalizedException(
                    __('The wrapped AI client stopped streaming before its generator finished.')
                );
            }

            return $stream->getReturn();
        } catch (AiRequestNotSentException $e) {
            $threw = true;
            throw $e;
        } catch (\Throwable $e) {
            $threw = true;
            $this->record($model, $consumer, $usage, true, true);
            throw $e;
        } finally {
            // A caller that breaks out of the stream early never resumes execution past the yield
            // above, so this only runs once PHP destroys the abandoned generator, which is why this
            // branch never double-records a call already handled by the catch blocks above; $usage
            // still holds whatever the last usage chunk reported by then, which is the most this
            // decorator can honestly record for a call nobody let finish.
            if (!$threw) {
                $this->record($model, $consumer, $usage, true, false);
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function complete(string $prompt, array $options = []): string
    {
        return $this->chat(
            new ChatRequest([new ChatMessage(MessageRole::User, $prompt)]),
            $options,
        )->getText();
    }

    /**
     * @inheritdoc
     */
    public function getServiceCode(): string
    {
        return $this->delegate->getServiceCode();
    }

    /**
     * @inheritdoc
     */
    public function getServiceId(): string
    {
        return $this->delegate->getServiceId();
    }

    /**
     * @inheritdoc
     */
    public function getModel(): string
    {
        return $this->delegate->getModel();
    }

    /**
     * @inheritdoc
     */
    public function getConsumer(): string
    {
        return $this->delegate->getConsumer();
    }

    /**
     * The model this call actually ran against: the caller's override, or the client's configured one.
     *
     * {@see AiClientInterface::getModel()} documents that it stays the configured value even when a
     * call passed {@see AiClientInterface::OPTION_MODEL}, so recording that value blindly would
     * mis-attribute spend on exactly the calls that option exists for.
     *
     * @param array<string,mixed> $options
     * @return string
     */
    private function resolveModel(array $options): string
    {
        $requested = $options[AiClientInterface::OPTION_MODEL] ?? null;
        $model = is_string($requested) ? trim($requested) : '';

        return $model !== '' ? $model : $this->delegate->getModel();
    }

    /**
     * The consumer to attribute this call to: the per-call option, or the client-level one.
     *
     * {@see AiClientInterface::getConsumer()} already falls back to
     * {@see \MageOS\AiBase\Api\Data\UsageRecordInterface::CONSUMER_UNKNOWN} when the client was
     * built with none, so nothing here has to repeat that default for the client-level case.
     *
     * @param array<string,mixed> $options
     * @return string
     */
    private function resolveConsumer(array $options): string
    {
        $requested = $options[AiClientInterface::OPTION_CONSUMER] ?? null;
        $consumer = is_string($requested) ? trim($requested) : '';

        return $consumer !== '' ? $consumer : $this->delegate->getConsumer();
    }

    /**
     * Options as the wrapped client should see them: without the consumer option.
     *
     * The consumer is this decorator's own bookkeeping and never a provider request field. A
     * third-party delegate has never heard of {@see AiClientInterface::OPTION_CONSUMER} and would
     * forward it straight into the provider's request body, which OpenAI-compatible endpoints
     * reject with a 400.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function withoutConsumerOption(array $options): array
    {
        unset($options[AiClientInterface::OPTION_CONSUMER]);

        return $options;
    }

    /**
     * Persist one usage row for this call, whatever it reported.
     *
     * A null $usage still writes a row, with every token count null: the provider genuinely gave
     * back nothing to record, and dropping the row would make `calls` undercount it rather than
     * honestly show a call the store cannot see the cost of. A save failure is logged and swallowed
     * rather than thrown: this can run after the wrapped call already succeeded, or after it already
     * failed for its own reason, and a storage problem must never override either outcome for the
     * caller.
     *
     * @param string $model
     * @param string $consumer
     * @param TokenUsageInterface|null $usage
     * @param bool $streamed
     * @param bool $failed
     * @return void
     */
    private function record(
        string $model,
        string $consumer,
        ?TokenUsageInterface $usage,
        bool $streamed,
        bool $failed,
    ): void {
        try {
            $this->repository->save(new UsageRecord(
                serviceId: $this->delegate->getServiceId(),
                serviceCode: $this->delegate->getServiceCode(),
                model: $model,
                storeId: $this->resolveStoreId(),
                consumer: $consumer,
                inputTokens: $usage?->getPromptTokens(),
                outputTokens: $usage?->getCompletionTokens(),
                totalTokens: $usage?->getTotalTokens(),
                cacheReadTokens: $usage?->getCacheReadTokens(),
                reasoningTokens: $usage?->getReasoningTokens(),
                streamed: $streamed,
                cacheWriteTokens: $usage?->getCacheWriteTokens(),
                failed: $failed,
            ));
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf(
                    'Failed to record AI usage for service "%s": %s',
                    $this->delegate->getServiceCode(),
                    $e->getMessage(),
                ),
                ['exception' => $e],
            );
        }
    }

    /**
     * The store this call ran under, or the admin store when none is in scope.
     *
     * Cron and CLI report no current store the same way, which is why store id 0 is what this
     * column already means everywhere else it is read, rather than a value invented for this class.
     *
     * @return int
     */
    private function resolveStoreId(): int
    {
        try {
            return (int) $this->storeManager->getStore()->getId();
        } catch (NoSuchEntityException) {
            return 0;
        }
    }
}
