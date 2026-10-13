<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Handler;

use JardisSupport\Contract\Repository\Exception\TransientConflictException;
use PDOException;

/**
 * Turns a transient concurrency conflict (see DetectTransientConflict) of a write on $table into a
 * TransientConflictException carrying the driver code and the PDOException as previous.
 * Returns null if the exception is not a transient conflict — the caller keeps its own error path.
 */
final class TranslateTransientConflict
{
    private readonly DetectTransientConflict $detectTransientConflict;

    public function __construct()
    {
        $this->detectTransientConflict = new DetectTransientConflict();
    }

    public function __invoke(PDOException $e, string $dialect, string $table): ?TransientConflictException
    {
        $driverCode = ($this->detectTransientConflict)($e, $dialect);
        if ($driverCode === null) {
            return null;
        }

        return new TransientConflictException(
            'Transient conflict on ' . $table . ': ' . $e->getMessage(),
            $driverCode,
            $e
        );
    }
}
