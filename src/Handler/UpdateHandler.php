<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Handler;

use Closure;
use JardisSupport\Contract\DbQuery\DbPreparedQueryInterface;
use JardisSupport\Contract\Repository\Exception\PersistException;
use JardisSupport\DbQuery\DbUpdate;
use JardisSupport\Repository\Handler\Query\ApplyExpectedConditions;
use PDOException;

/**
 * Handles update operations by primary key.
 */
final class UpdateHandler
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
     * @param array<string, mixed> $values Geaenderte Spaltenwerte
     * @param array<string, scalar|null> $expected Spalte => erwarteter Wert; null => IS NULL
     */
    public function __invoke(
        string $table,
        string $pkColumn,
        int|string $id,
        array $values,
        array $expected = [],
    ): bool {
        if (empty($values)) {
            if ($expected !== []) {
                throw new \InvalidArgumentException(
                    'Cannot verify $expected without $values in update() for ' . $table
                );
            }

            return true;
        }

        $query = (new DbUpdate())
            ->table($table)
            ->setMultiple($values)
            ->where($pkColumn)->equals($id);
        $query = ($this->applyExpectedConditions)($query, $expected);

        $prepared = $query->sql($this->executor->getDialect(), prepared: true);
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
