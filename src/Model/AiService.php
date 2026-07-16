<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model;

use MageOS\AiBase\Api\Data\AiServiceInterface;

class AiService implements AiServiceInterface
{
    /**
     * @param string $code
     * @param array $configuration
     */
    public function __construct(
        private readonly string $code,
        private readonly array $configuration
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * @inheritdoc
     */
    public function getConfiguration(): array
    {
        return $this->configuration;
    }
}
