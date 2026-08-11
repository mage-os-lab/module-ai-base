<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model;

use MageOS\AiBase\Api\Data\AiServiceInterface;

class AiService implements AiServiceInterface
{
    /**
     * @param string $id
     * @param string $code
     * @param array<string,mixed> $configuration
     */
    public function __construct(
        private readonly string $id,
        private readonly string $code,
        private readonly array $configuration
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getId(): string
    {
        return $this->id;
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
    public function getLabel(): ?string
    {
        $label = $this->configuration[self::CONFIGURATION_LABEL] ?? null;
        if (!is_scalar($label)) {
            return null;
        }

        return trim((string) $label) === '' ? null : trim((string) $label);
    }

    /**
     * @inheritdoc
     */
    public function isEnabled(): bool
    {
        $enabled = $this->configuration[self::CONFIGURATION_ENABLED] ?? null;
        if ($enabled === null) {
            return true;
        }

        // The form posts '0' or '1'; a hand-edited value could be anything. Only an explicit
        // negative turns a row off, so an unreadable value leaves it working.
        return !in_array($enabled, ['0', 0, false, 'false'], true);
    }

    /**
     * @inheritdoc
     */
    public function getConfiguration(): array
    {
        return $this->configuration;
    }
}
