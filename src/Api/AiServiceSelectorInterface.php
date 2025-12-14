<?php

namespace MageOS\AiBase\Api;

use MageOS\AiBase\Api\Data\AiServiceInterface;

interface AiServiceSelectorInterface
{
    /**
     * @return AiServiceInterface[]
     */
    public function getAll(): array;

    /**
     * @return AiServiceInterface[]
     */
    public function getByCode(string $code): array;
}
