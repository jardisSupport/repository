<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Handler;

use JardisSupport\DbQuery\DbInsert;
use JardisSupport\Repository\Exception\PersistException;
use JardisSupport\Repository\PrimaryKey\IntegerPkGenerator;
use JardisSupport\Repository\PrimaryKey\PkStrategy;
use JardisSupport\Repository\PrimaryKey\StringPkGenerator;
use PDOException;

/**
 * Handles insert operations with configurable primary key strategies.
 */
final class InsertHandler
{
    private readonly IntegerPkGenerator $integerPkGenerator;
    private readonly StringPkGenerator $stringPkGenerator;

    public function __construct(
        private readonly QueryExecutor $executor,
    ) {
        $this->integerPkGenerator = new IntegerPkGenerator();
        $this->stringPkGenerator = new StringPkGenerator();
    }

    /**
     * @param string $table Tabellenname
     * @param string $pkColumn Primary-Key-Spalte
     * @param array<string, mixed> $values Spaltenwerte
     * @param PkStrategy $pkStrategy Strategie fuer PK-Erzeugung
     * @return int|string Erzeugter Primary Key
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
            PkStrategy::STRING => $this->stringPk($table, $pkColumn, $values),
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

        $this->executor->fetchAll($prepared);

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

                $this->executor->fetchAll($prepared);

                return $pk;
            } catch (PDOException $e) {
                if (!$this->isDuplicateKeyError($e) || $attempt === 3) {
                    throw new PersistException(
                        'Insert failed for ' . $table . ': ' . $e->getMessage(),
                        0,
                        $e
                    );
                }
            }
        }

        throw new PersistException('Insert failed for ' . $table . ' after 3 attempts');
    }

    /**
     * @param array<string, mixed> $values
     */
    private function stringPk(string $table, string $pkColumn, array $values): string
    {
        $pk = $this->stringPkGenerator->generate();
        $values[$pkColumn] = $pk;

        $prepared = (new DbInsert())
            ->into($table)
            ->set($values)
            ->sql($this->executor->getDialect(), prepared: true);

        $this->executor->fetchAll($prepared);

        return $pk;
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

        $this->executor->fetchAll($prepared);

        return $pk;
    }

    private function isDuplicateKeyError(PDOException $e): bool
    {
        return $e->getCode() === '23000'
            || str_contains($e->getMessage(), 'Duplicate')
            || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }
}
