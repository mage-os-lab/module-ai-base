<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api\Data;

interface AiServiceInterface
{
    /**
     * Stable identifier of this configured row, unique across all configured services.
     *
     * This is the row key Magento assigns in the admin form and keeps for the life of the row,
     * which is what makes it usable as a stored reference: a consumer module can persist it in
     * its own configuration and resolve it back through AiServiceSelectorInterface::getById().
     * The same backend can be registered more than once, so the service code alone does not
     * identify a row.
     *
     * @return string
     */
    public function getId(): string;

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
