<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api\Data;

/**
 * One tool invocation the model asked for.
 *
 * This module never executes tools: it reports what was requested and carries the consumer's
 * result back. Deciding whether a call is allowed to run, and running it, stays with the module
 * that owns the tool.
 */
interface ToolCallInterface
{
    /**
     * Provider-assigned id, used to pair this call with its result.
     *
     * @return string
     */
    public function getId(): string;

    /**
     * Name of the tool the model wants to invoke.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Arguments the model supplied, decoded from the provider's JSON.
     *
     * @return array<string, mixed>
     */
    public function getArguments(): array;
}
