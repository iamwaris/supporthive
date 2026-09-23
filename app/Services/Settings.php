<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;

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
     */
    public static function set(string $key, string $value): void
    {
        Database::instance()->run(
            'INSERT INTO settings (setting_key, setting_value, updated_by)
             VALUES (:k, :v, :u)
             ON DUPLICATE KEY UPDATE setting_value = :v2, updated_by = :u2',
            ['k' => $key, 'v' => $value, 'u' => Auth::id(), 'v2' => $value, 'u2' => Auth::id()]
        );

        self::$cache = null;
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

        $rows = Database::instance()->all('SELECT setting_key, setting_value, value_type FROM settings');
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
