<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Dot-notation access to config/*.php. Config files read Env; nothing else should.
 */
final class Config
{
    /** @var array<string,mixed> */
    private static array $items = [];

    public static function load(string $dir): void
    {
        // The require happens inside a closure, NOT in this method's scope.
        //
        // `require` executes in the calling scope, so a config file that
        // declares a variable named like one of ours silently overwrites it.
        // config/database.php assigning $name keyed the entire config array by
        // the database name instead of "database", and every config lookup
        // returned null - with no error anywhere.
        $read = static function (string $path): array {
            /** @var array<string,mixed> $values */
            $values = require $path;
            return $values;
        };

        foreach (glob($dir . '/*.php') ?: [] as $configFile) {
            self::$items[basename($configFile, '.php')] = $read($configFile);
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public static function isProduction(): bool
    {
        return self::get('app.env') === 'production';
    }
}
