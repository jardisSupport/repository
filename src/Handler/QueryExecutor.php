<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Handler;

use JardisPort\DbConnection\DbConnectionInterface;
use JardisPort\DbQuery\DbPreparedQueryInterface;
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
     * @param string|DbPreparedQueryInterface $prepared
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(string|DbPreparedQueryInterface $prepared): array
    {
        assert($prepared instanceof DbPreparedQueryInterface);

        $stmt = $this->connection->pdo()->prepare($prepared->sql());
        $stmt->execute($prepared->bindings());

        return $stmt->fetchAll();
    }

    /**
     * @param string|DbPreparedQueryInterface $prepared
     * @return array<string, mixed>|null
     */
    public function fetchOne(string|DbPreparedQueryInterface $prepared): ?array
    {
        assert($prepared instanceof DbPreparedQueryInterface);

        $stmt = $this->connection->pdo()->prepare($prepared->sql());
        $stmt->execute($prepared->bindings());
        $row = $stmt->fetch();

        return $row === false ? null : $row;
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
