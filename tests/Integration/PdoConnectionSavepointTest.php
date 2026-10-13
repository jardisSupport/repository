<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Tests\Integration;

use InvalidArgumentException;
use JardisSupport\Contract\DbConnection\SavepointInterface;
use JardisSupport\Repository\Adapter\PdoConnection;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Savepoints of the PDO wrapper against SQLite, MySQL, MariaDB and PostgreSQL.
 */
final class PdoConnectionSavepointTest extends TestCase
{
    private const TABLE = 'test_savepoint';

    private ?PDO $pdo = null;

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE);
            $this->pdo = null;
        }

        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function drivers(): array
    {
        return ['sqlite' => ['sqlite'], 'mysql' => ['mysql'], 'mariadb' => ['mariadb'], 'postgres' => ['pgsql']];
    }

    #[DataProvider('drivers')]
    public function testWrapperImplementsSavepointCapability(string $driver): void
    {
        $this->assertInstanceOf(SavepointInterface::class, $this->open($driver));
    }

    #[DataProvider('drivers')]
    public function testRollbackToSavepointDiscardsOnlyTheNestedPart(string $driver): void
    {
        $conn = $this->open($driver);
        $conn->beginTransaction();
        $this->insert('outer');

        $conn->savepoint('inner_bracket');
        $this->insert('inner');
        $conn->rollbackToSavepoint('inner_bracket');

        $this->assertTrue($conn->inTransaction(), 'the enclosing transaction stays open');
        $conn->commit();

        $this->assertSame(['outer'], $this->labels());
    }

    #[DataProvider('drivers')]
    public function testReleaseSavepointKeepsTheNestedPart(string $driver): void
    {
        $conn = $this->open($driver);
        $conn->beginTransaction();
        $this->insert('outer');

        $conn->savepoint('inner_bracket');
        $this->insert('inner');
        $conn->releaseSavepoint('inner_bracket');
        $conn->commit();

        $this->assertSame(['inner', 'outer'], $this->labels());
    }

    #[DataProvider('drivers')]
    public function testOuterRollbackAlsoDiscardsAReleasedSavepoint(string $driver): void
    {
        $conn = $this->open($driver);
        $conn->beginTransaction();
        $conn->savepoint('inner_bracket');
        $this->insert('inner');
        $conn->releaseSavepoint('inner_bracket');
        $conn->rollback();

        $this->assertSame([], $this->labels());
    }

    #[DataProvider('drivers')]
    public function testWithoutTransactionAllThreeMethodsThrow(string $driver): void
    {
        $conn = $this->open($driver);

        foreach (['savepoint', 'releaseSavepoint', 'rollbackToSavepoint'] as $method) {
            try {
                $conn->{$method}('no_transaction');
                $this->fail($method . ' must throw without an active transaction');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('requires an active transaction', $e->getMessage());
            }
        }
    }

    #[DataProvider('drivers')]
    public function testUnknownSavepointIsAStatementFailure(string $driver): void
    {
        $conn = $this->open($driver);
        $conn->beginTransaction();

        $this->expectException(RuntimeException::class);
        $conn->rollbackToSavepoint('never_opened');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidNames(): array
    {
        return [
            'empty' => [''],
            'leading digit' => ['1abc'],
            'space' => ['a b'],
            'semicolon (injection)' => ['a; DROP TABLE users'],
            'quote' => ["a'b"],
            'trailing newline' => ["abc\n"],
            '64 characters' => [str_repeat('a', 64)],
        ];
    }

    #[DataProvider('invalidNames')]
    public function testInvalidNameThrowsInvalidArgumentOnAllThreeMethods(string $name): void
    {
        $conn = $this->open('sqlite');
        $conn->beginTransaction();

        foreach (['savepoint', 'releaseSavepoint', 'rollbackToSavepoint'] as $method) {
            try {
                $conn->{$method}($name);
                $this->fail($method . ' accepted an invalid name');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testNameWith63CharactersIsAccepted(): void
    {
        $conn = $this->open('sqlite');
        $conn->beginTransaction();
        $name = str_repeat('a', 63);

        $conn->savepoint($name);
        $conn->releaseSavepoint($name);
        $conn->rollback();

        $this->assertSame([], $this->labels());
    }

    private function open(string $driver): PdoConnection
    {
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];

        $this->pdo = match ($driver) {
            'sqlite' => new PDO('sqlite::memory:', null, null, $options),
            'pgsql' => new PDO(
                sprintf(
                    'pgsql:host=%s;port=%s;dbname=%s',
                    $_ENV['POSTGRES_HOST'] ?? 'postgres',
                    $_ENV['POSTGRES_PORT'] ?? '5432',
                    $_ENV['POSTGRES_DATABASE'] ?? 'test_db'
                ),
                $_ENV['POSTGRES_USER'] ?? 'test_user',
                $_ENV['POSTGRES_PASSWORD'] ?? 'test_password',
                $options
            ),
            default => new PDO(
                sprintf(
                    'mysql:host=%s;port=%s;dbname=%s',
                    $_ENV[strtoupper($driver) . '_HOST'] ?? $driver,
                    $_ENV[strtoupper($driver) . '_PORT'] ?? '3306',
                    $_ENV[strtoupper($driver) . '_DATABASE'] ?? 'test_db'
                ),
                $_ENV[strtoupper($driver) . '_USER'] ?? 'test_user',
                $_ENV[strtoupper($driver) . '_PASSWORD'] ?? 'test_password',
                $options
            ),
        };

        $idColumn = match ($driver) {
            'pgsql' => 'id SERIAL PRIMARY KEY',
            'sqlite' => 'id INTEGER PRIMARY KEY AUTOINCREMENT',
            default => 'id INT AUTO_INCREMENT PRIMARY KEY',
        };
        $this->pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE);
        $this->pdo->exec('CREATE TABLE ' . self::TABLE . " ($idColumn, label VARCHAR(32) NOT NULL)");

        return new PdoConnection($this->pdo);
    }

    private function insert(string $label): void
    {
        $this->assertNotNull($this->pdo);
        $this->pdo->exec('INSERT INTO ' . self::TABLE . " (label) VALUES ('$label')");
    }

    /**
     * @return list<string>
     */
    private function labels(): array
    {
        $this->assertNotNull($this->pdo);
        $stmt = $this->pdo->query('SELECT label FROM ' . self::TABLE . ' ORDER BY label');
        $this->assertNotFalse($stmt);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
