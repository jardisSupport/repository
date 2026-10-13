<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Tests\Unit\Handler;

use JardisSupport\Repository\Handler\DetectTransientConflict;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DetectTransientConflictTest extends TestCase
{
    private DetectTransientConflict $detect;

    protected function setUp(): void
    {
        $this->detect = new DetectTransientConflict();
    }

    /**
     * @param array<int, mixed>|null $errorInfo
     */
    private function exception(?array $errorInfo): PDOException
    {
        $e = new PDOException('x');
        $e->errorInfo = $errorInfo;

        return $e;
    }

    /**
     * @return iterable<string, array{string, array<int, mixed>, string}>
     */
    public static function transientProvider(): iterable
    {
        yield 'mariadb 1020 snapshot conflict' => ['mariadb', ['HY000', 1020, 'Record has changed since last read'], '1020'];
        yield 'mysql 1020' => ['mysql', ['HY000', 1020, 'Record has changed since last read'], '1020'];
        yield 'mysql 1213 deadlock' => ['mysql', ['40001', 1213, 'Deadlock found when trying to get lock'], '1213'];
        yield 'mariadb 1213 deadlock' => ['mariadb', ['40001', 1213, 'Deadlock found'], '1213'];
        yield 'mysql code as text' => ['mysql', ['HY000', '1020', 'x'], '1020'];
        yield 'postgres 40001 serialization failure' => ['postgres', ['40001', 7, 'could not serialize access'], '40001'];
        yield 'postgres 40P01 deadlock' => ['postgres', ['40P01', 7, 'deadlock detected'], '40P01'];
    }

    /**
     * @param array<int, mixed> $errorInfo
     */
    #[DataProvider('transientProvider')]
    public function testTransientCodesAreReturnedAsText(string $dialect, array $errorInfo, string $expected): void
    {
        $this->assertSame($expected, ($this->detect)($this->exception($errorInfo), $dialect));
    }

    /**
     * @return iterable<string, array{string, array<int, mixed>}>
     */
    public static function nonTransientProvider(): iterable
    {
        yield 'mysql duplicate 1062' => ['mysql', ['23000', 1062, 'Duplicate entry']];
        yield 'mariadb foreign key 1451' => ['mariadb', ['23000', 1451, 'Cannot delete or update a parent row']];
        yield 'mysql syntax error 1064' => ['mysql', ['42000', 1064, 'You have an error in your SQL syntax']];
        yield 'mysql lock wait timeout 1205 stays technical' => ['mysql', ['HY000', 1205, 'Lock wait timeout exceeded']];
        yield 'mysql sqlstate 40001 without known code' => ['mysql', ['40001', 9999, 'x']];
        yield 'postgres unique 23505' => ['postgres', ['23505', 7, 'duplicate key']];
        yield 'postgres foreign key 23503' => ['postgres', ['23503', 7, 'violates foreign key']];
        yield 'postgres syntax error 42601' => ['postgres', ['42601', 7, 'syntax error']];
        yield 'postgres mysql-code does not match' => ['postgres', ['HY000', 1020, 'x']];
        yield 'mysql postgres-state does not match' => ['mysql', ['40P01', 7, 'x']];
        yield 'sqlite busy is out of scope' => ['sqlite', ['HY000', 5, 'database is locked']];
        yield 'unknown dialect' => ['oracle', ['40001', 1213, 'x']];
    }

    /**
     * @param array<int, mixed> $errorInfo
     */
    #[DataProvider('nonTransientProvider')]
    public function testOtherErrorsAreNotTransient(string $dialect, array $errorInfo): void
    {
        $this->assertNull(($this->detect)($this->exception($errorInfo), $dialect));
    }

    #[DataProvider('dialects')]
    public function testMissingErrorInfoIsNotTransient(string $dialect): void
    {
        $this->assertNull(($this->detect)($this->exception(null), $dialect));
        $this->assertNull(($this->detect)($this->exception([]), $dialect));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function dialects(): iterable
    {
        yield 'mysql' => ['mysql'];
        yield 'mariadb' => ['mariadb'];
        yield 'postgres' => ['postgres'];
        yield 'sqlite' => ['sqlite'];
    }
}
