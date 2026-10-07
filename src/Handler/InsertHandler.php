<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Handler;

use JardisSupport\Contract\DbQuery\DbPreparedQueryInterface;
use JardisSupport\Contract\Repository\Exception\PersistException;
use JardisSupport\Contract\Repository\Exception\UniqueViolationException;
use JardisSupport\DbQuery\DbInsert;
use JardisSupport\Contract\Repository\PrimaryKey\PkStrategy;
use PDOException;

/**
 * Handles insert operations with configurable primary key strategies.
 */
final class InsertHandler
{
    private readonly IntegerPkGenerator $integerPkGenerator;
    private readonly DetectUniqueViolation $detectUniqueViolation;

    public function __construct(
        private readonly QueryExecutor $executor,
    ) {
        $this->detectUniqueViolation = new DetectUniqueViolation();
        $this->integerPkGenerator = new IntegerPkGenerator();
    }

    /**
     * @param string $table Tabellenname
     * @param string $pkColumn Primary-Key-Spalte
     * @param array<string, mixed> $values Spaltenwerte
     * @param PkStrategy $pkStrategy Strategie fuer PK-Erzeugung
     * @return int|string Erzeugter Primary Key
     * @throws UniqueViolationException bei Verletzung eines Unique-Constraints
     * @throws PersistException bei sonstigen Persistierungsfehlern
     */
    public function __invoke(
        string $table,
        string $pkColumn,
        array $values,
        PkStrategy $pkStrategy,
    ): int|string {
        if (empty($values)) {
            throw new PersistException('Cannot insert empty values into ' . $table);
        }

        return match ($pkStrategy) {
            PkStrategy::AUTOINCREMENT => $this->autoincrement($table, $values),
            PkStrategy::INTEGER => $this->integerPk($table, $pkColumn, $values),
            PkStrategy::NONE => $this->providedPk($table, $pkColumn, $values),
        };
    }

    /**
     * @param array<string, mixed> $values
     */
    private function autoincrement(string $table, array $values): int
    {
        $prepared = (new DbInsert())
            ->into($table)
            ->set($values)
            ->sql($this->executor->getDialect(), prepared: true);
        \assert($prepared instanceof DbPreparedQueryInterface);

        try {
            $this->executor->fetchAll($prepared);
        } catch (PDOException $e) {
            throw $this->translate($e, $table);
        }

        return (int) $this->executor->getPdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $values
     */
    private function integerPk(string $table, string $pkColumn, array $values): int
    {
        $dialect = $this->executor->getDialect();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $pk = $this->integerPkGenerator->generate(
                $this->executor->getPdo(),
                $dialect,
                $table,
                $pkColumn
            );
            $values[$pkColumn] = $pk;

            try {
                $prepared = (new DbInsert())
                    ->into($table)
                    ->set($values)
                    ->sql($dialect, prepared: true);
                \assert($prepared instanceof DbPreparedQueryInterface);

                $this->executor->fetchAll($prepared);

                return $pk;
            } catch (PDOException $e) {
                $translated = $this->translate($e, $table);
                $retry = $translated instanceof UniqueViolationException
                    && $this->isPrimaryKeyViolation($translated->getConstraint(), $dialect, $table, $pkColumn);
                if (!$retry || $attempt === 3) {
                    throw $translated;
                }
            }
        }

        throw new PersistException('Insert failed for ' . $table . ' after 3 attempts');
    }

    /**
     * @param array<string, mixed> $values
     */
    private function providedPk(string $table, string $pkColumn, array $values): int|string
    {
        if (!array_key_exists($pkColumn, $values)) {
            throw new PersistException(
                'PkStrategy::NONE requires ' . $pkColumn . ' in values for ' . $table
            );
        }

        $pk = $values[$pkColumn];
        if (!is_int($pk) && !is_string($pk)) {
            throw new PersistException(
                'Primary key must be int or string for ' . $table
            );
        }

        $prepared = (new DbInsert())
            ->into($table)
            ->set($values)
            ->sql($this->executor->getDialect(), prepared: true);
        \assert($prepared instanceof DbPreparedQueryInterface);

        try {
            $this->executor->fetchAll($prepared);
        } catch (PDOException $e) {
            throw $this->translate($e, $table);
        }

        return $pk;
    }

    private function translate(PDOException $e, string $table): PersistException
    {
        $constraint = ($this->detectUniqueViolation)($e, $this->executor->getDialect());

        if ($constraint !== null) {
            return new UniqueViolationException(
                'Unique violation on ' . $table . ': ' . $e->getMessage(),
                $constraint ?: null,
                $e
            );
        }

        return new PersistException('Insert failed for ' . $table . ': ' . $e->getMessage(), 0, $e);
    }

    private function isPrimaryKeyViolation(?string $constraint, string $dialect, string $table, string $pkColumn): bool
    {
        if ($constraint === null) {
            return false;
        }

        return match ($dialect) {
            'mysql', 'mariadb' => $constraint === 'PRIMARY',
            'postgres' => str_ends_with($constraint, '_pkey'),
            'sqlite' => $constraint === $table . '.' . $pkColumn,
            default => false,
        };
    }
}
