<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Tests\Integration;

use JardisAdapter\DbConnection\ConnectionPool;
use JardisAdapter\DbConnection\Factory\ConnectionFactory;
use JardisSupport\Contract\DbConnection\ConnectionPoolInterface;
use JardisSupport\Contract\Repository\Exception\PersistException;
use JardisSupport\Contract\Repository\Exception\TransientConflictException;
use JardisSupport\Contract\Repository\PrimaryKey\PkStrategy;
use JardisSupport\Repository\Repository;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * TransientConflictException against PostgreSQL:
 *  - 40001 serialization failure: REPEATABLE READ snapshot writes onto a row another session committed since
 *  - 40P01 deadlock detected: the test session and a peer process (tests/Integration/Support/PostgresLockPeer.php)
 *    lock crossed rows/keys; the test session requests last and is the deadlock victim
 *    (deadlock_timeout 300ms on both sessions, peer waits > 1s before the test session joins the cycle).
 */
final class RepositoryPostgresTransientConflictTest extends TestCase
{
    private const TABLE = 'test_pg_transient';
    private const TABLE_KEYS = 'test_pg_transient_keys';
    private const PK = 'id';

    private static ConnectionPoolInterface $pool;
    private static PDO $pdo;
    private static PDO $pdoB;
    /** @var array{dsn: string, user: string, password: string} */
    private static array $conn;
    private Repository $repository;

    public static function setUpBeforeClass(): void
    {
        $host = $_ENV['POSTGRES_HOST'] ?? 'postgres';
        $port = (int) ($_ENV['POSTGRES_PORT'] ?? 5432);
        $database = $_ENV['POSTGRES_DATABASE'] ?? 'test_db';
        $user = $_ENV['POSTGRES_USER'] ?? 'test_user';
        $password = $_ENV['POSTGRES_PASSWORD'] ?? 'test_password';
        self::$conn = [
            'dsn' => sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $database),
            'user' => $user,
            'password' => $password,
        ];

        $factory = new ConnectionFactory();
        $connect = static fn () => $factory->postgres(
            host: $host,
            user: $user,
            password: $password,
            database: $database,
            port: $port,
        );

        self::$pool = new ConnectionPool(writer: $connect(), readers: []);
        self::$pdo = self::$pool->getWriter()->pdo();
        self::$pdoB = $connect()->pdo();

