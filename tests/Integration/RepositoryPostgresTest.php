<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Tests\Integration;

use JardisAdapter\DbConnection\ConnectionPool;
use JardisAdapter\DbConnection\Factory\ConnectionFactory;
use JardisSupport\Contract\DbConnection\ConnectionPoolInterface;
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
    private const PK = 'id';

    private static ConnectionPoolInterface $pool;
    private static PDO $pdo;

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
    }

    public static function tearDownAfterClass(): void
    {
        self::$pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE);
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
}
