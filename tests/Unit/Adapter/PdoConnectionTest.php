<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Tests\Unit\Adapter;

use JardisSupport\Repository\Adapter\PdoConnection;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoConnectionTest extends TestCase
{
    private PDO $pdo;
    private PdoConnection $connection;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $this->connection = new PdoConnection($this->pdo);
    }

    public function testPdoReturnsSameInstance(): void
    {
        $this->assertSame($this->pdo, $this->connection->pdo());
    }

    public function testGetDriverNameReturnsSqlite(): void
    {
        $this->assertSame('sqlite', $this->connection->getDriverName());
    }

    public function testGetDatabaseNameReturnsMemoryForSqliteInMemory(): void
    {
        $result = $this->connection->getDatabaseName();

        $this->assertSame(':memory:', $result);
    }

    public function testIsConnectedAlwaysReturnsTrue(): void
    {
        $this->assertTrue($this->connection->isConnected());
    }

    public function testConnectIsNoOp(): void
    {
        $this->connection->connect();

        $this->assertTrue($this->connection->isConnected());
    }

    public function testDisconnectIsNoOp(): void
    {
        $this->connection->disconnect();

        $this->assertTrue($this->connection->isConnected());
    }

    public function testReconnectIsNoOp(): void
    {
        $this->connection->reconnect();

        $this->assertTrue($this->connection->isConnected());
    }

    public function testBeginTransactionAndCommit(): void
    {
        $this->assertFalse($this->connection->inTransaction());

        $this->connection->beginTransaction();
        $this->assertTrue($this->connection->inTransaction());

        $this->connection->commit();
        $this->assertFalse($this->connection->inTransaction());
    }

    public function testBeginTransactionAndRollback(): void
    {
        $this->connection->beginTransaction();
        $this->assertTrue($this->connection->inTransaction());

        $this->connection->rollback();
        $this->assertFalse($this->connection->inTransaction());
    }

    public function testTransactionPersistsDataOnCommit(): void
    {
        $this->pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, val TEXT)');

        $this->connection->beginTransaction();
        $this->pdo->exec("INSERT INTO t (val) VALUES ('test')");
        $this->connection->commit();

        $stmt = $this->pdo->query('SELECT val FROM t');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('test', $row['val']);
    }

    public function testTransactionRollsBackData(): void
    {
        $this->pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, val TEXT)');

        $this->connection->beginTransaction();
        $this->pdo->exec("INSERT INTO t (val) VALUES ('test')");
        $this->connection->rollback();

        $stmt = $this->pdo->query('SELECT COUNT(*) as cnt FROM t');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(0, (int) $row['cnt']);
    }
}
