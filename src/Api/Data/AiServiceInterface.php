<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api\Data;

interface AiServiceInterface
{
    public function getCode(): string;

    /**
     * @return array<string, mixed>
     */
    public function getConfiguration(): array;
}
