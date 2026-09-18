<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Usage;

use MageOS\AiBase\Api\Data\UsageRecordInterface;

/**
 * Immutable value object for one recorded AI call.
 *
 * Deliberately not a {@see \Magento\Framework\Model\AbstractModel}: the resource-model glue that
 * loads and saves a row lives in `Repository` (task 005), which is what keeps this class usable
 * both before a row exists (built by the recording decorator) and after one has been read back.
 */
class UsageRecord implements UsageRecordInterface
{
    /**
     * @param string $serviceId {@see UsageRecordInterface::getServiceId()}
     * @param string $serviceCode {@see UsageRecordInterface::getServiceCode()}
     * @param string $model {@see UsageRecordInterface::getModel()}
     * @param int $storeId {@see UsageRecordInterface::getStoreId()}
     * @param string $consumer {@see UsageRecordInterface::getConsumer()}
     * @param int|null $inputTokens {@see UsageRecordInterface::getInputTokens()}
     * @param int|null $outputTokens {@see UsageRecordInterface::getOutputTokens()}
     * @param int|null $totalTokens {@see UsageRecordInterface::getTotalTokens()}
     * @param int|null $cacheReadTokens {@see UsageRecordInterface::getCacheReadTokens()}
     * @param int|null $reasoningTokens {@see UsageRecordInterface::getReasoningTokens()}
     * @param bool $streamed {@see UsageRecordInterface::isStreamed()}
     * @param int|null $id {@see UsageRecordInterface::getId()}
     * @param string|null $createdAt {@see UsageRecordInterface::getCreatedAt()}
     * @param int|null $cacheWriteTokens {@see UsageRecordInterface::getCacheWriteTokens()}
     * @param bool $failed {@see UsageRecordInterface::isFailed()}
     */
    public function __construct(
        private readonly string $serviceId,
        private readonly string $serviceCode,
        private readonly string $model,
        private readonly int $storeId,
        private readonly string $consumer = self::CONSUMER_UNKNOWN,
        private readonly ?int $inputTokens = 0,
        private readonly ?int $outputTokens = 0,
        private readonly ?int $totalTokens = 0,
        private readonly ?int $cacheReadTokens = null,
        private readonly ?int $reasoningTokens = null,
        private readonly bool $streamed = false,
        private readonly ?int $id = null,
        private readonly ?string $createdAt = null,
        private readonly ?int $cacheWriteTokens = null,
        private readonly bool $failed = false,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @inheritdoc
     */
    public function getServiceId(): string
    {
        return $this->serviceId;
    }

    /**
     * @inheritdoc
     */
    public function getServiceCode(): string
    {
        return $this->serviceCode;
    }

    /**
     * @inheritdoc
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * @inheritdoc
     */
    public function getConsumer(): string
    {
        return $this->consumer;
    }

    /**
     * @inheritdoc
     */
    public function getStoreId(): int
    {
        return $this->storeId;
    }

    /**
     * @inheritdoc
     */
    public function getInputTokens(): ?int
    {
        return $this->inputTokens;
    }

    /**
     * @inheritdoc
     */
    public function getOutputTokens(): ?int
    {
        return $this->outputTokens;
    }

    /**
     * @inheritdoc
     */
    public function getTotalTokens(): ?int
    {
        return $this->totalTokens;
    }

    /**
     * @inheritdoc
     */
    public function getCacheReadTokens(): ?int
    {
        return $this->cacheReadTokens;
    }

    /**
     * @inheritdoc
     */
    public function getCacheWriteTokens(): ?int
    {
        return $this->cacheWriteTokens;
    }

    /**
     * @inheritdoc
     */
    public function getReasoningTokens(): ?int
    {
        return $this->reasoningTokens;
    }

    /**
     * @inheritdoc
     */
    public function isStreamed(): bool
    {
        return $this->streamed;
    }

    /**
     * @inheritdoc
     */
    public function isFailed(): bool
    {
        return $this->failed;
    }

    /**
     * @inheritdoc
     */
    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }
}
