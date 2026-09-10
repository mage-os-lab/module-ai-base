<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Usage\Source;

use Magento\Framework\Data\OptionSourceInterface;
use MageOS\AiBase\Api\AiServiceSelectorInterface;
use MageOS\AiBase\Api\Data\AiServiceInterface;
use MageOS\AiBase\Model\ServiceRegistry;

/**
 * Option source for the usage listing's service filter (task 015).
 *
 * The stored column it filters, `service_id`, is {@see AiServiceInterface::getId()}: an opaque
 * JSON object key that means nothing to an administrator reading the filter list. Each option's
 * label is instead the human provider name {@see ServiceRegistry} has for the row's code, so
 * picking a row is picking "OpenAI" rather than a row key nobody chose for readability.
 *
 * Lists the currently configured rows through {@see AiServiceSelectorInterface::getAll()} rather
 * than a distinct query over the usage table: the row a historical call was served through is
 * still the row an administrator wants to find it by, and this is the same source the admin form
 * itself reads its rows from.
 */
class ServiceRow implements OptionSourceInterface
{
    /**
     * @param AiServiceSelectorInterface $serviceSelector Currently configured service rows
     * @param ServiceRegistry $serviceRegistry Registered backends, for the human-readable label
     */
    public function __construct(
        private readonly AiServiceSelectorInterface $serviceSelector,
        private readonly ServiceRegistry $serviceRegistry,
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
            fn (AiServiceInterface $service): array => [
                'value' => $service->getId(),
                'label' => $this->getLabel($service),
            ],
            $this->serviceSelector->getAll(),
        );
    }

    /**
     * Human provider name for a row's code, falling back to the raw code.
     *
     * Falls back when the provider that registered the code is no longer installed: a row can
     * outlive the module that registered it, and its historical usage rows should still resolve
     * to a readable option.
     *
     * @param AiServiceInterface $service
     * @return string
     */
    private function getLabel(AiServiceInterface $service): string
    {
        return $this->serviceRegistry->get($service->getCode())?->getName() ?? $service->getCode();
    }
}
