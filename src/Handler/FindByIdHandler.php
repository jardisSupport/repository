<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Handler;

use JardisSupport\DbQuery\DbQuery;

/**
 * Handles find-by-primary-key operations.
 */
final class FindByIdHandler
{
    public function __construct(
        private readonly QueryExecutor $executor,
    ) {
    }

    /**
     * @param string $table Tabellenname
     * @param string $pkColumn Primary-Key-Spalte
     * @param int|string $id Primary-Key-Wert
     * @return array<string, mixed>|null
     */
    public function __invoke(
        string $table,
        string $pkColumn,
        int|string $id,
    ): ?array {
        $prepared = (new DbQuery())
            ->select('*')
            ->from($table)
            ->where($pkColumn)->equals($id)
            ->sql($this->executor->getDialect(), prepared: true);

        return $this->executor->fetchOne($prepared);
    }
}
