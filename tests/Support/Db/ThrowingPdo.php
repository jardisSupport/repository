<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Tests\Support\Db;

use PDO;
use PDOException;
use PDOStatement;

/**
 * A PDO whose prepare() throws the given PDOException - stands in for a database that rejects a statement.
 */
final class ThrowingPdo extends PDO
{
    public function __construct(private readonly PDOException $failure)
    {
        parent::__construct('sqlite::memory:');
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        throw $this->failure;
    }
}
