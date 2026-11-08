<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Tests\Integration;

use JardisAdapter\DbConnection\ConnectionPool;
use JardisAdapter\DbConnection\Factory\ConnectionFactory;
use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use JardisSupport\Contract\Repository\Exception\PersistException;
use JardisSupport\Contract\Repository\Exception\TransientConflictException;
use JardisSupport\DbQuery\Data\DbPreparedQuery;
use JardisSupport\Repository\Handler\QueryExecutor;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * QueryExecutor used on its own (a statement that does not run through a Repository handler) against MariaDB with
 * snapshot isolation: a statement on a row another connection changed since the snapshot is rejected with 1020
 * and must surface as TransientConflictException on every statement path (execute, fetchAll, fetchOne).
 */
final class QueryExecutorMariadbTransientConflictTest extends TestCase
{
    private const TABLE = 'test_executor_transient';

    private static DbConnectionInterface $connectionA;
    private static PDO $pdoA;
    private static PDO $pdoB;
    private QueryExecutor $executor;

    public static function setUpBeforeClass(): void
    {
        $factory = new ConnectionFactory();
        $connect = static fn () => $factory->mysql(
            host: $_ENV['MARIADB_HOST'] ?? 'mariadb',
            user: $_ENV['MARIADB_USER'] ?? 'test_user',
            password: $_ENV['MARIADB_PASSWORD'] ?? 'test_password',
            database: $_ENV['MARIADB_DATABASE'] ?? 'test_db',
            port: (int) ($_ENV['MARIADB_PORT'] ?? 3306),
        );

        self::$connectionA = (new ConnectionPool(writer: $connect(), readers: []))->getWriter();
        self::$pdoA = self::$connectionA->pdo();
        self::$pdoB = $connect()->pdo();

        self::$pdoA->exec('DROP TABLE IF EXISTS ' . self::TABLE);
        self::$pdoA->exec(
            'CREATE TABLE ' . self::TABLE . ' (id INT PRIMARY KEY, counter INT NOT NULL)'
        );
        self::$pdoA->exec('SET SESSION innodb_snapshot_isolation=1');
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

        // A holds its snapshot, B changes the row and commits.
        self::$pdoA->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        self::$pdoA->query('SELECT counter FROM ' . self::TABLE . ' WHERE id = 1')->fetchColumn();
        self::$pdoB->exec('UPDATE ' . self::TABLE . ' SET counter = 1 WHERE id = 1');
    }

    protected function tearDown(): void
    {
        if (self::$pdoA->inTransaction()) {
            self::$pdoA->rollBack();
        }
    }

    private function update(): DbPreparedQuery
    {
        return new DbPreparedQuery(
            'UPDATE ' . self::TABLE . ' SET counter = counter + 1 WHERE id = ?',
            [1],
            'mariadb'
        );
    }

    private function lockingRead(): DbPreparedQuery
    {
        return new DbPreparedQuery('SELECT counter FROM ' . self::TABLE . ' WHERE id = ? FOR UPDATE', [1], 'mariadb');
    }

    private function assertTransient(TransientConflictException $e): void
    {
        $this->assertSame('1020', $e->getDriverCode());
        $this->assertInstanceOf(PDOException::class, $e->getPrevious());
        $this->assertInstanceOf(PersistException::class, $e);
    }

    public function testExecuteOnChangedSnapshotThrowsTransientConflict(): void
    {
        try {
            $this->executor->execute($this->update());
            $this->fail('TransientConflictException expected');
        } catch (TransientConflictException $e) {
            $this->assertTransient($e);
        }
    }

    public function testFetchAllOnChangedSnapshotThrowsTransientConflict(): void
    {
        try {
            $this->executor->fetchAll($this->lockingRead());
            $this->fail('TransientConflictException expected');
        } catch (TransientConflictException $e) {
            $this->assertTransient($e);
        }
    }

    public function testFetchOneOnChangedSnapshotThrowsTransientConflict(): void
    {
        try {
            $this->executor->fetchOne($this->lockingRead());
            $this->fail('TransientConflictException expected');
        } catch (TransientConflictException $e) {
            $this->assertTransient($e);
        }
    }

    public function testStatementWithoutConflictRunsThrough(): void
    {
        self::$pdoA->rollBack();

        $this->assertSame(1, $this->executor->execute($this->update())->rowCount());
    }

    public function testNonTransientErrorStaysPdoException(): void
    {
        self::$pdoA->rollBack();

        $this->expectException(PDOException::class);
        $this->executor->execute(new DbPreparedQuery('UPDATE ' . self::TABLE . ' SET nope = 1', [], 'mariadb'));
    }
}
