<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use MageOS\AiBase\Api\AiServiceSelectorInterface;
use MageOS\AiBase\Api\Data\AiServiceInterface;
use MageOS\AiBase\Model\Client\BridgeRegistry;
use MageOS\AiBase\Model\ServiceRegistry;

/**
 * Option source listing the AI services an administrator has configured, for reuse in the
 * system.xml of consumer modules:
 *
 *     <field id="ai_service" type="select" ...>
 *         <source_model>MageOS\AiBase\Model\Config\Source\ConfiguredService</source_model>
 *     </field>
 *
 * The stored value is the row id (AiServiceInterface::getId()), which resolves back through
 * AiServiceSelectorInterface::getById() or AiClientFactoryInterface::createById(). The service
 * code is deliberately not the value: the same backend can be registered more than once, with
 * different credentials or models, and those rows have to stay distinguishable.
 *
 * Every configured row is listed, including ones the bundled client cannot currently use. Modules
 * that call a provider with their own HTTP client do not need a bridge at all, so hiding those
 * rows would hide legitimate choices; instead the label says what is wrong with them.
 */
class ConfiguredService implements OptionSourceInterface
{
    /**
     * Configuration key holding the selected model of a service row.
     */
    private const FIELD_MODEL = 'model';

    /**
     * @param AiServiceSelectorInterface $serviceSelector
     * @param ServiceRegistry $serviceRegistry
     * @param BridgeRegistry $bridgeRegistry
     */
    public function __construct(
        private readonly AiServiceSelectorInterface $serviceSelector,
        private readonly ServiceRegistry $serviceRegistry,
        private readonly BridgeRegistry $bridgeRegistry,
    ) {
    }

    /**
     * @inheritdoc
     *
     * @return array<int,array{value:string,label:string}>
     */
    public function toOptionArray(): array
    {
        return $this->numberDuplicateLabels(array_map(
            fn (AiServiceInterface $service): array => [
                'value' => $service->getId(),
                'label' => $this->getLabel($service),
            ],
            $this->serviceSelector->getAll(),
        ));
    }

    /**
     * Human-readable description of a configured row.
     *
     * Provider name, configured model, and any reason the bundled client could not use the row.
     *
     * @param AiServiceInterface $service
     * @return string
     */
    private function getLabel(AiServiceInterface $service): string
    {
        $details = array_values(array_filter([
            $this->getModel($service),
            $this->getUsabilityNote($service->getCode()),
        ]));

        return $details === []
            ? $this->getServiceName($service->getCode())
            : sprintf('%s (%s)', $this->getServiceName($service->getCode()), implode(', ', $details));
    }

    /**
     * Display name of a backend, falling back to its raw code.
     *
     * A row outlives the module that registered its provider, and an administrator who already
     * picked that row should still recognise it rather than see it vanish from the list.
     *
     * @param string $code
     * @return string
     */
    private function getServiceName(string $code): string
    {
        return $this->serviceRegistry->get($code)?->getName() ?? $code;
    }

    /**
     * Configured model of a row, or null when it carries none.
     *
     * @param AiServiceInterface $service
     * @return string|null
     */
    private function getModel(AiServiceInterface $service): ?string
    {
        $model = $service->getConfiguration()[self::FIELD_MODEL] ?? null;
        if (!is_scalar($model)) {
            return null;
        }

        return trim((string) $model) === '' ? null : trim((string) $model);
    }

    /**
     * Why the bundled client cannot use this backend, or null when it can.
     *
     * @param string $code
     * @return string|null
     */
    private function getUsabilityNote(string $code): ?string
    {
        if (!$this->bridgeRegistry->isSupported($code)) {
            return (string) __('no client bridge available');
        }

        return $this->bridgeRegistry->isAvailable($code) ? null : (string) __('bridge not installed');
    }

    /**
     * Suffix repeated labels with their position among their duplicates.
     *
     * Two rows of the same backend with the same model are a legitimate setup (two accounts, two
     * billing owners), and an administrator cannot pick between two identical option labels.
     *
     * @param array<int,array{value:string,label:string}> $options Option rows, each with a value and a label
     * @return array<int,array{value:string,label:string}>
     */
    private function numberDuplicateLabels(array $options): array
    {
        $occurrences = array_count_values(array_column($options, 'label'));
        $seen = [];

        return array_map(
            function (array $option) use ($occurrences, &$seen): array {
                $label = $option['label'];
                if ($occurrences[$label] < 2) {
                    return $option;
                }
                $seen[$label] = ($seen[$label] ?? 0) + 1;

                return $seen[$label] === 1
                    ? $option
                    : ['value' => $option['value'], 'label' => sprintf('%s #%d', $label, $seen[$label])];
            },
            $options,
        );
    }
}
