<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Handler\Transaction;

use JardisSupport\Contract\DbConnection\ConnectionPoolInterface;
use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use JardisSupport\Repository\Adapter\PdoConnection;

/**
 * Resolves the writer connection for a transaction from what an application holds.
 *
 * A connection pool gives its writer, a plain PDO is wrapped by {@see PdoConnection} (transactions and
 * savepoints), no database gives null - then only work that opted out of the transaction can run.
 */
final class ResolveConnection
{
    public function __invoke(ConnectionPoolInterface|\PDO|null $connection): ?DbConnectionInterface
    {
        return match (true) {
            $connection instanceof ConnectionPoolInterface => $connection->getWriter(),
            $connection instanceof \PDO => new PdoConnection($connection),
            default => null,
        };
    }
}
