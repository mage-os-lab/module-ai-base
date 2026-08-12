<?php

declare(strict_types=1);

/**
 * Test stand-ins for the Magento-generated chat value object factories.
 *
 * In a full Magento install these are code-generated from the `Api\Data` interfaces and resolve
 * through the preferences in `etc/di.xml`; a standalone module checkout has no generator. The
 * class_exists guards (which also trigger autoloading/generation where available) keep the real
 * classes authoritative when present.
 */

namespace MageOS\AiBase\Api\Data;

if (!class_exists(ChatRequestInterfaceFactory::class)) {
    /**
     * Minimal stand-in matching the generated factory's public API.
     */
    class ChatRequestInterfaceFactory
    {
        /**
         * @param array $data
         * @return ChatRequestInterface
         */
        public function create(array $data = [])
        {
            return new \MageOS\AiBase\Model\Chat\ChatRequest(...$data);
        }
    }
}

if (!class_exists(ChatMessageInterfaceFactory::class)) {
    /**
     * Minimal stand-in matching the generated factory's public API.
     */
    class ChatMessageInterfaceFactory
    {
        /**
         * @param array $data
         * @return ChatMessageInterface
         */
        public function create(array $data = [])
        {
            return new \MageOS\AiBase\Model\Chat\ChatMessage(...$data);
        }
    }
}

if (!class_exists(ToolDefinitionInterfaceFactory::class)) {
    /**
     * Minimal stand-in matching the generated factory's public API.
     */
    class ToolDefinitionInterfaceFactory
    {
        /**
         * @param array $data
         * @return ToolDefinitionInterface
         */
        public function create(array $data = [])
        {
            return new \MageOS\AiBase\Model\Chat\ToolDefinition(...$data);
        }
    }
}
