<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Handler;

use JardisSupport\Contract\DbQuery\DbPreparedQueryInterface;
use JardisSupport\Contract\Repository\Exception\PersistException;
use JardisSupport\DbQuery\DbUpdate;
use PDOException;

/**
 * Handles update operations by primary key.
 */
final class UpdateHandler
{
    public function __construct(
        private readonly QueryExecutor $executor,
    ) {
    }

    /**
     * @param string $table Tabellenname
     * @param string $pkColumn Primary-Key-Spalte
     * @param int|string $id Primary-Key-Wert
     * @param array<string, mixed> $values Geaenderte Spaltenwerte
     */
    public function __invoke(
        string $table,
        string $pkColumn,
        int|string $id,
        array $values,
    ): bool {
        if (empty($values)) {
            return true;
        }

        $prepared = (new DbUpdate())
            ->table($table)
            ->setMultiple($values)
            ->where($pkColumn)->equals($id)
            ->sql($this->executor->getDialect(), prepared: true);
        \assert($prepared instanceof DbPreparedQueryInterface);

        try {
            $stmt = $this->executor->execute($prepared);
        } catch (PDOException $e) {
            throw new PersistException(
                'Update failed for ' . $table . ': ' . $e->getMessage(),
                0,
                $e
            );
        }

        return $stmt->rowCount() > 0;
    }
}
