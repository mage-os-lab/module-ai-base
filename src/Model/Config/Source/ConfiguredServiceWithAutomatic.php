<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Config\Source;

/**
 * ConfiguredService plus an empty-valued "Automatic" option.
 *
 * Use this variant when the consuming field should also allow "no explicit choice", which maps
 * onto AiClientFactoryInterface::create() without an argument: the first configured service whose
 * bridge is installed. Keeping it a separate class rather than a constructor flag means a
 * system.xml field says which of the two behaviours it wants by naming the source model.
 */
class ConfiguredServiceWithAutomatic extends ConfiguredService
{
    /**
     * @inheritdoc
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        return array_merge(
            [['value' => '', 'label' => (string) __('Automatic (first usable service)')]],
            parent::toOptionArray(),
        );
    }
}
