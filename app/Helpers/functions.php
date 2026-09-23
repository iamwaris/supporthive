<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Session;

if (!function_exists('e')) {
    /**
     * Escape for HTML output. Every dynamic value printed in a view goes through this.
     */
    function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('config')) {
    /** @return mixed */
    function config(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
    }
}

if (!function_exists('old')) {
    function old(string $field, string $default = ''): string
    {
        $values = Session::get('_old', []);
        return is_array($values) && isset($values[$field]) ? (string) $values[$field] : $default;
    }
}

if (!function_exists('url')) {
    function url(string $path = ''): string
    {
        return rtrim((string) config('app.url'), '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('str_random')) {
    function str_random(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }
}

if (!function_exists('asset')) {
    /**
     * Versioned URL for a built asset.
     *
     * public/.htaccess caches CSS and JS for a year, which is correct for
     * performance and wrong for an unversioned filename: after a deploy a
     * returning browser keeps serving the previous app.css and the page
     * renders with stylesheet rules that no longer match the markup. It
     * happened on the first M1 deploy - the buttons came back in the old
     * scaffold's indigo and every icon rendered full-size.
     *
     * A content hash in the query string makes each build a distinct URL, so
     * a changed file is fetched and an unchanged one still hits the cache.
     * Hashed once per request per file.
     */
    function asset(string $path): string
    {
        static $versions = [];

        $relative = ltrim($path, '/');

        if (!array_key_exists($relative, $versions)) {
            $file = PUBLIC_PATH . '/' . $relative;
            $versions[$relative] = is_file($file)
                ? substr((string) md5_file($file), 0, 10)
                : null;
        }

        $version = $versions[$relative];

        return $version === null ? url($path) : url($path) . '?v=' . $version;
    }
}
