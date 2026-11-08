<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Tests\Unit\Handler;

use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use JardisSupport\Contract\Repository\Exception\PersistException;
use JardisSupport\Contract\Repository\Exception\TransientConflictException;
use JardisSupport\DbQuery\Data\DbPreparedQuery;
use JardisSupport\Contract\Repository\PrimaryKey\PkStrategy;
use JardisSupport\Repository\Handler\InsertHandler;
use JardisSupport\Repository\Handler\QueryExecutor;
use JardisSupport\Repository\Tests\Support\Db\ThrowingPdo;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every statement path of the QueryExecutor (execute, fetchAll, fetchOne) turns a transient conflict of the
 * database into a TransientConflictException; any other PDOException passes through unchanged.
 */
final class QueryExecutorTransientConflictTest extends TestCase
{
    /**
     * @return array<string, array{string, string, array<int, int|string>, string}>
     */
    public static function transientProvider(): array
    {
        return [
            'mysql 1020' => ['mysql', 'HY000', [1020], '1020'],
            'mysql 1213' => ['mysql', '40001', [1213], '1213'],
            'mariadb 1020' => ['mariadb', 'HY000', [1020], '1020'],
            'postgres 40001' => ['pgsql', '40001', [7], '40001'],
            'postgres 40P01' => ['pgsql', '40P01', [7], '40P01'],
        ];
    }

    /**
     * @param array<int, int|string> $codes
     */
    #[DataProvider('transientProvider')]
    public function testEveryStatementPathTranslatesTransientConflict(
        string $driver,
        string $sqlState,
        array $codes,
        string $expectedCode,
    ): void {
        foreach (['execute', 'fetchAll', 'fetchOne'] as $method) {
            $failure = new PDOException('conflict');
            $failure->errorInfo = [$sqlState, $codes[0], 'conflict'];
            $executor = new QueryExecutor($this->connection($driver, $failure));

            try {
                $executor->{$method}(new DbPreparedQuery('UPDATE t SET a = 1', [], $driver));
                $this->fail($method . ': TransientConflictException expected');
            } catch (TransientConflictException $e) {
                $this->assertSame($expectedCode, $e->getDriverCode(), $method);
                $this->assertSame($failure, $e->getPrevious(), $method);
                $this->assertInstanceOf(PersistException::class, $e);
            }
        }
    }

    public function testOtherDatabaseErrorPassesThroughAsPdoException(): void
    {
        $failure = new PDOException('dup');
        $failure->errorInfo = ['23000', 1062, 'Duplicate entry'];
        $executor = new QueryExecutor($this->connection('mysql', $failure));

        $this->expectExceptionObject($failure);
        $executor->execute(new DbPreparedQuery('UPDATE t SET a = 1', [], 'mysql'));
    }

    public function testIntegerPkReadOfInsertHandlerTranslatesTransientConflict(): void
    {
        $failure = new PDOException('deadlock');
        $failure->errorInfo = ['40001', 1213, 'deadlock'];
        $handler = new InsertHandler(new QueryExecutor($this->connection('mysql', $failure)));

        try {
            $handler('orders', 'id', ['status' => 'a'], PkStrategy::INTEGER);
            $this->fail('TransientConflictException expected');
        } catch (TransientConflictException $e) {
            $this->assertSame('1213', $e->getDriverCode());
            $this->assertStringContainsString('orders', $e->getMessage());
        }
    }

    private function connection(string $driver, PDOException $failure): DbConnectionInterface
    {
        $connection = $this->createStub(DbConnectionInterface::class);
        $connection->method('getDriverName')->willReturn($driver);
        $connection->method('pdo')->willReturn(new ThrowingPdo($failure));

        return $connection;
    }
}
