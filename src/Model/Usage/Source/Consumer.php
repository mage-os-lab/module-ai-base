<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Usage\Source;

use Magento\Framework\Data\OptionSourceInterface;
use MageOS\AiBase\Api\UsageRecordRepositoryInterface;

/**
 * Option source for the usage listing's consumer filter (task 015).
 *
 * A free-text filter over `consumer` would ask an administrator to spell an opaque module
 * identifier nobody chose for readability, and to guess which of several near-identical values
 * actually appears in the table. This lists exactly the distinct values recorded, through
 * {@see UsageRecordRepositoryInterface::getDistinctConsumers()} rather than a collection of its
 * own, so the option list can never drift from what the grid can actually match.
 */
class Consumer implements OptionSourceInterface
{
    /**
     * @param UsageRecordRepositoryInterface $usageRecordRepository
     */
    public function __construct(
        private readonly UsageRecordRepositoryInterface $usageRecordRepository,
    ) {
    }

    /**
     * @inheritdoc
     *
     * @return array<array{value:string,label:string}>
     */
    public function toOptionArray(): array
    {
        return array_map(
            static fn (string $consumer): array => ['value' => $consumer, 'label' => $consumer],
            $this->usageRecordRepository->getDistinctConsumers(),
        );
    }
}
