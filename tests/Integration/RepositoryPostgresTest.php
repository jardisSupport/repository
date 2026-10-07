<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Tests\Integration;

use JardisAdapter\DbConnection\ConnectionPool;
use JardisAdapter\DbConnection\Factory\ConnectionFactory;
use JardisSupport\Contract\DbConnection\ConnectionPoolInterface;
use JardisSupport\Contract\Repository\Exception\UniqueViolationException;
use JardisSupport\Contract\Repository\PrimaryKey\PkStrategy;
use JardisSupport\Repository\Repository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * I2: Optimistic-concurrency collision scenario against PostgreSQL — same scenario as
 * RepositoryTest::testConditionalUpdateCollisionScenario() (I1, MySQL), proving the
 * dialect divergence does not break the $expected-conditions contract.
 */
final class RepositoryPostgresTest extends TestCase
{
    private const TABLE = 'test_conditional_write';
    private const TABLE_UNIQUE = 'test_unique_violation';
    private const PK = 'id';

    private static ConnectionPoolInterface $pool;
    private static PDO $pdo;
    private Repository $repository;

    public static function setUpBeforeClass(): void
    {
        $factory = new ConnectionFactory();
        $writer = $factory->postgres(
            host: $_ENV['POSTGRES_HOST'] ?? 'postgres',
            user: $_ENV['POSTGRES_USER'] ?? 'test_user',
            password: $_ENV['POSTGRES_PASSWORD'] ?? 'test_password',
            database: $_ENV['POSTGRES_DATABASE'] ?? 'test_db',
            port: (int) ($_ENV['POSTGRES_PORT'] ?? 5432),
        );

        self::$pool = new ConnectionPool(
            writer: $writer,
            readers: [],
        );

        self::$pdo = self::$pool->getWriter()->pdo();

        self::$pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE_UNIQUE);
        self::$pdo->exec('
            CREATE TABLE ' . self::TABLE_UNIQUE . ' (
                id SERIAL PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL,
                CONSTRAINT uniq_email UNIQUE (email)
            )
        ');

        self::$pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE);
        self::$pdo->exec('
            CREATE TABLE ' . self::TABLE . ' (
                id SERIAL PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                age INT
            )
        ');
    }

    protected function setUp(): void
    {
        self::$pdo->exec('TRUNCATE TABLE ' . self::TABLE . ' RESTART IDENTITY');
        self::$pdo->exec('TRUNCATE TABLE ' . self::TABLE_UNIQUE . ' RESTART IDENTITY');
        $this->repository = new Repository(self::$pool);
    }

    public static function tearDownAfterClass(): void
    {
        self::$pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE);
        self::$pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE_UNIQUE);
    }

    public function testConditionalUpdateCollisionScenario(): void
    {
        $repositoryA = new Repository(self::$pool);
        $repositoryB = new Repository(self::$pool);

        $id = $repositoryA->insert(self::TABLE, self::PK, ['name' => 'Alice', 'age' => 100]);

        // Both "clients" read the same starting age (100). PDO_PGSQL may return numeric
        // columns as strings depending on driver/version, so compare numerically.
        $readByA = $repositoryA->findById(self::TABLE, self::PK, $id);
        $readByB = $repositoryB->findById(self::TABLE, self::PK, $id);
        $this->assertSame(100, (int) $readByA['age']);
        $this->assertSame(100, (int) $readByB['age']);

        // A writes first, expecting the age it read.
        $resultA = $repositoryA->update(self::TABLE, self::PK, $id, ['age' => 150], ['age' => 100]);
        $this->assertTrue($resultA);

        // B writes with the now-stale expectation and loses.
        $resultB = $repositoryB->update(self::TABLE, self::PK, $id, ['age' => 200], ['age' => 100]);
        $this->assertFalse($resultB);

        $row = $repositoryA->findById(self::TABLE, self::PK, $id);
        $this->assertSame(150, (int) $row['age']);
    }

    // ── UNIQUE VIOLATION ──────────────────────────────────────────────

    public function testAutoincrementDuplicateThrowsUniqueViolation(): void
    {
        $this->repository->insert(self::TABLE_UNIQUE, self::PK, ['name' => 'Alice', 'email' => 'a@x.de']);

        try {
            $this->repository->insert(self::TABLE_UNIQUE, self::PK, ['name' => 'Bob', 'email' => 'a@x.de']);
            $this->fail('UniqueViolationException expected');
        } catch (UniqueViolationException $e) {
            $this->assertNotNull($e->getConstraint());
            $this->assertStringContainsString('uniq_email', (string) $e->getConstraint());
        }
    }

    public function testProvidedPkDuplicateThrowsUniqueViolation(): void
    {
        $this->repository->insert(
            self::TABLE_UNIQUE,
            self::PK,
            ['id' => 10, 'name' => 'Alice', 'email' => 'a@x.de'],
            PkStrategy::NONE,
        );

        try {
            $this->repository->insert(
                self::TABLE_UNIQUE,
                self::PK,
                ['id' => 11, 'name' => 'Bob', 'email' => 'a@x.de'],
                PkStrategy::NONE,
            );
            $this->fail('UniqueViolationException expected');
        } catch (UniqueViolationException $e) {
            $this->assertNotNull($e->getConstraint());
        }
    }

    public function testIntegerPkForeignUniqueViolationThrowsWithoutRetry(): void
    {
        $this->repository->insert(
            self::TABLE_UNIQUE,
            self::PK,
            ['name' => 'Alice', 'email' => 'a@x.de'],
            PkStrategy::INTEGER,
        );

        try {
            $this->repository->insert(
                self::TABLE_UNIQUE,
                self::PK,
                ['name' => 'Bob', 'email' => 'a@x.de'],
                PkStrategy::INTEGER,
            );
            $this->fail('UniqueViolationException expected');
        } catch (UniqueViolationException $e) {
            $this->assertNotNull($e->getConstraint());
        }

        // A single attempt: the next successful insert gets MAX+1, no gap / no repeated draws.
        $next = $this->repository->insert(
            self::TABLE_UNIQUE,
            self::PK,
            ['name' => 'Carol', 'email' => 'c@x.de'],
            PkStrategy::INTEGER,
        );
        $this->assertSame(2, (int) $next);
    }

    public function testUpdateOntoForeignUniqueValueThrowsUniqueViolation(): void
    {
        $this->repository->insert(self::TABLE_UNIQUE, self::PK, ['name' => 'Alice', 'email' => 'a@x.de']);
        $id = $this->repository->insert(self::TABLE_UNIQUE, self::PK, ['name' => 'Bob', 'email' => 'b@x.de']);

        $this->expectException(UniqueViolationException::class);
        $this->repository->update(self::TABLE_UNIQUE, self::PK, $id, ['email' => 'a@x.de']);
    }
}
