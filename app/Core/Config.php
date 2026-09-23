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
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $name = basename($file, '.php');
            /** @var array<string,mixed> $data */
            $data = require $file;
            self::$items[$name] = $data;
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
