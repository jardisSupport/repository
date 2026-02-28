<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Handler;

use JardisSupport\DbQuery\DbDelete;

/**
 * Handles single-record delete operations by primary key.
 */
final class DeleteHandler
{
    public function __construct(
        private readonly QueryExecutor $executor,
    ) {
    }

    /**
     * @param string $table Tabellenname
     * @param string $pkColumn Primary-Key-Spalte
     * @param int|string $id Primary-Key-Wert
     */
    public function __invoke(
        string $table,
        string $pkColumn,
        int|string $id,
    ): bool {
        $prepared = (new DbDelete())
            ->from($table)
            ->where($pkColumn)->equals($id)
            ->sql($this->executor->getDialect(), prepared: true);

        $this->executor->fetchAll($prepared);

        return true;
    }
}
