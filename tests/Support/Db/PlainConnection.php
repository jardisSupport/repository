<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Tests\Support\Db;

use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use JardisSupport\Repository\Adapter\PdoConnection;
use PDO;

/**
 * A connection that offers transactions but no savepoints (it does not implement SavepointInterface) -
 * the one case a nested unit of work cannot be bracketed.
 */
final class PlainConnection implements DbConnectionInterface
{
    private PdoConnection $inner;

    public function __construct(PDO $pdo)
    {
        $this->inner = new PdoConnection($pdo);
    }

    public function connect(): void
    {
        $this->inner->connect();
    }

    public function pdo(): PDO
    {
        return $this->inner->pdo();
    }

    public function isConnected(): bool
    {
        return $this->inner->isConnected();
    }

    public function disconnect(): void
    {
        $this->inner->disconnect();
    }

    public function reconnect(): void
    {
        $this->inner->reconnect();
    }

    public function beginTransaction(): void
    {
        $this->inner->beginTransaction();
    }

    public function commit(): void
    {
        $this->inner->commit();
    }

    public function rollback(): void
    {
        $this->inner->rollback();
    }

    public function inTransaction(): bool
    {
        return $this->inner->inTransaction();
    }

    public function getDriverName(): string
    {
        return $this->inner->getDriverName();
    }

    public function getDatabaseName(): string
    {
        return $this->inner->getDatabaseName();
    }
}
