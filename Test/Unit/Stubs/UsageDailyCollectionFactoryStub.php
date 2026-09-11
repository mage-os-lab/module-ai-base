<?php

declare(strict_types=1);

/**
 * Test stand-in for the Magento-generated
 * MageOS\AiBase\Model\ResourceModel\Usage\UsageDaily\CollectionFactory.
 *
 * In a full Magento install the factory is code-generated at runtime; the standalone module
 * checkout has no generator, so tests that construct {@see \MageOS\AiBase\Model\Usage\
 * UsageDailyRepository} require this file to define a minimal, signature-compatible stub. The
 * class_exists guard (which also triggers autoloading/generation where available) keeps the real
 * class authoritative when present.
 */

namespace MageOS\AiBase\Model\ResourceModel\Usage\UsageDaily;

if (!class_exists(CollectionFactory::class)) {
    /**
     * Minimal stand-in matching the generated factory's public API.
     */
    class CollectionFactory
    {
        /**
         * Create a collection instance.
         *
         * @param array $data
         * @return Collection
         */
        public function create(array $data = [])
        {
            throw new \LogicException(
                'CollectionFactoryStub::create() has no backing collection outside a Magento install; '
                . 'mock its return value instead of calling through.'
            );
        }
    }
}
