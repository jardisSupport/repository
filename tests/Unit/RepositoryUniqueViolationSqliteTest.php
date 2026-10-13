<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Tests\Unit;

use JardisAdapter\DbConnection\ConnectionPool;
use JardisAdapter\DbConnection\Factory\ConnectionFactory;
use JardisSupport\Contract\Repository\Exception\PersistException;
use JardisSupport\Contract\Repository\Exception\UniqueViolationException;
use JardisSupport\Contract\Repository\PrimaryKey\PkStrategy;
use JardisSupport\Repository\Repository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * UniqueViolationException against SQLite via the adapter path (no Docker service needed).
 */
final class RepositoryUniqueViolationSqliteTest extends TestCase
{
    private PDO $pdo;
    private Repository $repository;

    protected function setUp(): void
    {
        $connection = (new ConnectionFactory())->sqlite(':memory:');
        $this->pdo = $connection->pdo();
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL)');
        $this->pdo->exec('CREATE UNIQUE INDEX uniq_email ON users (email)');
        $this->pdo->exec('CREATE TABLE manual (id INTEGER PRIMARY KEY, email TEXT NOT NULL UNIQUE)');
        $this->pdo->exec(
            'CREATE TABLE pairs (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, '
            . 'number INTEGER NOT NULL, UNIQUE (tenant_id, number))'
        );
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('CREATE TABLE parents (id INTEGER PRIMARY KEY)');
        $this->pdo->exec(
            'CREATE TABLE children (id INTEGER PRIMARY KEY AUTOINCREMENT, parent_id INTEGER NOT NULL, '
            . 'FOREIGN KEY (parent_id) REFERENCES parents(id))'
        );

        $this->repository = new Repository(new ConnectionPool(writer: $connection, readers: []));
    }

    public function testAutoincrementDuplicateThrowsUniqueViolation(): void
    {
        $this->repository->insert('users', 'id', ['email' => 'a@x.de']);

        try {
            $this->repository->insert('users', 'id', ['email' => 'a@x.de']);
            $this->fail('UniqueViolationException expected');
        } catch (UniqueViolationException $e) {
            $this->assertSame('users.email', $e->getConstraint());
            $this->assertInstanceOf(PersistException::class, $e);
        }
    }

    public function testProvidedPkDuplicateThrowsUniqueViolation(): void
    {
        $this->repository->insert('manual', 'id', ['id' => 1, 'email' => 'a@x.de'], PkStrategy::NONE);

        $this->expectException(UniqueViolationException::class);
        $this->repository->insert('manual', 'id', ['id' => 2, 'email' => 'a@x.de'], PkStrategy::NONE);
    }

    public function testIntegerPkForeignUniqueViolationThrowsWithoutRetry(): void
    {
        $this->repository->insert('manual', 'id', ['email' => 'a@x.de'], PkStrategy::INTEGER);

        try {
            $this->repository->insert('manual', 'id', ['email' => 'a@x.de'], PkStrategy::INTEGER);
            $this->fail('UniqueViolationException expected');
        } catch (UniqueViolationException $e) {
            $this->assertSame('manual.email', $e->getConstraint());
        }

        $next = $this->repository->insert('manual', 'id', ['email' => 'b@x.de'], PkStrategy::INTEGER);
        $this->assertSame(2, $next);
    }

    public function testUpdateOntoForeignUniqueValueThrowsUniqueViolation(): void
    {
        $this->repository->insert('users', 'id', ['email' => 'a@x.de']);
        $id = $this->repository->insert('users', 'id', ['email' => 'b@x.de']);

        $this->expectException(UniqueViolationException::class);
        $this->repository->update('users', 'id', $id, ['email' => 'a@x.de']);
    }

    public function testForeignKeyViolationIsPlainPersistException(): void
    {
        try {
            $this->repository->insert('children', 'id', ['parent_id' => 999]);
            $this->fail('PersistException expected');
        } catch (PersistException $e) {
            $this->assertNotInstanceOf(UniqueViolationException::class, $e);
        }
    }

    public function testInsertViolationCarriesSingleColumnAndTable(): void
    {
        $this->repository->insert('users', 'id', ['email' => 'a@x.de']);

        try {
            $this->repository->insert('users', 'id', ['email' => 'a@x.de']);
            $this->fail('UniqueViolationException expected');
        } catch (UniqueViolationException $e) {
            $this->assertSame(['email'], $e->getColumns());
            $this->assertSame('users', $e->getTable());
            $this->assertInstanceOf(\PDOException::class, $e->getPrevious());
        }
    }

    public function testInsertViolationCarriesCompleteTwoColumnIndexInOrder(): void
    {
        $this->repository->insert('pairs', 'id', ['tenant_id' => 1, 'number' => 7]);

        try {
            $this->repository->insert('pairs', 'id', ['tenant_id' => 1, 'number' => 7]);
            $this->fail('UniqueViolationException expected');
        } catch (UniqueViolationException $e) {
            $this->assertSame(['tenant_id', 'number'], $e->getColumns());
            $this->assertSame('pairs', $e->getTable());
        }
    }

    public function testUpdateViolationCarriesColumnsAndTable(): void
    {
        $this->repository->insert('pairs', 'id', ['tenant_id' => 1, 'number' => 7]);
        $id = $this->repository->insert('pairs', 'id', ['tenant_id' => 1, 'number' => 8]);

        try {
            $this->repository->update('pairs', 'id', $id, ['number' => 7]);
            $this->fail('UniqueViolationException expected');
        } catch (UniqueViolationException $e) {
            $this->assertSame(['tenant_id', 'number'], $e->getColumns());
            $this->assertSame('pairs', $e->getTable());
        }

        $this->repository->insert('users', 'id', ['email' => 'a@x.de']);
        $other = $this->repository->insert('users', 'id', ['email' => 'b@x.de']);
        try {
            $this->repository->update('users', 'id', $other, ['email' => 'a@x.de']);
            $this->fail('UniqueViolationException expected');
        } catch (UniqueViolationException $e) {
            $this->assertSame(['email'], $e->getColumns());
            $this->assertSame('users', $e->getTable());
        }
    }

    public function testPrimaryKeyViolationCarriesPkColumn(): void
    {
        $this->repository->insert('manual', 'id', ['id' => 1, 'email' => 'a@x.de'], PkStrategy::NONE);

        try {
            $this->repository->insert('manual', 'id', ['id' => 1, 'email' => 'b@x.de'], PkStrategy::NONE);
            $this->fail('UniqueViolationException expected');
        } catch (UniqueViolationException $e) {
            $this->assertSame(['id'], $e->getColumns());
            $this->assertSame('manual', $e->getTable());
        }
    }
}
