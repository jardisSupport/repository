<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Handler;

use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use JardisSupport\Contract\DbQuery\DbPreparedQueryInterface;
use PDO;

/**
 * Executes prepared queries against a database connection.
 *
 * The single place where a transient concurrency conflict of the database (snapshot/serialization conflict,
 * deadlock - see DetectTransientConflict) becomes a TransientConflictException, on every statement path
 * (fetchAll, fetchOne, execute). Every other PDOException passes through unchanged for the caller to classify.
 */
final readonly class QueryExecutor
{
    private string $dialect;

    private BindTypedParameters $bindTypedParameters;

    private TranslateTransientConflict $translateTransientConflict;

    public function __construct(
        private DbConnectionInterface $connection,
        ?BindTypedParameters $bindTypedParameters = null,
    ) {
        $this->dialect = match ($connection->getDriverName()) {
            'pgsql' => 'postgres',
            default => $connection->getDriverName(),
        };
        $this->bindTypedParameters = $bindTypedParameters ?? new BindTypedParameters();
        $this->translateTransientConflict = new TranslateTransientConflict();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(DbPreparedQueryInterface $prepared): array
    {
        return $this->guarded(function () use ($prepared): array {
            return $this->run($prepared)->fetchAll(PDO::FETCH_ASSOC);
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetchOne(DbPreparedQueryInterface $prepared): ?array
    {
        return $this->guarded(function () use ($prepared): ?array {
            $row = $this->run($prepared)->fetch(PDO::FETCH_ASSOC);

            return $row === false ? null : $row;
        });
    }

    public function execute(DbPreparedQueryInterface $prepared): \PDOStatement
    {
        return $this->guarded(fn(): \PDOStatement => $this->run($prepared));
    }

    public function getDialect(): string
    {
        return $this->dialect;
    }

    public function getPdo(): PDO
    {
        return $this->connection->pdo();
    }

    private function run(DbPreparedQueryInterface $prepared): \PDOStatement
    {
        $stmt = $this->connection->pdo()->prepare($prepared->sql());
        ($this->bindTypedParameters)($stmt, $prepared->bindings());
        $stmt->execute();

        return $stmt;
    }

    /**
     * @template T
     * @param \Closure(): T $statement
     * @return T
     */
    private function guarded(\Closure $statement): mixed
    {
        try {
            return $statement();
        } catch (\PDOException $e) {
            throw ($this->translateTransientConflict)($e, $this->dialect) ?? $e;
        }
    }
}
