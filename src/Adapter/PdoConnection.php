<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Adapter;

use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use PDO;

/**
 * Wraps a plain PDO instance as DbConnectionInterface.
 */
final class PdoConnection implements DbConnectionInterface
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function getDriverName(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function getDatabaseName(): string
    {
        $driver = $this->getDriverName();

        if ($driver === 'sqlite') {
            $stmt = $this->pdo->query('PRAGMA database_list');
            $row = $stmt !== false ? $stmt->fetch() : false;

            $file = is_array($row) ? ($row['file'] ?? '') : '';

            return $file !== '' ? $file : ':memory:';
        }

        if ($driver === 'pgsql') {
            $stmt = $this->pdo->query('SELECT current_database()');
            $name = $stmt !== false ? $stmt->fetchColumn() : false;

            return is_string($name) ? $name : '';
        }

        $stmt = $this->pdo->query('SELECT DATABASE()');
        $name = $stmt !== false ? $stmt->fetchColumn() : false;

        return is_string($name) ? $name : '';
    }

    public function connect(): void
    {
        // Already connected — PDO was provided externally.
    }

    public function disconnect(): void
    {
        // Not our responsibility — caller owns the PDO lifecycle.
    }

    public function isConnected(): bool
    {
        return true;
    }

    public function reconnect(): void
    {
        // Cannot reconnect a plain PDO — no DSN available.
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollback(): void
    {
        $this->pdo->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }
}
