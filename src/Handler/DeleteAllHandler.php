<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Handler;

use JardisSupport\Contract\DbQuery\DbPreparedQueryInterface;
use JardisSupport\Contract\Repository\Exception\PersistException;
use JardisSupport\DbQuery\DbDelete;
use PDOException;

/**
 * Handles batch delete operations by primary key list.
 */
final class DeleteAllHandler
{
    public function __construct(
        private readonly QueryExecutor $executor,
    ) {
    }

    /**
     * @param string $table Tabellenname
     * @param string $pkColumn Primary-Key-Spalte
     * @param array<int|string> $ids Primary-Key-Werte
     */
    public function __invoke(
        string $table,
        string $pkColumn,
        array $ids,
    ): void {
        if (empty($ids)) {
            return;
        }

        $prepared = (new DbDelete())
            ->from($table)
            ->where($pkColumn)->in($ids)
            ->sql($this->executor->getDialect(), prepared: true);
        \assert($prepared instanceof DbPreparedQueryInterface);

        try {
            $this->executor->fetchAll($prepared);
        } catch (PDOException $e) {
            throw new PersistException(
                'Delete failed for ' . $table . ': ' . $e->getMessage(),
                0,
                $e
            );
        }
    }
}
