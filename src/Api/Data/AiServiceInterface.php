<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api\Data;

interface AiServiceInterface
{
    /**
     * Configuration key holding the name an administrator gave this row.
     *
     * Optional, and deliberately not a provider field: the same backend is configured more than
     * once for different purposes ("Chat AI" and "Summaries" on two Anthropic keys), and the
     * purpose is what an administrator picking a service in another module's configuration
     * recognises. Underscore-prefixed so it cannot collide with a field a provider declares.
     */
    public const CONFIGURATION_LABEL = '_label';

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
     * Configuration key holding whether this row may be used.
     *
     * Absent means enabled. Rows saved before this existed carry no such key, and a stored
     * configuration that suddenly stopped working would be a worse surprise than a row that stays
     * on until somebody turns it off.
     */
    public const CONFIGURATION_ENABLED = '_enabled';

    /**
     * The name an administrator gave this row, or null when they left it unnamed.
     *
     * The same backend gets configured more than once for different purposes, on different keys,
     * and the purpose is what someone picking a service in another module's configuration
     * recognises. Null rather than a fallback to the provider name, so a caller can tell "named
     * Chat AI" from "unnamed row that happens to be Anthropic" and choose its own wording.
     *
     * @return string|null
     */
    public function getLabel(): ?string;

    /**
     * Whether this row may be used.
     *
     * A disabled row is configuration an administrator wants kept but not called: credentials that
     * are being rotated, an account that is over budget, a provider being trialled. It keeps its
     * id and its credentials, and `AiServiceSelectorInterface` stops handing it out, so nothing
     * reaches a provider through it until it is turned back on.
     *
     * @return bool
     */
    public function isEnabled(): bool;

    /**
     * Stored configuration values for this service instance.
     *
     * @return array<string, mixed>
     */
    public function getConfiguration(): array;
}
