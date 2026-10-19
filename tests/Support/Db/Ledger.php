<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Tests\Support\Db;

use PDO;

/**
 * The row state the transaction tests look at: one table, one note per row. Works on SQLite, MariaDB and
 * PostgreSQL; the table is (re)created outside any transaction.
 */
final class Ledger
{
    public const TABLE = 'test_run_in_transaction';

    public function __construct(private readonly PDO $pdo)
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $key = match ($driver) {
            'pgsql' => 'SERIAL PRIMARY KEY',
            'mysql' => 'INT AUTO_INCREMENT PRIMARY KEY',
            default => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        };

        $this->pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE);
        $this->pdo->exec('CREATE TABLE ' . self::TABLE . ' (id ' . $key . ', note VARCHAR(100) NOT NULL)');
    }

    public static function sqlite(): PDO
    {
        return new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    public function add(string $note): void
    {
        $this->pdo->prepare('INSERT INTO ' . self::TABLE . ' (note) VALUES (?)')->execute([$note]);
    }

    /**
     * @return list<string>
     */
    public function notes(): array
    {
        $rows = $this->pdo->query('SELECT note FROM ' . self::TABLE . ' ORDER BY id');

        return $rows === false ? [] : array_map('strval', $rows->fetchAll(PDO::FETCH_COLUMN));
    }

    public function drop(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE);
    }
}
