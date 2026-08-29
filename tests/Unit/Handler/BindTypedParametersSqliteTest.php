<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Tests\Unit\Handler;

use JardisAdapter\DbConnection\ConnectionPool;
use JardisAdapter\DbConnection\Factory\ConnectionFactory;
use JardisSupport\Contract\DbConnection\ConnectionPoolInterface;
use JardisSupport\Repository\Repository;
use PHPUnit\Framework\TestCase;

/**
 * Proves the typed-binding fix (BindTypedParameters) does not regress on SQLite in-memory,
 * where bool/int both map to an INTEGER column. No Docker service needed, so this runs as a
 * Unit test — same scenario as TypedBindingPostgresTest (Integration, the dialect that
 * actually broke) and TypedBindingMysqlTest/TypedBindingMariadbTest.
 */
final class BindTypedParametersSqliteTest extends TestCase
{
    private const TABLE = 'test_typed_binding';
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
                active INTEGER NOT NULL,
                note TEXT
            )
        ');
    }

    public function testInsertFalseIsPersistedAsZero(): void
    {
        $repository = new Repository($this->pool);

        $id = $repository->insert(self::TABLE, self::PK, [
            'active' => false,
            'note' => null,
        ]);

        $row = $repository->findById(self::TABLE, self::PK, $id);

        $this->assertNotNull($row);
        $this->assertSame(0, $row['active']);
        $this->assertNull($row['note']);
    }

    public function testInsertTrueIsPersistedAsOne(): void
    {
        $repository = new Repository($this->pool);

        $id = $repository->insert(self::TABLE, self::PK, [
            'active' => true,
            'note' => 'hello',
        ]);

        $row = $repository->findById(self::TABLE, self::PK, $id);

        $this->assertNotNull($row);
        $this->assertSame(1, $row['active']);
        $this->assertSame('hello', $row['note']);
    }
}
