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
 * Reproduces and guards against the typed-binding bug (Bescheid Rolf 2026-08-29, found in the
 * Jardis license server): QueryExecutor/IntegerPkGenerator used to call
 * PDOStatement::execute($bindings), which binds every value as PDO::PARAM_STR. PHP `false`
 * then reached Postgres as the empty string, and Postgres rejects that against a BOOLEAN
 * column with "invalid input syntax for type boolean: """. BindTypedParameters fixes this by
 * binding bool/int/null/string with their matching PDO::PARAM_* type.
 */
final class TypedBindingPostgresTest extends TestCase
{
    private const TABLE = 'test_typed_binding';
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
                active BOOLEAN NOT NULL,
                count INT,
                note VARCHAR(255)
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

    /**
     * Belegt den Fund: ohne BindTypedParameters reicht PDOStatement::execute($bindings)
     * `false` als leeren String an eine BOOLEAN-Spalte durch. Postgres lehnt das ab.
     */
    public function testUntypedExecuteFailsAgainstBooleanColumn(): void
    {
        $stmt = self::$pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (active, count, note) VALUES (?, ?, ?)',
        );

        $caught = null;
        try {
            $stmt->execute([false, 0, null]);
        } catch (\PDOException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'Untyped execute() was expected to fail against a BOOLEAN column.');
        $this->assertStringContainsString('invalid input syntax for type boolean', $caught->getMessage());
    }

    public function testInsertFalseIsPersistedAsBoolean(): void
    {
        $repository = new Repository(self::$pool);

        $id = $repository->insert(self::TABLE, self::PK, [
            'active' => false,
            'count' => 0,
            'note' => null,
        ]);

        $row = $repository->findById(self::TABLE, self::PK, $id);

        $this->assertNotNull($row);
        $this->assertFalse((bool) $row['active']);
        $this->assertSame(0, (int) $row['count']);
        $this->assertNull($row['note']);
    }

    public function testInsertTrueIsPersistedAsBoolean(): void
    {
        $repository = new Repository(self::$pool);

        $id = $repository->insert(self::TABLE, self::PK, [
            'active' => true,
            'count' => 5,
            'note' => 'hello',
        ]);

        $row = $repository->findById(self::TABLE, self::PK, $id);

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row['active']);
        $this->assertSame(5, (int) $row['count']);
        $this->assertSame('hello', $row['note']);
    }

    public function testConditionalUpdateWithBooleanBindingsSucceeds(): void
    {
        $repository = new Repository(self::$pool);

        $id = $repository->insert(self::TABLE, self::PK, [
            'active' => true,
            'count' => 1,
            'note' => null,
        ]);

        $result = $repository->update(
            self::TABLE,
            self::PK,
            $id,
            ['active' => false],
            ['active' => true],
        );

        $this->assertTrue($result);

        $row = $repository->findById(self::TABLE, self::PK, $id);
        $this->assertFalse((bool) $row['active']);
    }
}
