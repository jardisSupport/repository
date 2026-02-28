<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Handler;

use JardisSupport\DbQuery\DbDelete;

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

        $this->executor->fetchAll($prepared);
    }
}
