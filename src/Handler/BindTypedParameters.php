<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Handler;

use PDO;
use PDOStatement;

/**
 * Binds prepared-statement parameters with an explicit PDO type per value.
 *
 * PDOStatement::execute($bindings) binds every value as PDO::PARAM_STR, which turns PHP
 * `false` into the empty string on the wire. Postgres rejects that against a BOOLEAN column
 * ("invalid input syntax for type boolean"). Binding each value with bindValue() and its
 * matching PDO::PARAM_* type keeps bool/int/null intact across dialects.
 */
final readonly class BindTypedParameters
{
    /**
     * @param array<int|string, mixed> $bindings Numeric keys for positional (?) placeholders,
     *                                            string keys for named (:name) placeholders.
     */
    public function __invoke(PDOStatement $stmt, array $bindings): void
    {
        foreach ($bindings as $key => $value) {
            $param = is_int($key) ? $key + 1 : $key;
            $type = match (true) {
                is_bool($value) => PDO::PARAM_BOOL,
                is_int($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };

            $stmt->bindValue($param, $value, $type);
        }
    }
}
