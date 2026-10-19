<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Tests\Support\Db;

use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use JardisSupport\Contract\DbConnection\SavepointInterface;
use PDO;

/**
 * A fake connection without any database: it only keeps whether a transaction is open and the order of the
 * calls it received - the way to look at savepoint names and call order without SQL strings.
 */
final class FakeSavepointConnection implements DbConnectionInterface, SavepointInterface
{
    /** @var list<string> */
    private array $calls = [];

    public function __construct(private bool $inTransaction = true)
    {
    }

    /**
     * @return list<string>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function connect(): void
    {
    }

    public function pdo(): PDO
    {
        throw new \LogicException('the fake has no PDO');
    }

    public function isConnected(): bool
    {
        return true;
    }

    public function disconnect(): void
    {
    }

    public function reconnect(): void
    {
    }

    public function beginTransaction(): void
    {
        $this->calls[] = 'beginTransaction';
        $this->inTransaction = true;
    }

    public function commit(): void
    {
        $this->calls[] = 'commit';
        $this->inTransaction = false;
    }

    public function rollback(): void
    {
        $this->calls[] = 'rollback';
        $this->inTransaction = false;
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    public function getDriverName(): string
    {
        return 'fake';
    }

    public function getDatabaseName(): string
    {
        return 'fake';
    }

    public function savepoint(string $name): void
    {
        $this->calls[] = 'savepoint:' . $name;
    }

    public function releaseSavepoint(string $name): void
    {
        $this->calls[] = 'release:' . $name;
    }

    public function rollbackToSavepoint(string $name): void
    {
        $this->calls[] = 'rollbackTo:' . $name;
    }
}
