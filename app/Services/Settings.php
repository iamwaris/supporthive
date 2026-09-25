<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use RuntimeException;

/**
 * Application settings, stored in the database rather than .env.
 *
 * .env holds things that differ per environment (credentials, URLs). These are
 * business choices the admin changes from the UI — company name, currency,
 * fiscal year start — so they belong in a table, and a deploy must not
 * overwrite them.
 *
 * Loaded once per request and cached in memory: the settings table is read on
 * nearly every page and there are only a handful of rows.
 */
final class Settings
{
    /** @var array<string,array{value:string|null,type:string}>|null */
    private static ?array $cache = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();

        if (!isset(self::$cache[$key])) {
            return $default;
        }

        $row = self::$cache[$key];
        $value = $row['value'];

        if ($value === null || $value === '') {
            return $default;
        }

        return self::cast($value, $row['type']);
    }

    public static function string(string $key, string $default = ''): string
    {
        return (string) self::get($key, $default);
    }

    public static function int(string $key, int $default = 0): int
    {
        return (int) self::get($key, $default);
    }

    /**
     * Write a setting. Records who changed it — settings alter how money is
     * calculated, so an unattributed change is not acceptable.
     *
     * $value is typed mixed rather than string: the previous string-only
     * signature forced every caller to pre-stringify the value before it
     * reached here, which threw away the one signal that lets this method
     * infer the correct value_type (a PHP bool, int or float). Without that
     * signal the INSERT ... ON DUPLICATE KEY UPDATE never set value_type at
     * all, so a brand-new row silently took the settings table's DB-level
     * default of 'string' — and because value_type was never included in the
     * UPDATE branch either, a row stuck at 'string' could never self-correct
     * on a later write. Settings::cast() only converts '1'/'0' to a real
     * bool when value_type = 'bool', so a setting like ai_enabled stuck at
     * 'string' compares false forever against a strict `=== true` check even
     * though the stored value "looks like" it's on.
     */
    public static function set(string $key, mixed $value): void
    {
        $branchId = Auth::branchId();
        if ($branchId === null) {
            throw new RuntimeException('No active branch — cannot change settings.');
        }

        $type = self::inferType($value);
        $stringValue = self::stringify($value);

        Database::instance()->run(
            'INSERT INTO settings (branch_id, setting_key, setting_value, value_type, updated_by)
             VALUES (:b, :k, :v, :t, :u)
             ON DUPLICATE KEY UPDATE setting_value = :v2, value_type = :t2, updated_by = :u2',
            [
                'b' => $branchId,
                'k' => $key,
                'v' => $stringValue,
                't' => $type,
                'u' => Auth::id(),
                'v2' => $stringValue,
                't2' => $type,
                'u2' => Auth::id(),
            ]
        );

        self::$cache = null;
    }

    /** Maps a PHP value's native type to one of the settings.value_type ENUM values. */
    private static function inferType(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'bool',
            is_int($value) => 'int',
            // Money and percentages are passed in as strings precisely to
            // avoid a float round-trip (see cast() below); a genuine PHP
            // float is still honoured for callers that do pass one.
            is_float($value) => 'decimal',
            default => 'string',
        };
    }

    private static function stringify(mixed $value): string
    {
        return is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    }

    /** @return array<string,mixed> every setting, cast, for the settings screen */
    public static function all(): array
    {
        self::load();

        $out = [];
        foreach (self::$cache ?? [] as $key => $row) {
            $out[$key] = $row['value'] === null ? null : self::cast($row['value'], $row['type']);
        }

        return $out;
    }

    /** Drop the in-memory cache. Used by tests. */
    public static function flush(): void
    {
        self::$cache = null;
    }

    private static function load(): void
    {
        if (self::$cache !== null) {
            return;
        }

        self::$cache = [];

        // No active branch — e.g. a super admin on the branch picker, which
        // every page (including this one) reads company_name/currency from
        // via the sidebar. Falling back to defaults rather than throwing
        // keeps that screen renderable; get()/string()/int() already accept
        // a default for exactly this reason.
        $branchId = Auth::branchId();
        if ($branchId === null) {
            return;
        }

        $rows = Database::instance()->all(
            'SELECT setting_key, setting_value, value_type FROM settings WHERE branch_id = :branch',
            ['branch' => $branchId]
        );
        foreach ($rows as $row) {
            self::$cache[(string) $row['setting_key']] = [
                'value' => $row['setting_value'] === null ? null : (string) $row['setting_value'],
                'type' => (string) $row['value_type'],
            ];
        }
    }

    private static function cast(string $value, string $type): mixed
    {
        return match ($type) {
            'int' => (int) $value,
            // Money and percentages stay strings until they are used in
            // arithmetic, so a float never silently rounds a stored value.
            'decimal' => $value,
            'bool' => in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true),
            default => $value,
        };
    }
}
