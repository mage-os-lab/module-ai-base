<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Usage;

use Magento\Framework\App\ResourceConnection;

/**
 * {@see UsageTransactionInterface} over the default connection, which is the one both usage
 * tables live on, so a roll-up and the delete behind it commit or roll back together.
 */
class UsageTransaction implements UsageTransactionInterface
{
    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(private readonly ResourceConnection $resourceConnection)
    {
    }

    /**
     * @inheritdoc
     */
    public function run(callable $work): int
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->beginTransaction();

        try {
            $result = $work();
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }

        $connection->commit();

        return $result;
    }
}
