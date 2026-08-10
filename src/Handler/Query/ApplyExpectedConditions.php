<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Handler\Query;

use JardisSupport\Contract\DbQuery\DbDeleteBuilderInterface;
use JardisSupport\Contract\DbQuery\DbQueryBuilderInterface;
use JardisSupport\Contract\DbQuery\DbUpdateBuilderInterface;

/**
 * Applies expected-value conditions (optimistic-concurrency guard) to an UPDATE/DELETE query.
 *
 * Each entry becomes an additional AND-condition: `null` maps to IS NULL, every other
 * scalar maps to an equality check. Shared by UpdateHandler and DeleteHandler.
 */
final class ApplyExpectedConditions
{
    /**
     * @param array<string, scalar|null> $expected Spalte => erwarteter Wert; null => IS NULL
     */
    public function __invoke(
        DbQueryBuilderInterface|DbUpdateBuilderInterface|DbDeleteBuilderInterface $query,
        array $expected,
    ): DbQueryBuilderInterface|DbUpdateBuilderInterface|DbDeleteBuilderInterface {
        foreach ($expected as $column => $value) {
            $this->assertScalarOrNull($column, $value);

            $query = $value === null
                ? $query->and($column)->isNull()
                : $query->and($column)->equals($value);
        }

        return $query;
    }

    private function assertScalarOrNull(string $column, mixed $value): void
    {
        if ($value !== null && !is_scalar($value)) {
            throw new \InvalidArgumentException(
                'Expected condition value for "' . $column . '" must be scalar or null, '
                    . get_debug_type($value) . ' given.'
            );
        }
    }
}
