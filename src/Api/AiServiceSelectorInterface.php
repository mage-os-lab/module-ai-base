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
     * Multiple entries per code are possible when an admin registers the same backend more than once.
     *
     * @return list<AiServiceInterface>
     */
    public function getByCode(string $code): array;
}
