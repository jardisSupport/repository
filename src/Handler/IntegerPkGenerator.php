<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Handler;

use JardisSupport\Contract\DbQuery\DbPreparedQueryInterface;
use JardisSupport\DbQuery\DbQuery;
use PDO;

/**
 * Generates integer primary keys using MAX+1 strategy.
 */
final class IntegerPkGenerator
{
    private readonly BindTypedParameters $bindTypedParameters;

    public function __construct(?BindTypedParameters $bindTypedParameters = null)
    {
        $this->bindTypedParameters = $bindTypedParameters ?? new BindTypedParameters();
    }

    public function generate(PDO $pdo, string $dialect, string $table, string $pkColumn): int
    {
        $prepared = (new DbQuery())
            ->select($pkColumn)
            ->from($table)
            ->orderBy($pkColumn, 'DESC')
            ->limit(1)
            ->sql($dialect, prepared: true);
        \assert($prepared instanceof DbPreparedQueryInterface);

        $stmt = $pdo->prepare($prepared->sql());
        ($this->bindTypedParameters)($stmt, $prepared->bindings());
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return 1;
        }

        return ((int) $row[$pkColumn]) + 1;
    }
}
