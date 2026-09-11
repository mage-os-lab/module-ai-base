<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Usage;

/**
 * Runs one unit of usage housekeeping so that either all of it lands or none of it does.
 *
 * Narrow on purpose, and internal to `Model\Usage`: this is not a general transaction service for
 * other modules, it exists so {@see UsageMaintenance} can make "write the day's aggregate, then
 * delete the raw rows behind it" a single step. Written as an interface rather than a concrete
 * collaborator so the maintenance unit tests can drive it with an in-memory fake, the way every
 * other collaborator in this namespace already is.
 */
interface UsageTransactionInterface
{
    /**
     * Runs $work inside a database transaction and returns whatever it counted.
     *
     * A throwable from $work rolls the transaction back and is rethrown: the caller decides
     * whether a failed unit is fatal, and nothing here swallows it.
     *
     * @param callable():int $work
     * @return int Whatever $work returned, once it is committed
     */
    public function run(callable $work): int;
}
