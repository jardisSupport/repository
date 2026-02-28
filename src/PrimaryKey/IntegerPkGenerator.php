<?php

declare(strict_types=1);

namespace JardisSupport\Repository\PrimaryKey;

use JardisPort\DbQuery\DbPreparedQueryInterface;
use JardisSupport\DbQuery\DbQuery;
use PDO;

/**
 * Generates integer primary keys using MAX+1 strategy.
 */
final class IntegerPkGenerator
{
    public function generate(PDO $pdo, string $dialect, string $table, string $pkColumn): int
    {
        $prepared = (new DbQuery())
            ->select($pkColumn)
            ->from($table)
            ->orderBy($pkColumn, 'DESC')
            ->limit(1)
            ->sql($dialect, prepared: true);
        assert($prepared instanceof DbPreparedQueryInterface);

        $stmt = $pdo->prepare($prepared->sql());
        $stmt->execute($prepared->bindings());
        $row = $stmt->fetch();

        if ($row === false) {
            return 1;
        }

        return ((int) $row[$pkColumn]) + 1;
    }
}
