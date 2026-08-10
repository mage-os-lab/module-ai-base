<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api;

use MageOS\AiBase\Api\Data\AiServiceInterface;

/**
 * Reads the AI services an administrator configured.
 *
 * Scope: every method resolves the configuration at store scope, in whatever scope is ambient when
 * it is called, and none of them takes a scope argument. In a storefront request that is the
 * current store, so a per-store setup resolves on its own. In adminhtml, in cron and on the CLI
 * there is no current store, so the default scope answers — a per-website or per-store services
 * list is not reachable from those contexts. Code that needs a specific scope has to establish it
 * first (store emulation), which is the same rule the rest of Magento's configuration follows.
 */
interface AiServiceSelectorInterface
{
    /**
     * Returns all configured AI services in insertion order (the order the admin saved them).
     *
     * @return list<AiServiceInterface>
     */
    public function getAll(): array;

    /**
     * Returns all configured AI services with the given code, in insertion order.
     *
     * Multiple entries per code are possible when an admin registers the same backend more than once.
     *
     * @param string $code
     * @return list<AiServiceInterface>
     */
    public function getByCode(string $code): array;

    /**
     * Returns the single configured AI service carrying the given row id, or null when it is gone.
     *
     * This is the resolution step for a stored reference: a consumer module persists the id an
     * administrator picked (see Model\Config\Source\ConfiguredService) and reads the row back
     * here. Null rather than an exception, because an administrator deleting a row in the AI
     * Configuration form is ordinary, and every consumer needs to handle that state anyway.
     *
     * @param string $id
     * @return AiServiceInterface|null
     */
    public function getById(string $id): ?AiServiceInterface;
}
