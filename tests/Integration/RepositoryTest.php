<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Tests\Integration;

use JardisAdapter\DbConnection\ConnectionPool;
use JardisAdapter\DbConnection\Data\MySqlConfig;
use JardisAdapter\DbConnection\MySql;
use JardisPort\DbConnection\ConnectionPoolInterface;
use JardisSupport\DbQuery\DbQuery;
use JardisSupport\Repository\Exception\PersistException;
use JardisSupport\Repository\PrimaryKey\PkStrategy;
use JardisSupport\Repository\Repository;
use PDO;
use PHPUnit\Framework\TestCase;

final class RepositoryTest extends TestCase
{
    private const TABLE_AUTO = 'test_auto_pk';
    private const TABLE_INT = 'test_integer_pk';
    private const TABLE_STR = 'test_string_pk';
    private const PK = 'id';

    private static ConnectionPoolInterface $pool;
    private static PDO $pdo;
    private Repository $repository;

    public static function setUpBeforeClass(): void
    {
        $config = new MySqlConfig(
            host: $_ENV['MYSQL_HOST'] ?? 'mysql',
            user: $_ENV['MYSQL_USER'] ?? 'test_user',
            password: $_ENV['MYSQL_PASSWORD'] ?? 'test_password',
            database: $_ENV['MYSQL_DATABASE'] ?? 'test_db',
            port: (int) ($_ENV['MYSQL_PORT'] ?? 3306),
        );

        self::$pool = new ConnectionPool(
            writer: $config,
            readers: [],
            driverClass: MySql::class,
        );

        self::$pdo = self::$pool->getWriter()->pdo();

        self::$pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE_AUTO);
        self::$pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE_INT);
        self::$pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE_STR);

        self::$pdo->exec('
            CREATE TABLE ' . self::TABLE_AUTO . ' (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255),
                age INT,
                status VARCHAR(50) DEFAULT \'active\'
            )
        ');

        self::$pdo->exec('
            CREATE TABLE ' . self::TABLE_INT . ' (
                id INT PRIMARY KEY,
                name VARCHAR(255) NOT NULL
            )
        ');

        self::$pdo->exec('
            CREATE TABLE ' . self::TABLE_STR . ' (
                id VARCHAR(36) PRIMARY KEY,
                name VARCHAR(255) NOT NULL
            )
        ');
    }

    protected function setUp(): void
    {
        self::$pdo->exec('TRUNCATE TABLE ' . self::TABLE_AUTO);
        self::$pdo->exec('TRUNCATE TABLE ' . self::TABLE_INT);
        self::$pdo->exec('TRUNCATE TABLE ' . self::TABLE_STR);

        $this->repository = new Repository(self::$pool);
    }

    public static function tearDownAfterClass(): void
    {
        self::$pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE_AUTO);
        self::$pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE_INT);
        self::$pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE_STR);
    }

    // ── INSERT ──────────────────────────────────────────────────────

    public function testInsertWithAutoincrementReturnsGeneratedId(): void
    {
        $id = $this->repository->insert(
            self::TABLE_AUTO,
            self::PK,
            ['name' => 'Alice', 'email' => 'alice@example.com', 'age' => 30],
        );

        $this->assertSame(1, $id);

        $id2 = $this->repository->insert(
            self::TABLE_AUTO,
            self::PK,
            ['name' => 'Bob', 'email' => 'bob@example.com', 'age' => 25],
        );

        $this->assertSame(2, $id2);
    }

    public function testInsertWithIntegerPkGeneratesSequentialId(): void
    {
        $id1 = $this->repository->insert(
            self::TABLE_INT,
            self::PK,
            ['name' => 'Alice'],
            PkStrategy::INTEGER,
        );

        $this->assertSame(1, $id1);

        $id2 = $this->repository->insert(
            self::TABLE_INT,
            self::PK,
            ['name' => 'Bob'],
            PkStrategy::INTEGER,
        );

        $this->assertSame(2, $id2);
    }

    public function testInsertWithNonePkUsesProvidedKey(): void
    {
        $id = $this->repository->insert(
            self::TABLE_STR,
            self::PK,
            ['id' => 'my-custom-pk-123', 'name' => 'Alice'],
            PkStrategy::NONE,
        );

        $this->assertSame('my-custom-pk-123', $id);

        $row = $this->repository->findById(self::TABLE_STR, self::PK, $id);
        $this->assertSame('Alice', $row['name']);
    }

    public function testInsertWithNonePkThrowsWhenPkMissing(): void
    {
        $this->expectException(PersistException::class);

        $this->repository->insert(
            self::TABLE_STR,
            self::PK,
            ['name' => 'Alice'],
            PkStrategy::NONE,
        );
    }

    public function testInsertWithEmptyValuesThrowsPersistException(): void
    {
        $this->expectException(PersistException::class);

        $this->repository->insert(self::TABLE_AUTO, self::PK, []);
    }

    public function testInsertedDataIsPersistedCorrectly(): void
    {
        $this->repository->insert(
            self::TABLE_AUTO,
            self::PK,
            ['name' => 'Alice', 'email' => 'alice@example.com', 'age' => 30, 'status' => 'active'],
        );

        $row = $this->repository->findById(self::TABLE_AUTO, self::PK, 1);

        $this->assertNotNull($row);
        $this->assertSame('Alice', $row['name']);
        $this->assertSame('alice@example.com', $row['email']);
        $this->assertSame(30, $row['age']);
        $this->assertSame('active', $row['status']);
    }

    // ── UPDATE ──────────────────────────────────────────────────────

    public function testUpdateModifiesExistingRecord(): void
    {
        $id = $this->repository->insert(
            self::TABLE_AUTO,
            self::PK,
            ['name' => 'Alice', 'email' => 'alice@example.com', 'age' => 30],
        );

        $result = $this->repository->update(
            self::TABLE_AUTO,
            self::PK,
            $id,
            ['name' => 'Alice Updated', 'age' => 31],
        );

        $this->assertTrue($result);

        $row = $this->repository->findById(self::TABLE_AUTO, self::PK, $id);
        $this->assertSame('Alice Updated', $row['name']);
        $this->assertSame(31, $row['age']);
        $this->assertSame('alice@example.com', $row['email']);
    }

    public function testUpdateWithEmptyValuesReturnsTrue(): void
    {
        $id = $this->repository->insert(
            self::TABLE_AUTO,
            self::PK,
            ['name' => 'Alice'],
        );

        $result = $this->repository->update(self::TABLE_AUTO, self::PK, $id, []);

        $this->assertTrue($result);
    }

    // ── DELETE ───────────────────────────────────────────────────────

    public function testDeleteRemovesRecord(): void
    {
        $id = $this->repository->insert(
            self::TABLE_AUTO,
            self::PK,
            ['name' => 'Alice'],
        );

        $result = $this->repository->delete(self::TABLE_AUTO, self::PK, $id);

        $this->assertTrue($result);
        $this->assertNull($this->repository->findById(self::TABLE_AUTO, self::PK, $id));
    }

    public function testDeleteAllRemovesMultipleRecords(): void
    {
        $id1 = $this->repository->insert(self::TABLE_AUTO, self::PK, ['name' => 'Alice']);
        $id2 = $this->repository->insert(self::TABLE_AUTO, self::PK, ['name' => 'Bob']);
        $id3 = $this->repository->insert(self::TABLE_AUTO, self::PK, ['name' => 'Charlie']);

        $this->repository->deleteAll(self::TABLE_AUTO, self::PK, [$id1, $id3]);

        $this->assertNull($this->repository->findById(self::TABLE_AUTO, self::PK, $id1));
        $this->assertNotNull($this->repository->findById(self::TABLE_AUTO, self::PK, $id2));
        $this->assertNull($this->repository->findById(self::TABLE_AUTO, self::PK, $id3));
    }

    public function testDeleteAllWithEmptyIdsIsNoOp(): void
    {
        $this->repository->insert(self::TABLE_AUTO, self::PK, ['name' => 'Alice']);

        $this->repository->deleteAll(self::TABLE_AUTO, self::PK, []);

        $query = (new DbQuery())->select('COUNT(*) as cnt')->from(self::TABLE_AUTO);
        $rows = $this->repository->findByQuery($query);
        $this->assertSame(1, (int) $rows[0]['cnt']);
    }

    // ── FIND BY ID ──────────────────────────────────────────────────

    public function testFindByIdReturnsExistingRecord(): void
    {
        $id = $this->repository->insert(
            self::TABLE_AUTO,
            self::PK,
            ['name' => 'Alice', 'email' => 'alice@example.com'],
        );

        $row = $this->repository->findById(self::TABLE_AUTO, self::PK, $id);

        $this->assertNotNull($row);
        $this->assertSame('Alice', $row['name']);
        $this->assertSame('alice@example.com', $row['email']);
    }

    public function testFindByIdReturnsNullForNonExistentRecord(): void
    {
        $row = $this->repository->findById(self::TABLE_AUTO, self::PK, 9999);

        $this->assertNull($row);
    }

    // ── FIND BY QUERY ───────────────────────────────────────────────

    public function testFindByQueryReturnsMatchingRecords(): void
    {
        $this->repository->insert(self::TABLE_AUTO, self::PK, ['name' => 'Alice', 'status' => 'active']);
        $this->repository->insert(self::TABLE_AUTO, self::PK, ['name' => 'Bob', 'status' => 'inactive']);
        $this->repository->insert(self::TABLE_AUTO, self::PK, ['name' => 'Charlie', 'status' => 'active']);

        $query = (new DbQuery())
            ->select('*')
            ->from(self::TABLE_AUTO)
            ->where('status')->equals('active');

        $rows = $this->repository->findByQuery($query);

        $this->assertCount(2, $rows);
        $this->assertSame('Alice', $rows[0]['name']);
        $this->assertSame('Charlie', $rows[1]['name']);
    }

    public function testFindByQueryWithOrderByAndLimit(): void
    {
        $this->repository->insert(self::TABLE_AUTO, self::PK, ['name' => 'Charlie', 'age' => 35]);
        $this->repository->insert(self::TABLE_AUTO, self::PK, ['name' => 'Alice', 'age' => 30]);
        $this->repository->insert(self::TABLE_AUTO, self::PK, ['name' => 'Bob', 'age' => 25]);

        $query = (new DbQuery())
            ->select('*')
            ->from(self::TABLE_AUTO)
            ->orderBy('name', 'ASC')
            ->limit(2);

        $rows = $this->repository->findByQuery($query);

        $this->assertCount(2, $rows);
        $this->assertSame('Alice', $rows[0]['name']);
        $this->assertSame('Bob', $rows[1]['name']);
    }

    public function testFindByQueryWithGreaterThan(): void
    {
        $this->repository->insert(self::TABLE_AUTO, self::PK, ['name' => 'Alice', 'age' => 20]);
        $this->repository->insert(self::TABLE_AUTO, self::PK, ['name' => 'Bob', 'age' => 30]);
        $this->repository->insert(self::TABLE_AUTO, self::PK, ['name' => 'Charlie', 'age' => 40]);

        $query = (new DbQuery())
            ->select('*')
            ->from(self::TABLE_AUTO)
            ->where('age')->greater(25);

        $rows = $this->repository->findByQuery($query);

        $this->assertCount(2, $rows);
    }

    public function testFindByQueryWithLike(): void
    {
        $this->repository->insert(self::TABLE_AUTO, self::PK, ['name' => 'Alice Smith']);
        $this->repository->insert(self::TABLE_AUTO, self::PK, ['name' => 'Bob Jones']);
        $this->repository->insert(self::TABLE_AUTO, self::PK, ['name' => 'Alice Johnson']);

        $query = (new DbQuery())
            ->select('*')
            ->from(self::TABLE_AUTO)
            ->where('name')->like('Alice%');

        $rows = $this->repository->findByQuery($query);

        $this->assertCount(2, $rows);
    }

    public function testFindByQueryWithInOperator(): void
    {
        $this->repository->insert(self::TABLE_AUTO, self::PK, ['name' => 'Alice', 'status' => 'active']);
        $this->repository->insert(self::TABLE_AUTO, self::PK, ['name' => 'Bob', 'status' => 'inactive']);
        $this->repository->insert(self::TABLE_AUTO, self::PK, ['name' => 'Charlie', 'status' => 'pending']);

        $query = (new DbQuery())
            ->select('*')
            ->from(self::TABLE_AUTO)
            ->where('status')->in(['active', 'pending']);

        $rows = $this->repository->findByQuery($query);

        $this->assertCount(2, $rows);
    }

    public function testFindByQueryWithIsNull(): void
    {
        $this->repository->insert(self::TABLE_AUTO, self::PK, ['name' => 'Alice', 'email' => 'alice@example.com']);
        $this->repository->insert(self::TABLE_AUTO, self::PK, ['name' => 'Bob', 'email' => null]);

        $query = (new DbQuery())
            ->select('*')
            ->from(self::TABLE_AUTO)
            ->where('email')->isNull();

        $rows = $this->repository->findByQuery($query);

        $this->assertCount(1, $rows);
        $this->assertSame('Bob', $rows[0]['name']);
    }

    public function testFindByQueryWithBetween(): void
    {
        $this->repository->insert(self::TABLE_AUTO, self::PK, ['name' => 'Alice', 'age' => 20]);
        $this->repository->insert(self::TABLE_AUTO, self::PK, ['name' => 'Bob', 'age' => 30]);
        $this->repository->insert(self::TABLE_AUTO, self::PK, ['name' => 'Charlie', 'age' => 40]);

        $query = (new DbQuery())
            ->select('*')
            ->from(self::TABLE_AUTO)
            ->where('age')->between(25, 35);

        $rows = $this->repository->findByQuery($query);

        $this->assertCount(1, $rows);
        $this->assertSame('Bob', $rows[0]['name']);
    }

    public function testFindByQueryWithOrCondition(): void
    {
        $this->repository->insert(self::TABLE_AUTO, self::PK, ['name' => 'Alice', 'age' => 20, 'status' => 'active']);
        $this->repository->insert(self::TABLE_AUTO, self::PK, ['name' => 'Bob', 'age' => 30, 'status' => 'inactive']);
        $this->repository->insert(self::TABLE_AUTO, self::PK, ['name' => 'Charlie', 'age' => 40, 'status' => 'active']);

        $query = (new DbQuery())
            ->select('*')
            ->from(self::TABLE_AUTO)
            ->where('age')->lower(25)
            ->or('status')->equals('inactive');

        $rows = $this->repository->findByQuery($query);

        $this->assertCount(2, $rows);
    }

    public function testFindByQueryReturnsEmptyArrayWhenNoMatch(): void
    {
        $query = (new DbQuery())
            ->select('*')
            ->from(self::TABLE_AUTO)
            ->where('name')->equals('NonExistent');

        $rows = $this->repository->findByQuery($query);

        $this->assertSame([], $rows);
    }

    public function testFindByQuerySelectsSpecificColumns(): void
    {
        $this->repository->insert(
            self::TABLE_AUTO,
            self::PK,
            ['name' => 'Alice', 'email' => 'alice@example.com', 'age' => 30],
        );

        $query = (new DbQuery())
            ->select('name, email')
            ->from(self::TABLE_AUTO);

        $rows = $this->repository->findByQuery($query);

        $this->assertCount(1, $rows);
        $this->assertArrayHasKey('name', $rows[0]);
        $this->assertArrayHasKey('email', $rows[0]);
        $this->assertArrayNotHasKey('age', $rows[0]);
    }

    public function testFindByQueryWithCount(): void
    {
        $this->repository->insert(self::TABLE_AUTO, self::PK, ['name' => 'Alice', 'status' => 'active']);
        $this->repository->insert(self::TABLE_AUTO, self::PK, ['name' => 'Bob', 'status' => 'active']);
        $this->repository->insert(self::TABLE_AUTO, self::PK, ['name' => 'Charlie', 'status' => 'inactive']);

        $query = (new DbQuery())
            ->select('COUNT(*) as cnt')
            ->from(self::TABLE_AUTO)
            ->where('status')->equals('active');

        $rows = $this->repository->findByQuery($query);

        $this->assertSame(2, (int) $rows[0]['cnt']);
    }

    // ── EXISTS ──────────────────────────────────────────────────────

    public function testExistsReturnsTrueForExistingRecord(): void
    {
        $id = $this->repository->insert(self::TABLE_AUTO, self::PK, ['name' => 'Alice']);

        $this->assertTrue($this->repository->exists(self::TABLE_AUTO, self::PK, $id));
    }

    public function testExistsReturnsFalseForNonExistentRecord(): void
    {
        $this->assertFalse($this->repository->exists(self::TABLE_AUTO, self::PK, 9999));
    }

    public function testExistsReturnsFalseAfterDelete(): void
    {
        $id = $this->repository->insert(self::TABLE_AUTO, self::PK, ['name' => 'Alice']);

        $this->repository->delete(self::TABLE_AUTO, self::PK, $id);

        $this->assertFalse($this->repository->exists(self::TABLE_AUTO, self::PK, $id));
    }
}
