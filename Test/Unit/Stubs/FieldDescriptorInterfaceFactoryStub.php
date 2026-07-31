<?php

declare(strict_types=1);

/**
 * Test stand-in for the Magento-generated FieldDescriptorInterfaceFactory.
 *
 * In a full Magento install the factory is code-generated at runtime; the standalone module
 * checkout has no generator, so tests that construct AiServices classes require this file to
 * define a minimal, signature-compatible stub. The class_exists guard (which also triggers
 * autoloading/generation where available) keeps the real class authoritative when present.
 */

namespace MageOS\AiBase\Api\Data;

if (!class_exists(FieldDescriptorInterfaceFactory::class)) {
    /**
     * Minimal stand-in matching the generated factory's public API.
     */
    class FieldDescriptorInterfaceFactory
    {
        /**
         * Create a field descriptor instance.
         *
         * @param array $data
         * @return FieldDescriptorInterface
         */
        public function create(array $data = [])
        {
            return new \MageOS\AiBase\Model\FieldDescriptor(...$data);
        }
    }
}
