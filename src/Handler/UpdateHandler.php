<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Handler;

use Closure;
use JardisSupport\Contract\DbQuery\DbPreparedQueryInterface;
use JardisSupport\Contract\Repository\Exception\PersistException;
use JardisSupport\Contract\Repository\Exception\TransientConflictException;
use JardisSupport\Contract\Repository\Exception\UniqueViolationException;
use JardisSupport\DbQuery\DbUpdate;
use JardisSupport\Repository\Handler\Query\ApplyExpectedConditions;
use PDOException;

/**
 * Handles update operations by primary key.
 */
final class UpdateHandler
{
    private readonly Closure $applyExpectedConditions;
    private readonly DetectUniqueViolation $detectUniqueViolation;
    private readonly ResolveViolatedColumns $resolveViolatedColumns;
    private readonly TranslateTransientConflict $translateTransientConflict;

    public function __construct(
        private readonly QueryExecutor $executor,
    ) {
        $this->detectUniqueViolation = new DetectUniqueViolation();
        $this->resolveViolatedColumns = new ResolveViolatedColumns();
        $this->translateTransientConflict = new TranslateTransientConflict();
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

            $transient = ($this->translateTransientConflict)($e, $this->executor->getDialect(), $table);
            if ($transient !== null) {
                throw $transient;
            }

            throw new PersistException(
                'Update failed for ' . $table . ': ' . $e->getMessage(),
                0,
                $e
            );
        }

        return $stmt->rowCount() > 0;
    }
}
