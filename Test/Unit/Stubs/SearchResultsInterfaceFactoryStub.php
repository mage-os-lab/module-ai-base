<?php

declare(strict_types=1);

/**
 * Test stand-in for the Magento-generated Magento\Framework\Api\SearchResultsInterfaceFactory.
 *
 * In a full Magento install the factory is code-generated at runtime; the standalone module
 * checkout has no generator, so tests that construct a repository's `getList()` collaborators
 * require this file to define a minimal, signature-compatible stub. The class_exists guard (which
 * also triggers autoloading/generation where available) keeps the real class authoritative when
 * present.
 */

namespace Magento\Framework\Api;

if (!class_exists(SearchResultsInterfaceFactory::class)) {
    /**
     * Minimal stand-in matching the generated factory's public API.
     */
    class SearchResultsInterfaceFactory
    {
        /**
         * Create a search results instance.
         *
         * @param array $data
         * @return SearchResultsInterface
         */
        public function create(array $data = [])
        {
            throw new \LogicException(
                'SearchResultsInterfaceFactoryStub::create() has no backing implementation outside '
                . 'a Magento install; mock its return value instead of calling through.'
            );
        }
    }
}
