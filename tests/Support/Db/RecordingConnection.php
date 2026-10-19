<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Tests\Support\Db;

use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use JardisSupport\Contract\DbConnection\SavepointInterface;
use JardisSupport\Repository\Adapter\PdoConnection;
use PDO;

/**
 * A real connection (the repository's PdoConnection) that notes every transaction call it receives and
 * can be told to fail on one of them.
 *
 * Behaviour is the real one; the log only answers "how often was X called" (a call that must NOT happen
 * leaves no row state to look at). A call told to fail throws before it reaches the database.
 */
final class RecordingConnection implements DbConnectionInterface, SavepointInterface
{
    /** @var list<string> */
    private array $calls = [];

    /** @var array<string, \Throwable> */
    private array $failures = [];

    private PdoConnection $inner;

    public function __construct(PDO $pdo)
    {
        $this->inner = new PdoConnection($pdo);
    }

    /**
     * @return list<string> `beginTransaction`, `commit`, `rollback`, `savepoint:<name>`, `release:<name>`,
     *                      `rollbackTo:<name>`
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function failOn(string $call, \Throwable $failure): void
    {
        $this->failures[$call] = $failure;
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
        $this->note('beginTransaction');
        $this->inner->beginTransaction();
    }

    public function commit(): void
    {
        $this->note('commit');
        $this->inner->commit();
    }

    public function rollback(): void
    {
        $this->note('rollback');
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

    public function savepoint(string $name): void
    {
        $this->note('savepoint:' . $name);
        $this->inner->savepoint($name);
    }

    public function releaseSavepoint(string $name): void
    {
        $this->note('release:' . $name);
        $this->inner->releaseSavepoint($name);
    }

    public function rollbackToSavepoint(string $name): void
    {
        $this->note('rollbackTo:' . $name);
        $this->inner->rollbackToSavepoint($name);
    }

    private function note(string $call): void
    {
        $this->calls[] = $call;
        $kind = explode(':', $call)[0];
        if (isset($this->failures[$kind])) {
            throw $this->failures[$kind];
        }
    }
}
