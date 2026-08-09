<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api;

use MageOS\AiBase\Api\Data\AiServiceInterface;

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
