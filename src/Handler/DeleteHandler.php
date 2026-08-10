<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Handler;

use Closure;
use JardisSupport\Contract\DbQuery\DbPreparedQueryInterface;
use JardisSupport\Contract\Repository\Exception\PersistException;
use JardisSupport\DbQuery\DbDelete;
use JardisSupport\Repository\Handler\Query\ApplyExpectedConditions;
use PDOException;

/**
 * Handles single-record delete operations by primary key.
 */
final class DeleteHandler
{
    private readonly Closure $applyExpectedConditions;

    public function __construct(
        private readonly QueryExecutor $executor,
    ) {
        $this->applyExpectedConditions = (new ApplyExpectedConditions())->__invoke(...);
    }

    /**
     * @param string $table Tabellenname
     * @param string $pkColumn Primary-Key-Spalte
     * @param int|string $id Primary-Key-Wert
     * @param array<string, scalar|null> $expected Spalte => erwarteter Wert; null => IS NULL
     */
    public function __invoke(
        string $table,
        string $pkColumn,
        int|string $id,
        array $expected = [],
    ): bool {
        $query = (new DbDelete())
            ->from($table)
            ->where($pkColumn)->equals($id);
        $query = ($this->applyExpectedConditions)($query, $expected);

        $prepared = $query->sql($this->executor->getDialect(), prepared: true);
        \assert($prepared instanceof DbPreparedQueryInterface);

        try {
            $stmt = $this->executor->execute($prepared);
        } catch (PDOException $e) {
            throw new PersistException(
                'Delete failed for ' . $table . ': ' . $e->getMessage(),
                0,
                $e
            );
        }

        return $stmt->rowCount() > 0;
    }
}
