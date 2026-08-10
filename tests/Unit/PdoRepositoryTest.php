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
                status TEXT DEFAULT \'active\',
                active INTEGER
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

    // ── CONDITIONAL UPDATE (expected) ──────────────────────────────

    public function testUpdateWithMatchingExpectedReturnsTrueAndUpdatesRow(): void
    {
        $id = $this->repository->insert(self::TABLE, self::PK, ['name' => 'Alice', 'status' => 'active']);

        $result = $this->repository->update(
            self::TABLE,
            self::PK,
            $id,
            ['name' => 'Alice Updated'],
            ['status' => 'active'],
        );

        $this->assertTrue($result);
        $row = $this->repository->findById(self::TABLE, self::PK, $id);
        $this->assertSame('Alice Updated', $row['name']);
    }

    public function testUpdateWithStaleExpectedReturnsFalseAndLeavesRowUnchanged(): void
    {
        $id = $this->repository->insert(self::TABLE, self::PK, ['name' => 'Alice', 'status' => 'active']);

        $result = $this->repository->update(
            self::TABLE,
            self::PK,
            $id,
            ['name' => 'Alice Updated'],
            ['status' => 'stale-value'],
        );

        $this->assertFalse($result);
        $row = $this->repository->findById(self::TABLE, self::PK, $id);
        $this->assertSame('Alice', $row['name']);
    }

    // U4: null-Erwartung in beide Richtungen
    public function testUpdateWithNullExpectedMatchesNullColumn(): void
    {
        $id = $this->repository->insert(self::TABLE, self::PK, ['name' => 'Alice']);

        $result = $this->repository->update(
            self::TABLE,
            self::PK,
            $id,
            ['name' => 'Alice Updated'],
            ['email' => null],
        );

        $this->assertTrue($result);
    }

    public function testUpdateWithNullExpectedDoesNotMatchNonNullColumn(): void
    {
        $id = $this->repository->insert(self::TABLE, self::PK, ['name' => 'Alice', 'email' => 'alice@example.com']);

        $result = $this->repository->update(
            self::TABLE,
            self::PK,
            $id,
            ['name' => 'Alice Updated'],
            ['email' => null],
        );

        $this->assertFalse($result);
    }

    // U5: Mehrfeld-Erwartung
    public function testUpdateWithMultiFieldExpectedMatchesAllColumns(): void
    {
        $id = $this->repository->insert(
            self::TABLE,
            self::PK,
            ['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active'],
        );

        $result = $this->repository->update(
            self::TABLE,
            self::PK,
            $id,
            ['name' => 'Alice Updated'],
            ['email' => 'alice@example.com', 'status' => 'active'],
        );

        $this->assertTrue($result);
    }

    public function testUpdateWithMultiFieldExpectedFailsWhenOneFieldMismatches(): void
    {
        $id = $this->repository->insert(
            self::TABLE,
            self::PK,
            ['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active'],
        );

        $result = $this->repository->update(
            self::TABLE,
            self::PK,
            $id,
            ['name' => 'Alice Updated'],
            ['email' => 'alice@example.com', 'status' => 'inactive'],
        );

        $this->assertFalse($result);
        $row = $this->repository->findById(self::TABLE, self::PK, $id);
        $this->assertSame('Alice', $row['name']);
    }

    // ── CONDITIONAL DELETE (expected) ──────────────────────────────

    public function testDeleteWithMatchingExpectedReturnsTrueAndRemovesRow(): void
    {
        $id = $this->repository->insert(self::TABLE, self::PK, ['name' => 'Alice', 'status' => 'active']);

        $result = $this->repository->delete(self::TABLE, self::PK, $id, ['status' => 'active']);

        $this->assertTrue($result);
        $this->assertNull($this->repository->findById(self::TABLE, self::PK, $id));
    }

    public function testDeleteWithStaleExpectedReturnsFalseAndLeavesRowInPlace(): void
    {
        $id = $this->repository->insert(self::TABLE, self::PK, ['name' => 'Alice', 'status' => 'active']);

        $result = $this->repository->delete(self::TABLE, self::PK, $id, ['status' => 'stale-value']);

        $this->assertFalse($result);
        $this->assertNotNull($this->repository->findById(self::TABLE, self::PK, $id));
    }

    public function testDeleteWithNullExpectedMatchesNullColumn(): void
    {
        $id = $this->repository->insert(self::TABLE, self::PK, ['name' => 'Alice']);

        $result = $this->repository->delete(self::TABLE, self::PK, $id, ['email' => null]);

        $this->assertTrue($result);
        $this->assertNull($this->repository->findById(self::TABLE, self::PK, $id));
    }

    // ── VERTRAGSGRENZEN (Blocker-Befunde) ──────────────────────────

    // U9: leeres $values + gesetztes $expected
    public function testUpdateWithEmptyValuesAndNonEmptyExpectedThrows(): void
    {
        $id = $this->repository->insert(self::TABLE, self::PK, ['name' => 'Alice', 'status' => 'active']);

        $this->expectException(\InvalidArgumentException::class);

        $this->repository->update(self::TABLE, self::PK, $id, [], ['status' => 'active']);
    }

    public function testUpdateWithEmptyValuesAndEmptyExpectedStillReturnsTrue(): void
    {
        $id = $this->repository->insert(self::TABLE, self::PK, ['name' => 'Alice']);

        $result = $this->repository->update(self::TABLE, self::PK, $id, [], []);

        $this->assertTrue($result);
    }

    // U10: nur scalar|null in $expected
    public function testUpdateWithObjectExpectedValueThrows(): void
    {
        $id = $this->repository->insert(self::TABLE, self::PK, ['name' => 'Alice']);

        $this->expectException(\InvalidArgumentException::class);

        $this->repository->update(self::TABLE, self::PK, $id, ['name' => 'Bob'], ['status' => new \DateTime()]);
    }

    public function testUpdateWithArrayExpectedValueThrows(): void
    {
        $id = $this->repository->insert(self::TABLE, self::PK, ['name' => 'Alice']);

        $this->expectException(\InvalidArgumentException::class);

        $this->repository->update(self::TABLE, self::PK, $id, ['name' => 'Bob'], ['status' => ['active']]);
    }

    public function testDeleteWithObjectExpectedValueThrows(): void
    {
        $id = $this->repository->insert(self::TABLE, self::PK, ['name' => 'Alice']);

        $this->expectException(\InvalidArgumentException::class);

        $this->repository->delete(self::TABLE, self::PK, $id, ['status' => new \DateTime()]);
    }

    // U11: leerer String vs. NULL sind verschiedene Erwartungen
    public function testUpdateWithEmptyStringExpectedDoesNotMatchNullColumn(): void
    {
        $id = $this->repository->insert(self::TABLE, self::PK, ['name' => 'Alice', 'email' => null]);

        $result = $this->repository->update(
            self::TABLE,
            self::PK,
            $id,
            ['name' => 'Alice Updated'],
            ['email' => ''],
        );

        $this->assertFalse($result);
    }

    public function testUpdateWithNullExpectedDoesNotMatchEmptyStringColumn(): void
    {
        $id = $this->repository->insert(self::TABLE, self::PK, ['name' => 'Alice', 'email' => '']);

        $result = $this->repository->update(
            self::TABLE,
            self::PK,
            $id,
            ['name' => 'Alice Updated'],
            ['email' => null],
        );

        $this->assertFalse($result);
    }

    public function testUpdateWithEmptyStringExpectedMatchesEmptyStringColumn(): void
    {
        $id = $this->repository->insert(self::TABLE, self::PK, ['name' => 'Alice', 'email' => '']);

        $result = $this->repository->update(
            self::TABLE,
            self::PK,
            $id,
            ['name' => 'Alice Updated'],
            ['email' => ''],
        );

        $this->assertTrue($result);
    }

    // U12: Treiber-Verhalten gepinnt (SQLite), nicht versprochen
    public function testUpdateWithStringNumericExpectedMatchesIntegerColumn(): void
    {
        $id = $this->repository->insert(self::TABLE, self::PK, ['name' => 'Alice']);

        $result = $this->repository->update(
            self::TABLE,
            self::PK,
            $id,
            ['name' => 'Alice Updated'],
            ['id' => (string) $id],
        );

        $this->assertTrue($result);
    }

    public function testUpdateWithBooleanExpectedMatchesBooleanColumnParityWithValues(): void
    {
        $id = $this->repository->insert(self::TABLE, self::PK, ['name' => 'Alice', 'active' => true]);

        $result = $this->repository->update(
            self::TABLE,
            self::PK,
            $id,
            ['name' => 'Alice Updated'],
            ['active' => true],
        );

        $this->assertTrue($result);
    }

    public function testUpdateWithBooleanExpectedDoesNotMatchOppositeBooleanColumn(): void
    {
        $id = $this->repository->insert(self::TABLE, self::PK, ['name' => 'Alice', 'active' => true]);

        $result = $this->repository->update(
            self::TABLE,
            self::PK,
            $id,
            ['name' => 'Alice Updated'],
            ['active' => false],
        );

        $this->assertFalse($result);
    }

    // U13: Erwartung auf nicht existierende Spalte -> PersistException
    public function testUpdateWithExpectedOnNonexistentColumnThrowsPersistException(): void
    {
        $id = $this->repository->insert(self::TABLE, self::PK, ['name' => 'Alice']);

        $this->expectException(PersistException::class);

        $this->repository->update(
            self::TABLE,
            self::PK,
            $id,
            ['name' => 'Alice Updated'],
            ['nonexistent_column' => 'value'],
        );
    }

    public function testDeleteWithExpectedOnNonexistentColumnThrowsPersistException(): void
    {
        $id = $this->repository->insert(self::TABLE, self::PK, ['name' => 'Alice']);

        $this->expectException(PersistException::class);

        $this->repository->delete(self::TABLE, self::PK, $id, ['nonexistent_column' => 'value']);
    }
}
