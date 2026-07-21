<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Handler;

use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use JardisSupport\Contract\DbQuery\DbPreparedQueryInterface;
use PDO;

/**
 * Executes prepared queries against a database connection.
 */
final readonly class QueryExecutor
{
    private string $dialect;

    public function __construct(
        private DbConnectionInterface $connection,
    ) {
        $this->dialect = match ($connection->getDriverName()) {
            'pgsql' => 'postgres',
            default => $connection->getDriverName(),
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(DbPreparedQueryInterface $prepared): array
    {
        $stmt = $this->connection->pdo()->prepare($prepared->sql());
        $stmt->execute($prepared->bindings());

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetchOne(DbPreparedQueryInterface $prepared): ?array
    {
        $stmt = $this->connection->pdo()->prepare($prepared->sql());
        $stmt->execute($prepared->bindings());
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function execute(DbPreparedQueryInterface $prepared): \PDOStatement
    {
        $stmt = $this->connection->pdo()->prepare($prepared->sql());
        $stmt->execute($prepared->bindings());

        return $stmt;
    }

    public function getDialect(): string
    {
        return $this->dialect;
    }

    public function getPdo(): PDO
    {
        return $this->connection->pdo();
    }
}
