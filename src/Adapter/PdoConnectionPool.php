<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Adapter;

use JardisSupport\Contract\DbConnection\ConnectionPoolInterface;
use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use PDO;

/**
 * Wraps a plain PDO instance as ConnectionPoolInterface.
 *
 * Uses the same connection for both reader and writer (no splitting).
 */
final class PdoConnectionPool implements ConnectionPoolInterface
{
    private readonly PdoConnection $connection;

    public function __construct(PDO $pdo)
    {
        $this->connection = new PdoConnection($pdo);
    }

    public function getWriter(): DbConnectionInterface
    {
        return $this->connection;
    }

    public function getReader(): DbConnectionInterface
    {
        return $this->connection;
    }

    public function getReaders(): array
    {
        return [$this->connection];
    }

    public function getReaderCount(): int
    {
        return 1;
    }

    public function getStats(): array
    {
        return ['reads' => 0, 'writes' => 0, 'failovers' => 0, 'readers' => 1];
    }

    public function resetStats(): void
    {
        // No-op — plain PDO wrapper does not track statistics.
    }
}
