<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Tests\Unit\Handler;

use JardisSupport\Repository\Handler\DetectUniqueViolation;
use PDOException;
use PHPUnit\Framework\TestCase;

final class DetectUniqueViolationTest extends TestCase
{
    private DetectUniqueViolation $detect;

    protected function setUp(): void
    {
        $this->detect = new DetectUniqueViolation();
    }

    /**
     * @param array<int, mixed>|null $errorInfo
     */
    private function exception(string $message, ?array $errorInfo): PDOException
    {
        $e = new PDOException($message);
        $e->errorInfo = $errorInfo;

        return $e;
    }

    public function testMysql8ConstraintNameStripsTablePrefix(): void
    {
        $e = $this->exception('x', ['23000', 1062, "Duplicate entry 'a' for key 'users.uniq_email'"]);

        $this->assertSame('uniq_email', ($this->detect)($e, 'mysql'));
    }

    public function testMysqlPrimaryKey(): void
    {
        $e = $this->exception('x', ['23000', 1062, "Duplicate entry '1' for key 'PRIMARY'"]);

        $this->assertSame('PRIMARY', ($this->detect)($e, 'mysql'));
    }

    public function testMariadbConstraintNameWithoutPrefix(): void
    {
        $e = $this->exception('x', ['23000', 1062, "Duplicate entry 'a' for key 'uniq_email'"]);

        $this->assertSame('uniq_email', ($this->detect)($e, 'mariadb'));
    }

    public function testMysqlDuplicateWithoutReadableNameReturnsEmptyString(): void
    {
        $e = $this->exception('x', ['23000', 1062, 'Duplicate entry']);

        $this->assertSame('', ($this->detect)($e, 'mysql'));
    }

    public function testMysqlForeignKeyViolationIsNotUnique(): void
    {
        $e = $this->exception('x', ['23000', 1452, 'Cannot add or update a child row: a foreign key constraint fails']);

        $this->assertNull(($this->detect)($e, 'mysql'));
        $this->assertNull(($this->detect)($e, 'mariadb'));
    }

    public function testPostgresUniqueViolation(): void
    {
        $e = $this->exception('x', [
            '23505',
            7,
            'ERROR: duplicate key value violates unique constraint "uniq_email"',
        ]);

        $this->assertSame('uniq_email', ($this->detect)($e, 'postgres'));
    }

    public function testPostgresDuplicateWithoutReadableNameReturnsEmptyString(): void
    {
        $e = $this->exception('x', ['23505', 7, 'ERROR: duplicate']);

        $this->assertSame('', ($this->detect)($e, 'postgres'));
    }

    public function testPostgresForeignKeyViolationIsNotUnique(): void
    {
        $e = $this->exception('x', ['23503', 7, 'ERROR: insert violates foreign key constraint "fk_x"']);

        $this->assertNull(($this->detect)($e, 'postgres'));
    }

    public function testSqliteSingleColumn(): void
    {
        $e = $this->exception('SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: t.email', [
            '23000',
            19,
            'UNIQUE constraint failed: t.email',
        ]);

        $this->assertSame('t.email', ($this->detect)($e, 'sqlite'));
    }

    public function testSqliteMultiColumnFromMessageOnly(): void
    {
        $e = $this->exception('SQLSTATE[23000]: 19 UNIQUE constraint failed: t.a, t.b', null);

        $this->assertSame('t.a, t.b', ($this->detect)($e, 'sqlite'));
    }

    public function testSqliteForeignKeyViolationIsNotUnique(): void
    {
        $e = $this->exception('FOREIGN KEY constraint failed', ['23000', 19, 'FOREIGN KEY constraint failed']);

        $this->assertNull(($this->detect)($e, 'sqlite'));
    }

    public function testGenericSqlstate23000WithDuplicateWordIsNotEnough(): void
    {
        $e = $this->exception('Duplicate something', ['23000', 1451, 'Duplicate something']);

        $this->assertNull(($this->detect)($e, 'mysql'));
    }

    public function testUnknownDialectReturnsNull(): void
    {
        $e = $this->exception('x', ['23505', 7, 'unique constraint "a"']);

        $this->assertNull(($this->detect)($e, 'oracle'));
    }
}
