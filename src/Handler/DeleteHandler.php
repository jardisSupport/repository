<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Handler;

use JardisSupport\Contract\DbQuery\DbPreparedQueryInterface;
use JardisSupport\Contract\Repository\Exception\PersistException;
use JardisSupport\DbQuery\DbDelete;
use PDOException;

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
