<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Tests\Integration;

use JardisAdapter\DbConnection\ConnectionPool;
use JardisAdapter\DbConnection\Factory\ConnectionFactory;
use JardisSupport\Contract\DbConnection\ConnectionPoolInterface;
use JardisSupport\Contract\Repository\Exception\PersistException;
use JardisSupport\Contract\Repository\Exception\TransientConflictException;
use JardisSupport\Repository\Repository;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * TransientConflictException against MariaDB with snapshot isolation (service `mariadb` from
 * support/docker-compose.yml, MariaDB >= 11.8 where innodb_snapshot_isolation exists).
 *
 * With innodb_snapshot_isolation=1 a write to a row that was changed (and committed) by another
 * connection after the writer's snapshot is rejected with ERROR 1020 / SQLSTATE HY000 instead of
 * silently overwriting the newer row. Connection A (the repository's writer) holds the snapshot,
 * connection B changes the row in between.
 */
final class RepositoryMariadbTransientConflictTest extends TestCase
{
    private const TABLE = 'test_mariadb_transient';
    private const PK = 'id';

    private static ConnectionPoolInterface $pool;
    private static PDO $pdoA;
    private static PDO $pdoB;
    private Repository $repository;

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

        self::$pool = new ConnectionPool(writer: $connect(), readers: []);
        self::$pdoA = self::$pool->getWriter()->pdo();
        self::$pdoB = $connect()->pdo();

        self::$pdoA->exec('DROP TABLE IF EXISTS ' . self::TABLE);
        self::$pdoA->exec(
            'CREATE TABLE ' . self::TABLE . ' (id INT AUTO_INCREMENT PRIMARY KEY, status VARCHAR(20) NOT NULL)'
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
        $this->repository = new Repository(self::$pool);
    }

    protected function tearDown(): void
    {
        if (self::$pdoA->inTransaction()) {
            self::$pdoA->rollBack();
        }
    }

    /** Creates row $id with status 'a', opens A's snapshot, then B changes the row and commits. */
    private function conflictingRow(): int
    {
        $id = $this->repository->insert(self::TABLE, self::PK, ['status' => 'a']);

        self::$pdoA->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        self::$pdoA->query('SELECT status FROM ' . self::TABLE . ' WHERE id = ' . $id)->fetchColumn();

        self::$pdoB->exec("UPDATE " . self::TABLE . " SET status = 'b' WHERE id = " . $id);

        return $id;
    }

    private function assertTransient(TransientConflictException $e): void
    {
        $this->assertSame('1020', $e->getDriverCode());
        $this->assertInstanceOf(PDOException::class, $e->getPrevious());
        $this->assertInstanceOf(PersistException::class, $e);
    }

    public function testUpdateAgainstChangedSnapshotThrowsTransientConflict(): void
    {
        $id = $this->conflictingRow();

        try {
            $this->repository->update(self::TABLE, self::PK, $id, ['status' => 'c'], ['status' => 'a']);
            $this->fail('TransientConflictException expected');
        } catch (TransientConflictException $e) {
            $this->assertTransient($e);
        }
    }

    public function testDeleteAgainstChangedSnapshotThrowsTransientConflict(): void
    {
        $id = $this->conflictingRow();

        try {
            $this->repository->delete(self::TABLE, self::PK, $id, ['status' => 'a']);
            $this->fail('TransientConflictException expected');
        } catch (TransientConflictException $e) {
            $this->assertTransient($e);
        }
    }

    public function testDeleteAllAgainstChangedSnapshotThrowsTransientConflict(): void
    {
        $id = $this->conflictingRow();

        try {
            $this->repository->deleteAll(self::TABLE, self::PK, [$id]);
            $this->fail('TransientConflictException expected');
        } catch (TransientConflictException $e) {
            $this->assertTransient($e);
        }
    }

    public function testRetryInNewTransactionSucceedsAfterConflict(): void
    {
        $id = $this->conflictingRow();

        try {
            $this->repository->update(self::TABLE, self::PK, $id, ['status' => 'c'], ['status' => 'a']);
            $this->fail('TransientConflictException expected');
        } catch (TransientConflictException) {
            self::$pdoA->rollBack();
        }

        $this->assertFalse(
            $this->repository->update(self::TABLE, self::PK, $id, ['status' => 'c'], ['status' => 'a']),
            'optimistic check now fails on the data (row is b), not on the snapshot'
        );
        $this->assertTrue(
            $this->repository->update(self::TABLE, self::PK, $id, ['status' => 'c'], ['status' => 'b'])
        );
    }

    public function testWriteWithoutConcurrentChangeIsNotAConflict(): void
    {
        $id = $this->repository->insert(self::TABLE, self::PK, ['status' => 'a']);

        self::$pdoA->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        $this->assertTrue($this->repository->update(self::TABLE, self::PK, $id, ['status' => 'c'], ['status' => 'a']));
        self::$pdoA->commit();
    }

    public function testNonTransientErrorStaysPersistException(): void
    {
        $id = $this->repository->insert(self::TABLE, self::PK, ['status' => 'a']);

        try {
            $this->repository->update(self::TABLE, self::PK, $id, ['no_such_column' => 'x']);
            $this->fail('PersistException expected');
        } catch (TransientConflictException) {
            $this->fail('unknown column is not a transient conflict');
        } catch (PersistException $e) {
            $this->assertInstanceOf(PDOException::class, $e->getPrevious());
        }
    }
}
