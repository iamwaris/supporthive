<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Minimal .env reader. Values land in a private store, never in $_ENV/getenv(),
 * so a leaked phpinfo() or var_dump($_ENV) cannot spill credentials.
 */
final class Env
{
    /** @var array<string,string> */
    private static array $vars = [];
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        if (!is_readable($path)) {
            return; // Deployed hosts may inject config another way; Config::require() enforces presence.
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Strip inline comments on unquoted values, then surrounding quotes.
            if ($value !== '' && $value[0] !== '"' && $value[0] !== "'") {
                $value = trim(preg_split('/\s+#/', $value)[0] ?? '');
            }
            if (strlen($value) > 1 && ($value[0] === '"' || $value[0] === "'") && $value[0] === substr($value, -1)) {
                $value = substr($value, 1, -1);
            }

            self::$vars[$key] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return self::$vars[$key] ?? $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::$vars[$key] ?? null;
        if ($value === null || $value === '') {
            return $default;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::$vars[$key] ?? null;
        return ($value === null || $value === '') ? $default : (int) $value;
    }

    public static function require(string $key): string
    {
        $value = self::$vars[$key] ?? '';
        if ($value === '') {
            throw new RuntimeException("Missing required environment variable: {$key}");
        }
        return $value;
    }
}
