<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Adapter;

use InvalidArgumentException;
use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use JardisSupport\Contract\DbConnection\SavepointInterface;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Wraps a plain PDO instance as DbConnectionInterface (and SavepointInterface).
 */
final class PdoConnection implements DbConnectionInterface, SavepointInterface
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

    public function savepoint(string $name): void
    {
        $this->runSavepointStatement('SAVEPOINT', $name);
    }

    public function releaseSavepoint(string $name): void
    {
        $this->runSavepointStatement('RELEASE SAVEPOINT', $name);
    }

    public function rollbackToSavepoint(string $name): void
    {
        $this->runSavepointStatement('ROLLBACK TO SAVEPOINT', $name);
    }

    /**
     * Same SQL form on MySQL/MariaDB, PostgreSQL and SQLite. The name is
     * interpolated, so it is validated first: identifier form, max 63 characters.
     *
     * @throws InvalidArgumentException If the name is not a valid identifier
     * @throws RuntimeException If no transaction is active or the statement fails
     */
    private function runSavepointStatement(string $statement, string $name): void
    {
        $isIdentifier = preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) === 1;
        if (!$isIdentifier || strlen($name) > SavepointInterface::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'Invalid savepoint name "%s": expected [A-Za-z_][A-Za-z0-9_]* with at most %d characters',
                $name,
                SavepointInterface::MAX_NAME_LENGTH
            ));
        }

        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException(sprintf('%s %s requires an active transaction', $statement, $name));
        }

        try {
            $this->pdo->exec($statement . ' ' . $name);
        } catch (PDOException $e) {
            throw new RuntimeException(
                sprintf('%s %s failed: %s', $statement, $name, $e->getMessage()),
                (int) $e->getCode(),
                $e
            );
        }
    }
}
