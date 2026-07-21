<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Tests\Integration;

use JardisSupport\Repository\Adapter\PdoConnection;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoConnectionPostgresTest extends TestCase
{
    private PdoConnection $connection;

    protected function setUp(): void
    {
        $host = $_ENV['POSTGRES_HOST'] ?? 'postgres';
        $port = $_ENV['POSTGRES_PORT'] ?? '5432';
        $db = $_ENV['POSTGRES_DATABASE'] ?? 'test_db';
        $user = $_ENV['POSTGRES_USER'] ?? 'test_user';
        $pass = $_ENV['POSTGRES_PASSWORD'] ?? 'test_password';

        $pdo = new PDO(
            "pgsql:host={$host};port={$port};dbname={$db}",
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $this->connection = new PdoConnection($pdo);
    }

    public function testGetDriverNameReturnsPgsql(): void
    {
        $this->assertSame('pgsql', $this->connection->getDriverName());
    }

    public function testGetDatabaseNameReturnsPostgresDatabase(): void
    {
        $expected = $_ENV['POSTGRES_DATABASE'] ?? 'test_db';

        $this->assertSame($expected, $this->connection->getDatabaseName());
    }
}