        self::$pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE);
        self::$pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE_KEYS);
        self::$pdo->exec('CREATE TABLE ' . self::TABLE . ' (id INT PRIMARY KEY, status VARCHAR(20) NOT NULL)');
        self::$pdo->exec('CREATE TABLE ' . self::TABLE_KEYS . ' (id INT PRIMARY KEY)');
        self::$pdo->exec("SET deadlock_timeout = '300ms'");
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$pdo->inTransaction()) {
            self::$pdo->rollBack();
        }
        self::$pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE);
        self::$pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE_KEYS);
    }

    protected function setUp(): void
    {
        if (self::$pdo->inTransaction()) {
            self::$pdo->rollBack();
        }
        self::$pdo->exec('TRUNCATE TABLE ' . self::TABLE . ', ' . self::TABLE_KEYS);
        self::$pdo->exec("INSERT INTO " . self::TABLE . " (id, status) VALUES (1, 'a'), (2, 'a')");
        $this->repository = new Repository(self::$pool);
    }

    protected function tearDown(): void
    {
        if (self::$pdo->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    private function assertTransient(TransientConflictException $e, string $code): void
    {
        $this->assertSame($code, $e->getDriverCode());
        $this->assertInstanceOf(PDOException::class, $e->getPrevious());
        $this->assertInstanceOf(PersistException::class, $e);
    }

    /** A: REPEATABLE READ snapshot on row 1, B changes row 1 and commits. */
    private function serializationConflict(): void
    {
        self::$pdo->beginTransaction();
        self::$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        self::$pdo->query('SELECT status FROM ' . self::TABLE . ' WHERE id = 1')->fetchColumn();

        self::$pdoB->exec("UPDATE " . self::TABLE . " SET status = 'b' WHERE id = 1");
    }

    public function testUpdateOnChangedSnapshotThrowsSerializationFailure(): void
    {
        $this->serializationConflict();

        try {
            $this->repository->update(self::TABLE, self::PK, 1, ['status' => 'c'], ['status' => 'a']);
            $this->fail('TransientConflictException expected');
        } catch (TransientConflictException $e) {
            $this->assertTransient($e, '40001');
        }
    }

    public function testDeleteOnChangedSnapshotThrowsSerializationFailure(): void
    {
        $this->serializationConflict();

        try {
            $this->repository->delete(self::TABLE, self::PK, 1, ['status' => 'a']);
            $this->fail('TransientConflictException expected');
        } catch (TransientConflictException $e) {
            $this->assertTransient($e, '40001');
        }
    }

    public function testDeleteAllOnChangedSnapshotThrowsSerializationFailure(): void
    {
        $this->serializationConflict();

        try {
            $this->repository->deleteAll(self::TABLE, self::PK, [1]);
            $this->fail('TransientConflictException expected');
        } catch (TransientConflictException $e) {
            $this->assertTransient($e, '40001');
        }
    }

    public function testUpdateDeadlockThrowsDeadlockDetected(): void
    {
        $this->crossedLocks(
            "UPDATE " . self::TABLE . " SET status = 'p' WHERE id = 1",
            "UPDATE " . self::TABLE . " SET status = 'c' WHERE id = 2",
            "UPDATE " . self::TABLE . " SET status = 'c' WHERE id = 1",
            fn () => $this->repository->update(self::TABLE, self::PK, 2, ['status' => 'x']),
        );
    }

    public function testDeleteDeadlockThrowsDeadlockDetected(): void
    {
        $this->crossedLocks(
            "UPDATE " . self::TABLE . " SET status = 'p' WHERE id = 1",
            "UPDATE " . self::TABLE . " SET status = 'c' WHERE id = 2",
            "UPDATE " . self::TABLE . " SET status = 'c' WHERE id = 1",
            fn () => $this->repository->delete(self::TABLE, self::PK, 2),
        );
    }

    public function testDeleteAllDeadlockThrowsDeadlockDetected(): void
    {
        $this->crossedLocks(
            "UPDATE " . self::TABLE . " SET status = 'p' WHERE id = 1",
            "UPDATE " . self::TABLE . " SET status = 'c' WHERE id = 2",
            "UPDATE " . self::TABLE . " SET status = 'c' WHERE id = 1",
            fn () => $this->repository->deleteAll(self::TABLE, self::PK, [2]),
        );
    }

    public function testInsertDeadlockThrowsDeadlockDetected(): void
    {
        $this->crossedLocks(
            'INSERT INTO ' . self::TABLE_KEYS . ' (id) VALUES (1)',
            'INSERT INTO ' . self::TABLE_KEYS . ' (id) VALUES (2)',
            'INSERT INTO ' . self::TABLE_KEYS . ' (id) VALUES (1)',
            fn () => $this->repository->insert(self::TABLE_KEYS, self::PK, [self::PK => 2], PkStrategy::NONE),
        );
    }

    public function testUniqueViolationIsNotATransientConflict(): void
    {
        $this->repository->insert(self::TABLE_KEYS, self::PK, [self::PK => 7], PkStrategy::NONE);

        try {
            $this->repository->insert(self::TABLE_KEYS, self::PK, [self::PK => 7], PkStrategy::NONE);
            $this->fail('UniqueViolationException expected');
        } catch (TransientConflictException) {
            $this->fail('a unique violation is not a transient conflict');
        } catch (\JardisSupport\Contract\Repository\Exception\UniqueViolationException $e) {
            $this->assertSame('23505', $e->getPrevious()?->errorInfo[0] ?? null);
        }
    }

    /**
     * The test session takes $parentFirst, the peer takes $peerFirst and then blocks on $peerSecond (held by the test
     * session); the test session then runs $repositoryCall onto the peer's lock -> cycle, the test session is the victim.
     */
    private function crossedLocks(string $parentFirst, string $peerFirst, string $peerSecond, callable $repositoryCall): void
    {
        $peer = proc_open(
            [PHP_BINARY, __DIR__ . '/Support/PostgresLockPeer.php', json_encode(self::$conn + [
                'first' => $peerFirst,
                'second' => $peerSecond,
            ], JSON_THROW_ON_ERROR)],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($peer);

        try {
            stream_set_timeout($pipes[1], 15);
            $this->assertSame("ready\n", fgets($pipes[1]), 'peer did not take its first lock');

            self::$pdo->beginTransaction();
            self::$pdo->exec($parentFirst);

            fwrite($pipes[0], "go\n");
            fflush($pipes[0]);
            sleep(1); // peer now waits longer than deadlock_timeout; our request closes the cycle

            try {
                $repositoryCall();
                $this->fail('TransientConflictException expected');
            } catch (TransientConflictException $e) {
                $this->assertTransient($e, '40P01');
            } finally {
                if (self::$pdo->inTransaction()) {
                    self::$pdo->rollBack();
                }
            }

            $this->assertSame("done\n", fgets($pipes[1]), 'peer must finish once the victim rolled back');
        } finally {
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($peer);
        }
    }
}
