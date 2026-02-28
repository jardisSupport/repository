<?php

declare(strict_types=1);

namespace JardisSupport\Repository;

use JardisPort\DbConnection\ConnectionPoolInterface;
use JardisPort\DbQuery\DbQueryBuilderInterface;
use JardisSupport\Repository\Handler\DeleteAllHandler;
use JardisSupport\Repository\Handler\DeleteHandler;
use JardisSupport\Repository\Handler\ExistsHandler;
use JardisSupport\Repository\Handler\FindByIdHandler;
use JardisSupport\Repository\Handler\InsertHandler;
use JardisSupport\Repository\Handler\QueryExecutor;
use JardisSupport\Repository\Handler\UpdateHandler;
use JardisSupport\Repository\PrimaryKey\PkStrategy;

/**
 * Generic CRUD repository with read/write splitting.
 */
final class Repository implements RepositoryInterface
{
    private ?QueryExecutor $writeExecutor = null;
    private ?QueryExecutor $readExecutor = null;

    private ?InsertHandler $insertHandler = null;
    private ?UpdateHandler $updateHandler = null;
    private ?DeleteHandler $deleteHandler = null;
    private ?DeleteAllHandler $deleteAllHandler = null;
    private ?FindByIdHandler $findByIdHandler = null;
    private ?ExistsHandler $existsHandler = null;

    public function __construct(
        private readonly ConnectionPoolInterface $pool,
    ) {
    }

    public function insert(
        string $table,
        string $pkColumn,
        array $values,
        PkStrategy $pkStrategy = PkStrategy::AUTOINCREMENT,
    ): int|string {
        $this->insertHandler ??= new InsertHandler($this->writer());

        return ($this->insertHandler)($table, $pkColumn, $values, $pkStrategy);
    }

    public function update(
        string $table,
        string $pkColumn,
        int|string $id,
        array $values,
    ): bool {
        $this->updateHandler ??= new UpdateHandler($this->writer());

        return ($this->updateHandler)($table, $pkColumn, $id, $values);
    }

    public function delete(
        string $table,
        string $pkColumn,
        int|string $id,
    ): bool {
        $this->deleteHandler ??= new DeleteHandler($this->writer());

        return ($this->deleteHandler)($table, $pkColumn, $id);
    }

    public function deleteAll(
        string $table,
        string $pkColumn,
        array $ids,
    ): void {
        $this->deleteAllHandler ??= new DeleteAllHandler($this->writer());

        ($this->deleteAllHandler)($table, $pkColumn, $ids);
    }

    public function findById(
        string $table,
        string $pkColumn,
        int|string $id,
    ): ?array {
        $this->findByIdHandler ??= new FindByIdHandler($this->reader());

        return ($this->findByIdHandler)($table, $pkColumn, $id);
    }

    public function findByQuery(DbQueryBuilderInterface $query): array
    {
        $reader = $this->reader();
        $prepared = $query->sql($reader->getDialect(), prepared: true);

        return $reader->fetchAll($prepared);
    }

    public function exists(
        string $table,
        string $pkColumn,
        int|string $id,
    ): bool {
        $this->existsHandler ??= new ExistsHandler($this->reader());

        return ($this->existsHandler)($table, $pkColumn, $id);
    }

    private function writer(): QueryExecutor
    {
        return $this->writeExecutor ??= new QueryExecutor($this->pool->getWriter());
    }

    private function reader(): QueryExecutor
    {
        return $this->readExecutor ??= new QueryExecutor($this->pool->getReader());
    }
}
