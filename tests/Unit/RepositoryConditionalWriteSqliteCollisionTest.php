<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Tests\Unit;

use JardisAdapter\DbConnection\ConnectionPool;
use JardisAdapter\DbConnection\Factory\ConnectionFactory;
use JardisSupport\Contract\DbConnection\ConnectionPoolInterface;
use JardisSupport\Repository\Repository;
use PHPUnit\Framework\TestCase;

/**
 * I3: Optimistic-concurrency collision scenario against SQLite via the adapter path
 * (ConnectionFactory/ConnectionPool) — same layer and scenario as
 * RepositoryTest::testConditionalUpdateCollisionScenario() (I1, MySQL) and
 * RepositoryPostgresTest::testConditionalUpdateCollisionScenario() (I2, PostgreSQL).
 * SQLite needs no Docker service, so this runs as a Unit test.
 */
final class RepositoryConditionalWriteSqliteCollisionTest extends TestCase
{
    private const TABLE = 'accounts';
    private const PK = 'id';

    private ConnectionPoolInterface $pool;

    protected function setUp(): void
    {
        $factory = new ConnectionFactory();
        $connection = $factory->sqlite(':memory:');

        $this->pool = new ConnectionPool(writer: $connection, readers: []);

        $connection->pdo()->exec('
            CREATE TABLE ' . self::TABLE . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                balance INTEGER NOT NULL
            )
        ');
    }

    public function testConditionalUpdateCollisionScenario(): void
    {
        $repositoryA = new Repository($this->pool);
        $repositoryB = new Repository($this->pool);

        $id = $repositoryA->insert(self::TABLE, self::PK, ['balance' => 100]);

        // Both "clients" read the same starting balance (100).
        $readByA = $repositoryA->findById(self::TABLE, self::PK, $id);
        $readByB = $repositoryB->findById(self::TABLE, self::PK, $id);
        $this->assertSame(100, $readByA['balance']);
        $this->assertSame(100, $readByB['balance']);

        // A writes first, expecting the balance it read.
        $resultA = $repositoryA->update(self::TABLE, self::PK, $id, ['balance' => 150], ['balance' => 100]);
        $this->assertTrue($resultA);

        // B writes with the now-stale expectation and loses.
        $resultB = $repositoryB->update(self::TABLE, self::PK, $id, ['balance' => 200], ['balance' => 100]);
        $this->assertFalse($resultB);

        $row = $repositoryA->findById(self::TABLE, self::PK, $id);
        $this->assertSame(150, $row['balance']);
    }
}
