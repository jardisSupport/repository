<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Handler;

use Closure;
use JardisSupport\Contract\DbQuery\DbPreparedQueryInterface;
use JardisSupport\Contract\Repository\Exception\PersistException;
use JardisSupport\Contract\Repository\Exception\TransientConflictException;
use JardisSupport\Contract\Repository\Exception\UniqueViolationException;
use JardisSupport\DbQuery\Data\DbPreparedQuery;
use JardisSupport\DbQuery\DbQuery;
use JardisSupport\DbQuery\DbUpdate;
use JardisSupport\Repository\Handler\Query\ApplyExpectedConditions;
use PDOException;

/**
 * Handles update operations by primary key.
 *
 * Returns `true` when a row met the condition (primary key plus `$expected`), `false` when none did. The driver's
 * `rowCount()` cannot tell the two apart on MySQL/MariaDB: it counts changed rows, so an UPDATE that matches a row
 * but sets the stored value again (`'1'` onto a DECIMAL `1.00`) reports 0. PostgreSQL and SQLite count matched rows.
 * On `rowCount() === 0` a SELECT with the same condition on the same connection (inside an open transaction too)
 * decides; a positive `rowCount()` runs no further statement. On MySQL/MariaDB that SELECT is a locking read
 * (`FOR UPDATE`): the UPDATE's current read and a plain SELECT's transaction snapshot diverge under REPEATABLE READ,
 * and a row another connection changed since the snapshot must not count as a hit. The lock falls only on a row the
 * UPDATE has locked already.
 */
final class UpdateHandler
{
    private readonly Closure $applyExpectedConditions;
    private readonly DetectUniqueViolation $detectUniqueViolation;
    private readonly ResolveViolatedColumns $resolveViolatedColumns;

    public function __construct(
        private readonly QueryExecutor $executor,
    ) {
        $this->detectUniqueViolation = new DetectUniqueViolation();
        $this->resolveViolatedColumns = new ResolveViolatedColumns();
        $this->applyExpectedConditions = (new ApplyExpectedConditions())->__invoke(...);
    }

    /**
     * @throws UniqueViolationException bei Verletzung eines Unique-Constraints
     * @throws TransientConflictException bei Snapshot-/Serialisierungskonflikt oder Deadlock
     * @throws PersistException bei sonstigen Persistierungsfehlern
     *
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
            $constraint = ($this->detectUniqueViolation)($e, $this->executor->getDialect());
            if ($constraint !== null) {
                throw new UniqueViolationException(
                    'Unique violation on ' . $table . ': ' . $e->getMessage(),
                    $constraint ?: null,
                    $e,
                    ($this->resolveViolatedColumns)(
                        $e,
                        $this->executor->getDialect(),
                        $table,
                        $this->executor->getPdo(),
                        $constraint
                    ),
                    $table
                );
            }


            throw new PersistException(
                'Update failed for ' . $table . ': ' . $e->getMessage(),
                0,
                $e
            );
        }

        return $stmt->rowCount() > 0 || $this->rowMeetsCondition($table, $pkColumn, $id, $expected);
    }

    /**
     * @param array<string, scalar|null> $expected
     */
    private function rowMeetsCondition(string $table, string $pkColumn, int|string $id, array $expected): bool
    {
        $query = (new DbQuery())
            ->select('1')
            ->from($table)
            ->where($pkColumn)->equals($id);
        $query = ($this->applyExpectedConditions)($query, $expected);

        $prepared = $query->limit(1)->sql($this->executor->getDialect(), prepared: true);
        \assert($prepared instanceof DbPreparedQueryInterface);
        if ($this->executor->getDialect() === 'mysql') {
            $prepared = new DbPreparedQuery($prepared->sql() . ' FOR UPDATE', $prepared->bindings(), $prepared->type());
        }

        return $this->executor->fetchOne($prepared) !== null;
    }
}
