<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Handler;

use JardisSupport\DbQuery\DbQuery;

/**
 * Handles existence checks by primary key.
 */
final class ExistsHandler
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
        $prepared = (new DbQuery())
            ->select('1')
            ->from($table)
            ->where($pkColumn)->equals($id)
            ->limit(1)
            ->sql($this->executor->getDialect(), prepared: true);

        return $this->executor->fetchOne($prepared) !== null;
    }
}
