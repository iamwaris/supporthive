<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Compares values decoded from a JSON column (e.g. audit_log.meta) without
 * depending on object key order, while keeping assertSame's type strictness.
 * JSON lists keep their order and are compared as they are.
 */
trait JsonColumnAssertions
{
    // MySQL 8 JSON columns store object keys reordered (shorter first, then bytewise); MariaDB keeps insertion order.
    protected static function assertJsonColumnEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::assertSame(self::sortObjectKeys($expected), self::sortObjectKeys($actual), $message);
    }

    private static function sortObjectKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $sorted = array_map(self::sortObjectKeys(...), $value);
        if (!array_is_list($sorted)) {
            ksort($sorted, SORT_STRING);
        }

        return $sorted;
    }
}
