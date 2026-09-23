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
