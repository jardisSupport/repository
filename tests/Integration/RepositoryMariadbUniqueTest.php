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
 * UniqueViolationException columns/table against MariaDB (service `mariadb` from
 * support/docker-compose.yml). MariaDB reports the bare index name in error 1062
 * (MySQL 8 prefixes the table), the information_schema lookup must work for both.
 */
final class RepositoryMariadbUniqueTest extends TestCase
{
    private const TABLE_SINGLE = 'test_mariadb_unique_single';
    private const TABLE_PAIR = 'test_mariadb_unique_pair';
    private const PK = 'id';

    private static ConnectionPoolInterface $pool;
    private static PDO $pdo;
    private Repository $repository;

    public static function setUpBeforeClass(): void
    {
        $writer = (new ConnectionFactory())->mysql(
            host: $_ENV['MARIADB_HOST'] ?? 'mariadb',
            user: $_ENV['MARIADB_USER'] ?? 'test_user',
            password: $_ENV['MARIADB_PASSWORD'] ?? 'test_password',
            database: $_ENV['MARIADB_DATABASE'] ?? 'test_db',
            port: (int) ($_ENV['MARIADB_PORT'] ?? 3306),
        );

        self::$pool = new ConnectionPool(writer: $writer, readers: []);
        self::$pdo = self::$pool->getWriter()->pdo();

        self::$pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE_SINGLE);
        self::$pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE_PAIR);
        self::$pdo->exec('
            CREATE TABLE ' . self::TABLE_SINGLE . ' (
                id INT AUTO_INCREMENT PRIMARY KEY,
                offer_id INT NOT NULL,
                UNIQUE KEY uq_orders_offer_id (offer_id)
            )
        ');
        self::$pdo->exec('
            CREATE TABLE ' . self::TABLE_PAIR . ' (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT NOT NULL,
                number INT NOT NULL,
                UNIQUE KEY uq_pair (tenant_id, number)
            )
        ');
    }

    public static function tearDownAfterClass(): void
    {
        self::$pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE_SINGLE);
        self::$pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE_PAIR);
    }

    protected function setUp(): void
    {
        self::$pdo->exec('TRUNCATE TABLE ' . self::TABLE_SINGLE);
        self::$pdo->exec('TRUNCATE TABLE ' . self::TABLE_PAIR);
        $this->repository = new Repository(self::$pool);
    }

    public function testInsertViolationCarriesSingleColumnAndTable(): void
    {
        $this->repository->insert(self::TABLE_SINGLE, self::PK, ['offer_id' => 5]);

        try {
            $this->repository->insert(self::TABLE_SINGLE, self::PK, ['offer_id' => 5]);
            $this->fail('UniqueViolationException expected');
        } catch (UniqueViolationException $e) {
            $this->assertSame(['offer_id'], $e->getColumns());
            $this->assertSame(self::TABLE_SINGLE, $e->getTable());
            $this->assertSame('uq_orders_offer_id', $e->getConstraint());
            $this->assertInstanceOf(\PDOException::class, $e->getPrevious());
        }
    }

    public function testInsertViolationCarriesCompleteTwoColumnIndexInOrder(): void
    {
        $this->repository->insert(self::TABLE_PAIR, self::PK, ['tenant_id' => 1, 'number' => 7]);

        try {
            $this->repository->insert(self::TABLE_PAIR, self::PK, ['tenant_id' => 1, 'number' => 7]);
            $this->fail('UniqueViolationException expected');
        } catch (UniqueViolationException $e) {
            $this->assertSame(['tenant_id', 'number'], $e->getColumns());
            $this->assertSame(self::TABLE_PAIR, $e->getTable());
        }
    }

    public function testIntegerPkStrategyViolationCarriesColumns(): void
    {
        $this->repository->insert(self::TABLE_SINGLE, self::PK, ['offer_id' => 5], PkStrategy::INTEGER);

        try {
            $this->repository->insert(self::TABLE_SINGLE, self::PK, ['offer_id' => 5], PkStrategy::INTEGER);
            $this->fail('UniqueViolationException expected');
        } catch (UniqueViolationException $e) {
            $this->assertSame(['offer_id'], $e->getColumns());
        }
    }

    public function testUpdateOntoForeignUniqueValueCarriesColumnsAndTable(): void
    {
        $this->repository->insert(self::TABLE_SINGLE, self::PK, ['offer_id' => 5]);
        $id = $this->repository->insert(self::TABLE_SINGLE, self::PK, ['offer_id' => 6]);

        try {
            $this->repository->update(self::TABLE_SINGLE, self::PK, $id, ['offer_id' => 5]);
            $this->fail('UniqueViolationException expected');
        } catch (UniqueViolationException $e) {
            $this->assertSame(['offer_id'], $e->getColumns());
            $this->assertSame(self::TABLE_SINGLE, $e->getTable());
        }
    }

    public function testUpdateOntoForeignTwoColumnValueCarriesCompleteIndex(): void
    {
        $this->repository->insert(self::TABLE_PAIR, self::PK, ['tenant_id' => 1, 'number' => 7]);
        $id = $this->repository->insert(self::TABLE_PAIR, self::PK, ['tenant_id' => 1, 'number' => 8]);

        try {
            $this->repository->update(self::TABLE_PAIR, self::PK, $id, ['number' => 7]);
            $this->fail('UniqueViolationException expected');
        } catch (UniqueViolationException $e) {
            $this->assertSame(['tenant_id', 'number'], $e->getColumns());
            $this->assertSame(self::TABLE_PAIR, $e->getTable());
        }
    }

    public function testTransactionStaysUsableAfterViolationAndColumnsAreFilled(): void
    {
        $second = 0;
        self::$pdo->beginTransaction();
        try {
            $this->repository->insert(self::TABLE_PAIR, self::PK, ['tenant_id' => 1, 'number' => 7]);

            try {
                $this->repository->insert(self::TABLE_PAIR, self::PK, ['tenant_id' => 1, 'number' => 7]);
                $this->fail('UniqueViolationException expected');
            } catch (UniqueViolationException $e) {
                $this->assertSame(['tenant_id', 'number'], $e->getColumns());
            }

            $this->assertTrue(self::$pdo->inTransaction());
            $second = $this->repository->insert(self::TABLE_PAIR, self::PK, ['tenant_id' => 1, 'number' => 8]);
            self::$pdo->commit();
        } catch (\Throwable $t) {
            if (self::$pdo->inTransaction()) {
                self::$pdo->rollBack();
            }
            throw $t;
        }

        // auto-increment values burned by the failed insert are not reused: assert on the returned id
        $this->assertTrue($this->repository->exists(self::TABLE_PAIR, self::PK, $second));
        $this->assertSame(2, (int) self::$pdo->query('SELECT COUNT(*) FROM ' . self::TABLE_PAIR)->fetchColumn());
    }
}
