<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Tests\Integration;

use JardisAdapter\DbConnection\ConnectionPool;
use JardisAdapter\DbConnection\Factory\ConnectionFactory;
use JardisSupport\Contract\DbConnection\ConnectionPoolInterface;
use JardisSupport\Repository\Handler\Transaction\RunOwnedTransaction;
use JardisSupport\Repository\Repository;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * K16: `update()` answers `false` only when no row meets the condition, never because the matched row stayed
 * unchanged. MariaDB/MySQL count changed rows in `rowCount()` (an UPDATE that sets `'1'` onto a stored DECIMAL
 * `1.00` matches the row but reports 0), PostgreSQL and SQLite count matched rows. Real databases from
 * support/docker-compose.yml, no mock.
 */
final class RepositoryUpdateUnchangedRowTest extends TestCase
{
    private const TABLE = 'test_update_unchanged_row';
    private const PK = 'id';

    /** @var array<string, ConnectionPoolInterface> */
    private static array $pools = [];

    /**
     * @return array<string, array{string}>
     */
    public static function dialects(): array
    {
        return ['mariadb' => ['mariadb'], 'postgres' => ['postgres']];
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$pools as $pool) {
            $pool->getWriter()->pdo()->exec('DROP TABLE IF EXISTS ' . self::TABLE);
        }
        self::$pools = [];
    }

    private function pool(string $dialect): ConnectionPoolInterface
    {
        if (isset(self::$pools[$dialect])) {
            return self::$pools[$dialect];
        }

        $factory = new ConnectionFactory();
        $writer = $dialect === 'mariadb'
            ? $factory->mysql(
                host: $_ENV['MARIADB_HOST'] ?? 'mariadb',
                user: $_ENV['MARIADB_USER'] ?? 'test_user',
                password: $_ENV['MARIADB_PASSWORD'] ?? 'test_password',
                database: $_ENV['MARIADB_DATABASE'] ?? 'test_db',
                port: (int) ($_ENV['MARIADB_PORT'] ?? 3306),
            )
            : $factory->postgres(
                host: $_ENV['POSTGRES_HOST'] ?? 'postgres',
                user: $_ENV['POSTGRES_USER'] ?? 'test_user',
                password: $_ENV['POSTGRES_PASSWORD'] ?? 'test_password',
                database: $_ENV['POSTGRES_DATABASE'] ?? 'test_db',
                port: (int) ($_ENV['POSTGRES_PORT'] ?? 5432),
            );

        $pool = new ConnectionPool(writer: $writer, readers: []);
        $pdo = $pool->getWriter()->pdo();
        $pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE);
        $pdo->exec(
            'CREATE TABLE ' . self::TABLE . ' ('
            . ($dialect === 'mariadb' ? 'id INT AUTO_INCREMENT PRIMARY KEY' : 'id SERIAL PRIMARY KEY')
            . ', quantity DECIMAL(10,2) NOT NULL, status VARCHAR(20) NOT NULL)'
        );

        return self::$pools[$dialect] = $pool;
    }

    /**
     * @return array{Repository, PDO, int}
     */
    private function seeded(string $dialect): array
    {
        $pool = $this->pool($dialect);
        $pdo = $pool->getWriter()->pdo();
        $pdo->exec('DELETE FROM ' . self::TABLE);
        $pdo->exec("INSERT INTO " . self::TABLE . " (quantity, status) VALUES (1.00, 'open')");
        $id = (int) $pdo->query('SELECT MAX(id) FROM ' . self::TABLE)->fetchColumn();

        return [new Repository($pool), $pdo, $id];
    }

    #[DataProvider('dialects')]
    public function testUnchangedRowWithoutExpectedIsStillAHit(string $dialect): void
    {
        [$repository, , $id] = $this->seeded($dialect);

        $this->assertTrue($repository->update(self::TABLE, self::PK, $id, ['quantity' => '1']));
    }

    #[DataProvider('dialects')]
    public function testUnchangedRowWithMatchingExpectedIsStillAHit(string $dialect): void
    {
        [$repository, , $id] = $this->seeded($dialect);

        $this->assertTrue(
            $repository->update(self::TABLE, self::PK, $id, ['quantity' => '1'], ['quantity' => '1.00'])
        );
    }

    #[DataProvider('dialects')]
    public function testChangedRowIsAHit(string $dialect): void
    {
        [$repository, $pdo, $id] = $this->seeded($dialect);

        $this->assertTrue($repository->update(self::TABLE, self::PK, $id, ['status' => 'paid'], ['status' => 'open']));
        $this->assertSame('paid', $pdo->query('SELECT status FROM ' . self::TABLE)->fetchColumn());
    }

    #[DataProvider('dialects')]
    public function testDivergingExpectedIsAConflict(string $dialect): void
    {
        [$repository, $pdo, $id] = $this->seeded($dialect);

        $this->assertFalse(
            $repository->update(self::TABLE, self::PK, $id, ['quantity' => '1'], ['status' => 'closed'])
        );
        $this->assertSame('open', $pdo->query('SELECT status FROM ' . self::TABLE)->fetchColumn());
    }

    #[DataProvider('dialects')]
    public function testDeletedRowIsAConflict(string $dialect): void
    {
        [$repository, $pdo, $id] = $this->seeded($dialect);
        $pdo->exec('DELETE FROM ' . self::TABLE . ' WHERE id = ' . $id);

        $this->assertFalse($repository->update(self::TABLE, self::PK, $id, ['quantity' => '1']));
        $this->assertFalse($repository->update(self::TABLE, self::PK, $id, ['quantity' => '1'], ['status' => 'open']));
    }

    #[DataProvider('dialects')]
    public function testCheckRunsInsideTheOpenTransaction(string $dialect): void
    {
        [$repository, $pdo, $id] = $this->seeded($dialect);
        $connection = $this->pool($dialect)->getWriter();

        $result = (new RunOwnedTransaction())(
            $connection,
            function () use ($repository, $pdo, $id): array {
                $hit = $repository->update(self::TABLE, self::PK, $id, ['quantity' => '1'], ['quantity' => '1.00']);
                $stillOpen = $pdo->inTransaction();
                $conflict = $repository->update(self::TABLE, self::PK, $id, ['quantity' => '1'], ['status' => 'closed']);

                return [$hit, $stillOpen, $conflict, $pdo->inTransaction()];
            }
        );

        $this->assertNull($result->failure);
        $this->assertSame([true, true, false, true], $result->result);
        $this->assertFalse($pdo->inTransaction());
    }

    public function testPositiveRowCountRunsNoFurtherStatement(): void
    {
        [$repository, $counting, $id] = $this->countingMariadb();

        $this->assertTrue($repository->update(self::TABLE, self::PK, $id, ['status' => 'paid'], ['status' => 'open']));
        $this->assertSame(1, $counting->prepared);
    }

    public function testZeroRowCountAddsExactlyOneCheckStatement(): void
    {
        [$repository, $counting, $id] = $this->countingMariadb();

        $this->assertTrue($repository->update(self::TABLE, self::PK, $id, ['quantity' => '1']));
        $this->assertSame(2, $counting->prepared);
    }

    /**
     * REPEATABLE READ: a plain SELECT reads the transaction's snapshot, the UPDATE's current read sees the row
     * another connection changed and committed since. The check must not report a hit from the stale snapshot.
     */
    public function testRowChangedByPeerAfterSnapshotIsStillAConflict(): void
    {
        [$repository, $pdo, $id] = $this->seeded('mariadb');
        try {
            $pdo->exec('SET SESSION innodb_snapshot_isolation=0');
        } catch (\PDOException) {
            // server without the variable: no snapshot conflict mode to switch off
        }
        $peer = (new ConnectionFactory())->mysql(
            host: $_ENV['MARIADB_HOST'] ?? 'mariadb',
            user: $_ENV['MARIADB_USER'] ?? 'test_user',
            password: $_ENV['MARIADB_PASSWORD'] ?? 'test_password',
            database: $_ENV['MARIADB_DATABASE'] ?? 'test_db',
            port: (int) ($_ENV['MARIADB_PORT'] ?? 3306),
        )->pdo();

        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        try {
            $pdo->query('SELECT status FROM ' . self::TABLE . ' WHERE id = ' . $id)->fetchColumn();
            $peer->exec("UPDATE " . self::TABLE . " SET status = 'closed' WHERE id = " . $id);

            $this->assertFalse(
                $repository->update(self::TABLE, self::PK, $id, ['quantity' => '1'], ['status' => 'open'])
            );
        } finally {
            $pdo->rollBack();
        }
    }

    /**
     * @return array{Repository, object{prepared: int}&PDO, int}
     */
    private function countingMariadb(): array
    {
        [, , $id] = $this->seeded('mariadb');

        $counting = new class (
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $_ENV['MARIADB_HOST'] ?? 'mariadb',
                (int) ($_ENV['MARIADB_PORT'] ?? 3306),
                $_ENV['MARIADB_DATABASE'] ?? 'test_db'
            ),
            $_ENV['MARIADB_USER'] ?? 'test_user',
            $_ENV['MARIADB_PASSWORD'] ?? 'test_password',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        ) extends PDO {
            public int $prepared = 0;

            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                $this->prepared++;

                return parent::prepare($query, $options);
            }
        };

        return [new Repository($counting), $counting, $id];
    }
}
