<?php

declare(strict_types=1);

namespace JardisSupport\Repository\Handler;

use PDOException;

/**
 * Detects whether a PDOException is a unique-constraint violation (dialect-aware).
 *
 * Returns the violated constraint name, an empty string if a duplicate was detected but the
 * name is not readable, or null if the exception is not a unique violation (e.g. foreign key).
 */
final class DetectUniqueViolation
{
    public function __invoke(PDOException $e, string $dialect): ?string
    {
        $info = is_array($e->errorInfo) ? $e->errorInfo : [];
        $message = $e->getMessage();
        $driverMessage = isset($info[2]) && is_string($info[2]) ? $info[2] : $message;

        return match ($dialect) {
            'mysql', 'mariadb' => $this->mysql($info, $driverMessage),
            'postgres' => $this->postgres($info, $driverMessage),
            'sqlite' => $this->sqlite($message, $driverMessage),
            default => null,
        };
    }

    /**
     * @param array<array-key, mixed> $info
     */
    private function mysql(array $info, string $message): ?string
    {
        if (($info[1] ?? null) !== 1062) {
            return null;
        }

        if (preg_match("/for key '([^']*)'/", $message, $m) !== 1) {
            return '';
        }

        $parts = explode('.', $m[1]);

        return (string) end($parts);
    }

    /**
     * @param array<array-key, mixed> $info
     */
    private function postgres(array $info, string $message): ?string
    {
        if (($info[0] ?? null) !== '23505') {
            return null;
        }

        return preg_match('/unique constraint "([^"]*)"/', $message, $m) === 1 ? $m[1] : '';
    }

    private function sqlite(string $message, string $driverMessage): ?string
    {
        foreach ([$driverMessage, $message] as $candidate) {
            $marker = 'UNIQUE constraint failed: ';
            $pos = strpos($candidate, $marker);
            if ($pos !== false) {
                return trim(substr($candidate, $pos + strlen($marker)));
            }
        }

        return null;
    }
}
