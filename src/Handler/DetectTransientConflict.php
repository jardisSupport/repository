<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Handler;

use PDOException;

/**
 * Detects whether a PDOException is a transient concurrency conflict (dialect-aware): the database rejected the
 * statement because of a snapshot/serialization conflict or a deadlock, repeating the unit of work in a NEW
 * transaction can succeed.
 *
 * Returns the driver code as text ('1020', '1213', '40001', '40P01') or null if the exception is not a transient
 * conflict (or the driver exposes no error info).
 *
 * MySQL/MariaDB (driver code, errorInfo[1]):
 *  - 1020 ER_CHECKREAD: row changed since the snapshot (innodb_snapshot_isolation, MariaDB >= 11.6 default)
 *  - 1213 ER_LOCK_DEADLOCK: deadlock, the transaction was rolled back
 *  Not detected on purpose: 1205 ER_LOCK_WAIT_TIMEOUT. It rolls back only the statement (not the transaction, unless
 *  innodb_rollback_on_timeout) and states "waited too long", not "conflict resolved by the database": it stays a
 *  technical PersistException.
 * PostgreSQL (SQLSTATE, errorInfo[0]): 40001 serialization_failure, 40P01 deadlock_detected.
 * SQLite: none (SQLITE_BUSY is a lock-availability error, not a snapshot/deadlock conflict).
 */
final class DetectTransientConflict
{
    private const MYSQL_CODES = [1020, 1213];
    private const POSTGRES_STATES = ['40001', '40P01'];

    public function __invoke(PDOException $e, string $dialect): ?string
    {
        $info = is_array($e->errorInfo) ? $e->errorInfo : [];

        return match ($dialect) {
            'mysql', 'mariadb' => $this->mysql($info),
            'postgres' => $this->postgres($info),
            default => null,
        };
    }

    /**
     * @param array<array-key, mixed> $info
     */
    private function mysql(array $info): ?string
    {
        $code = $info[1] ?? null;
        if (is_string($code) && ctype_digit($code)) {
            $code = (int) $code;
        }

        return is_int($code) && in_array($code, self::MYSQL_CODES, true) ? (string) $code : null;
    }

    /**
     * @param array<array-key, mixed> $info
     */
    private function postgres(array $info): ?string
    {
        $state = $info[0] ?? null;

        return is_string($state) && in_array($state, self::POSTGRES_STATES, true) ? $state : null;
    }
}
