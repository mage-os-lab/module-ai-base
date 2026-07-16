<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api\Data;

interface AiServiceInterface
{
    /**
     * Machine code of the configured AI backend.
     *
     * @return string
     */
    public function getCode(): string;

    /**
     * Stored configuration values for this service instance.
     *
     * @return array<string, mixed>
     */
    public function getConfiguration(): array;
}
