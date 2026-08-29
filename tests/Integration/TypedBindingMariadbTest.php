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
 * Proves the typed-binding fix (BindTypedParameters) does not regress on MariaDB — same
 * scenario as TypedBindingMysqlTest, against the `mariadb` service from
 * support/docker-compose.yml (MariaDB speaks the MySQL wire protocol, so the same
 * ConnectionFactory::mysql() dialect applies).
 */
final class TypedBindingMariadbTest extends TestCase
{
    private const TABLE = 'test_typed_binding';
    private const PK = 'id';

    private static ConnectionPoolInterface $pool;
    private static PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        $factory = new ConnectionFactory();
        $writer = $factory->mysql(
            host: $_ENV['MARIADB_HOST'] ?? 'mariadb',
            user: $_ENV['MARIADB_USER'] ?? 'test_user',
            password: $_ENV['MARIADB_PASSWORD'] ?? 'test_password',
            database: $_ENV['MARIADB_DATABASE'] ?? 'test_db',
            port: (int) ($_ENV['MARIADB_PORT'] ?? 3306),
        );

        self::$pool = new ConnectionPool(
            writer: $writer,
            readers: [],
        );

        self::$pdo = self::$pool->getWriter()->pdo();

        self::$pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE);
        self::$pdo->exec('
            CREATE TABLE ' . self::TABLE . ' (
                id INT AUTO_INCREMENT PRIMARY KEY,
                active BOOLEAN NOT NULL,
                count INT,
                note VARCHAR(255)
            )
        ');
    }

    protected function setUp(): void
    {
        self::$pdo->exec('TRUNCATE TABLE ' . self::TABLE);
    }

    public static function tearDownAfterClass(): void
    {
        self::$pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE);
    }

    public function testInsertFalseIsPersistedAsZero(): void
    {
        $repository = new Repository(self::$pool);

        $id = $repository->insert(self::TABLE, self::PK, [
            'active' => false,
            'count' => 0,
            'note' => null,
        ]);

        $row = $repository->findById(self::TABLE, self::PK, $id);

        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row['active']);
        $this->assertSame(0, (int) $row['count']);
        $this->assertNull($row['note']);
    }

    public function testInsertTrueIsPersistedAsOne(): void
    {
        $repository = new Repository(self::$pool);

        $id = $repository->insert(self::TABLE, self::PK, [
            'active' => true,
            'count' => 5,
            'note' => 'hello',
        ]);

        $row = $repository->findById(self::TABLE, self::PK, $id);

        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['active']);
        $this->assertSame(5, (int) $row['count']);
        $this->assertSame('hello', $row['note']);
    }
}
