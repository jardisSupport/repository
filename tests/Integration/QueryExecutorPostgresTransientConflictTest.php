<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Tests\Integration;

use JardisAdapter\DbConnection\ConnectionPool;
use JardisAdapter\DbConnection\Factory\ConnectionFactory;
use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use JardisSupport\Contract\Repository\Exception\TransientConflictException;
use JardisSupport\DbQuery\Data\DbPreparedQuery;
use JardisSupport\Repository\Handler\QueryExecutor;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * QueryExecutor used on its own against PostgreSQL: a statement on a row another session changed since the
 * REPEATABLE READ snapshot fails with 40001 and must surface as TransientConflictException on every statement path.
 */
final class QueryExecutorPostgresTransientConflictTest extends TestCase
{
    private const TABLE = 'test_executor_pg_transient';

    private static DbConnectionInterface $connectionA;
    private static PDO $pdoA;
    private static PDO $pdoB;
    private QueryExecutor $executor;

    public static function setUpBeforeClass(): void
    {
        $factory = new ConnectionFactory();
        $connect = static fn () => $factory->postgres(
            host: $_ENV['POSTGRES_HOST'] ?? 'postgres',
            user: $_ENV['POSTGRES_USER'] ?? 'test_user',
            password: $_ENV['POSTGRES_PASSWORD'] ?? 'test_password',
            database: $_ENV['POSTGRES_DATABASE'] ?? 'test_db',
            port: (int) ($_ENV['POSTGRES_PORT'] ?? 5432),
        );

        self::$connectionA = (new ConnectionPool(writer: $connect(), readers: []))->getWriter();
        self::$pdoA = self::$connectionA->pdo();
        self::$pdoB = $connect()->pdo();

        self::$pdoA->exec('DROP TABLE IF EXISTS ' . self::TABLE);
        self::$pdoA->exec('CREATE TABLE ' . self::TABLE . ' (id INT PRIMARY KEY, counter INT NOT NULL)');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$pdoA->inTransaction()) {
            self::$pdoA->rollBack();
        }
        self::$pdoA->exec('DROP TABLE IF EXISTS ' . self::TABLE);
    }

    protected function setUp(): void
    {
        if (self::$pdoA->inTransaction()) {
            self::$pdoA->rollBack();
        }
        self::$pdoA->exec('TRUNCATE TABLE ' . self::TABLE);
        self::$pdoA->exec('INSERT INTO ' . self::TABLE . ' (id, counter) VALUES (1, 0)');
        $this->executor = new QueryExecutor(self::$connectionA);

        self::$pdoA->beginTransaction();
        self::$pdoA->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        self::$pdoA->query('SELECT counter FROM ' . self::TABLE . ' WHERE id = 1')->fetchColumn();
        self::$pdoB->exec('UPDATE ' . self::TABLE . ' SET counter = 1 WHERE id = 1');
    }

    protected function tearDown(): void
    {
        if (self::$pdoA->inTransaction()) {
            self::$pdoA->rollBack();
        }
    }

    private function returningUpdate(): DbPreparedQuery
    {
        return new DbPreparedQuery(
            'UPDATE ' . self::TABLE . ' SET counter = counter + 1 WHERE id = ? RETURNING counter',
            [1],
            'postgres'
        );
    }

    public function testExecuteOnChangedSnapshotThrowsTransientConflict(): void
    {
        try {
            $this->executor->execute($this->returningUpdate());
            $this->fail('TransientConflictException expected');
        } catch (TransientConflictException $e) {
            $this->assertSame('40001', $e->getDriverCode());
        }
    }

    public function testFetchAllOnChangedSnapshotThrowsTransientConflict(): void
    {
        try {
            $this->executor->fetchAll($this->returningUpdate());
            $this->fail('TransientConflictException expected');
        } catch (TransientConflictException $e) {
            $this->assertSame('40001', $e->getDriverCode());
        }
    }

    public function testFetchOneOnChangedSnapshotThrowsTransientConflict(): void
    {
        try {
            $this->executor->fetchOne($this->returningUpdate());
            $this->fail('TransientConflictException expected');
        } catch (TransientConflictException $e) {
            $this->assertSame('40001', $e->getDriverCode());
        }
    }
}
