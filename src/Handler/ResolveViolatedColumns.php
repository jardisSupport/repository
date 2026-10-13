<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Handler;

use PDO;
use PDOException;

/**
 * Resolves the columns of the unique index a PDOException violated (dialect-aware).
 *
 * Only called on the error path. Returns the complete column list of the violated index in
 * index order, or [] when the dialect cannot deliver it safely — never a partial list, and
 * never an exception: a failure while resolving must not hide the original violation.
 *
 * | Dialect       | Source                                                                 |
 * |---------------|------------------------------------------------------------------------|
 * | MySQL/MariaDB | index name from error 1062 -> information_schema.STATISTICS (cached)   |
 * | Postgres      | DETAIL `Key (a, b)=(...)` (no lookup: the transaction is aborted)      |
 * | SQLite        | `UNIQUE constraint failed: t.a, t.b`                                   |
 */
final class ResolveViolatedColumns
{
    /** @var array<string, list<string>> */
    private array $mysqlIndexColumns = [];

    /**
     * @param string $constraint Result of DetectUniqueViolation ('' when the name is not readable)
     * @return list<string>
     */
    public function __invoke(PDOException $e, string $dialect, string $table, PDO $pdo, string $constraint): array
    {
        try {
            $info = is_array($e->errorInfo) ? $e->errorInfo : [];
            $driverMessage = isset($info[2]) && is_string($info[2]) ? $info[2] : $e->getMessage();

            return match ($dialect) {
                'mysql', 'mariadb' => $this->mysql($pdo, $table, $constraint),
                'postgres' => $this->postgres($driverMessage),
                'sqlite' => $this->sqlite($driverMessage, $e->getMessage(), $table),
                default => [],
            };
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<string>
     */
    private function mysql(PDO $pdo, string $table, string $index): array
    {
        if ($index === '') {
            return [];
        }

        $cacheKey = $table . "\0" . $index;
        if (isset($this->mysqlIndexColumns[$cacheKey])) {
            return $this->mysqlIndexColumns[$cacheKey];
        }

        $stmt = $pdo->prepare(
            'SELECT COLUMN_NAME FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? '
            . 'ORDER BY SEQ_IN_INDEX'
        );
        if ($stmt === false || $stmt->execute([$table, $index]) === false) {
            return [];
        }

        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $column) {
            if (!is_string($column) || $column === '') {
                // expression part of a functional index: all-or-nothing
                return [];
            }
            $columns[] = $column;
        }

        if ($columns === []) {
            return [];
        }

        return $this->mysqlIndexColumns[$cacheKey] = $columns;
    }

    /**
     * @return list<string>
     */
    private function postgres(string $message): array
    {
        // The first line carries the (quoted) constraint name; the key list is the first
        // parenthesis of the following DETAIL line, also in translated server messages.
        $newline = strpos($message, "\n");
        if ($newline === false) {
            return [];
        }
        $detail = substr($message, $newline + 1);
        $open = strpos($detail, '(');
        if ($open === false) {
            return [];
        }

        $pattern = '/\G(?:"((?:[^"]|"")+)"|([a-z_][a-z0-9_]*))(, |\)=\()/A';
        $offset = $open + 1;
        $columns = [];
        while (preg_match($pattern, $detail, $m, 0, $offset) === 1) {
            $columns[] = $m[1] !== '' ? str_replace('""', '"', $m[1]) : $m[2];
            $offset += strlen($m[0]);
            if ($m[3] === ')=(') {
                return $columns;
            }
        }

        // expression index, unreadable token: all-or-nothing
        return [];
    }

    /**
     * @return list<string>
     */
    private function sqlite(string $driverMessage, string $message, string $table): array
    {
        foreach ([$driverMessage, $message] as $candidate) {
            $marker = 'UNIQUE constraint failed: ';
            $pos = strpos($candidate, $marker);
            if ($pos === false) {
                continue;
            }

            $list = trim(substr($candidate, $pos + strlen($marker)));
            if ($list === '' || str_starts_with($list, "index '")) {
                return [];
            }

            $columns = [];
            foreach (explode(', ', $list) as $item) {
                $item = trim($item);
                $prefix = $table . '.';
                if (str_starts_with($item, $prefix)) {
                    $column = substr($item, strlen($prefix));
                } else {
                    $dot = strpos($item, '.');
                    $column = $dot === false ? '' : substr($item, $dot + 1);
                }
                if ($column === '') {
                    return [];
                }
                $columns[] = $column;
            }

            return $columns;
        }

        return [];
    }
}
