<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Tests\Unit;

use JardisSupport\Contract\Repository\Exception\PersistException;
use JardisSupport\DbQuery\DbQuery;
use JardisSupport\Repository\Repository;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoRepositoryTest extends TestCase
{
    private const TABLE = 'items';
    private const PK = 'id';

    private PDO $pdo;
    private Repository $repository;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $this->pdo->exec('
            CREATE TABLE items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT,
                status TEXT DEFAULT \'active\'
            )
        ');

        $this->repository = new Repository($this->pdo);
    }

    // ── INSERT ──────────────────────────────────────────────────────

    public function testInsertReturnsGeneratedId(): void
    {
        $id = $this->repository->insert(self::TABLE, self::PK, ['name' => 'Alice']);

        $this->assertSame(1, $id);
    }

    public function testInsertedDataIsPersisted(): void
    {
        $id = $this->repository->insert(
            self::TABLE,
            self::PK,
            ['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active'],
        );

        $row = $this->repository->findById(self::TABLE, self::PK, $id);

        $this->assertNotNull($row);
        $this->assertSame('Alice', $row['name']);
        $this->assertSame('alice@example.com', $row['email']);
        $this->assertSame('active', $row['status']);
    }

    public function testInsertMultipleRecords(): void
    {
        $id1 = $this->repository->insert(self::TABLE, self::PK, ['name' => 'Alice']);
        $id2 = $this->repository->insert(self::TABLE, self::PK, ['name' => 'Bob']);

        $this->assertSame(1, $id1);
        $this->assertSame(2, $id2);
    }

    // ── UPDATE ──────────────────────────────────────────────────────

    public function testUpdateModifiesRecord(): void
    {
        $id = $this->repository->insert(
            self::TABLE,
            self::PK,
            ['name' => 'Alice', 'email' => 'old@example.com'],
        );

        $result = $this->repository->update(
            self::TABLE,
            self::PK,
            $id,
            ['name' => 'Alice Updated', 'email' => 'new@example.com'],
        );

        $this->assertTrue($result);

        $row = $this->repository->findById(self::TABLE, self::PK, $id);
        $this->assertSame('Alice Updated', $row['name']);
        $this->assertSame('new@example.com', $row['email']);
    }

    // ── DELETE ───────────────────────────────────────────────────────

    public function testDeleteRemovesRecord(): void
    {
        $id = $this->repository->insert(self::TABLE, self::PK, ['name' => 'Alice']);

        $result = $this->repository->delete(self::TABLE, self::PK, $id);

        $this->assertTrue($result);
        $this->assertNull($this->repository->findById(self::TABLE, self::PK, $id));
    }

    public function testDeleteAllRemovesMultipleRecords(): void
    {
        $id1 = $this->repository->insert(self::TABLE, self::PK, ['name' => 'Alice']);
        $id2 = $this->repository->insert(self::TABLE, self::PK, ['name' => 'Bob']);
        $id3 = $this->repository->insert(self::TABLE, self::PK, ['name' => 'Charlie']);

        $this->repository->deleteAll(self::TABLE, self::PK, [$id1, $id3]);

        $this->assertNull($this->repository->findById(self::TABLE, self::PK, $id1));
        $this->assertNotNull($this->repository->findById(self::TABLE, self::PK, $id2));
        $this->assertNull($this->repository->findById(self::TABLE, self::PK, $id3));
    }

    // ── FIND BY ID ──────────────────────────────────────────────────

    public function testFindByIdReturnsNullForNonExistent(): void
    {
        $this->assertNull($this->repository->findById(self::TABLE, self::PK, 9999));
    }

    // ── FIND BY QUERY ───────────────────────────────────────────────

    public function testFindByQueryReturnsMatchingRecords(): void
    {
        $this->repository->insert(self::TABLE, self::PK, ['name' => 'Alice', 'status' => 'active']);
        $this->repository->insert(self::TABLE, self::PK, ['name' => 'Bob', 'status' => 'inactive']);
        $this->repository->insert(self::TABLE, self::PK, ['name' => 'Charlie', 'status' => 'active']);

        $query = (new DbQuery())
            ->select('*')
            ->from(self::TABLE)
            ->where('status')->equals('active');

        $rows = $this->repository->findByQuery($query);

        $this->assertCount(2, $rows);
        $this->assertSame('Alice', $rows[0]['name']);
        $this->assertSame('Charlie', $rows[1]['name']);
    }

    // ── EXISTS ──────────────────────────────────────────────────────

    public function testExistsReturnsTrueForExistingRecord(): void
    {
        $id = $this->repository->insert(self::TABLE, self::PK, ['name' => 'Alice']);

        $this->assertTrue($this->repository->exists(self::TABLE, self::PK, $id));
    }

    public function testExistsReturnsFalseForNonExistent(): void
    {
        $this->assertFalse($this->repository->exists(self::TABLE, self::PK, 9999));
    }

    // ── PERSIST EXCEPTION ────────────────────────────────────────────

    public function testUpdateThrowsPersistExceptionOnInvalidColumn(): void
    {
        $id = $this->repository->insert(self::TABLE, self::PK, ['name' => 'Alice']);

        $this->expectException(PersistException::class);

        $this->repository->update(self::TABLE, self::PK, $id, ['nonexistent_column' => 'value']);
    }

    public function testDeleteThrowsPersistExceptionOnInvalidTable(): void
    {
        $this->expectException(PersistException::class);

        $this->repository->delete('nonexistent_table', self::PK, 1);
    }

    public function testDeleteAllThrowsPersistExceptionOnInvalidTable(): void
    {
        $this->expectException(PersistException::class);

        $this->repository->deleteAll('nonexistent_table', self::PK, [1, 2]);
    }

    public function testUpdateReturnsFalseWhenNoRowAffected(): void
    {
        $result = $this->repository->update(self::TABLE, self::PK, 9999, ['name' => 'Ghost']);

        $this->assertFalse($result);
    }

    public function testDeleteReturnsFalseWhenNoRowAffected(): void
    {
        $result = $this->repository->delete(self::TABLE, self::PK, 9999);

        $this->assertFalse($result);
    }

    public function testUpdateWithEmptyValuesReturnsTrueWithoutQuery(): void
    {
        $id = $this->repository->insert(self::TABLE, self::PK, ['name' => 'Alice']);

        $result = $this->repository->update(self::TABLE, self::PK, $id, []);

        $this->assertTrue($result);
    }

    public function testDeleteAllWithEmptyIdsIsNoOp(): void
    {
        $this->repository->insert(self::TABLE, self::PK, ['name' => 'Alice']);

        $this->repository->deleteAll(self::TABLE, self::PK, []);

        $query = (new DbQuery())->select('COUNT(*) as cnt')->from(self::TABLE);
        $rows = $this->repository->findByQuery($query);
        $this->assertSame(1, (int) $rows[0]['cnt']);
    }
}
