<?php

declare(strict_types=1);

namespace JardisSupport\Repository\PrimaryKey;

/**
 * Generates UUID v4 primary keys.
 */
final class StringPkGenerator
{
    public function generate(): string
    {
        $data = random_bytes(16);

        // Set version (4) and variant bits (RFC 4122)
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        $hex = bin2hex($data);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
