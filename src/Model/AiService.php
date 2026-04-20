<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model;

use MageOS\AiBase\Api\Data\AiServiceInterface;

final class AiService implements AiServiceInterface
{
    public function __construct(
        private readonly string $code,
        private readonly array $configuration
    ) {}

    public function getCode(): string
    {
        return $this->code;
    }

    public function getConfiguration(): array
    {
        return $this->configuration;
    }
}
