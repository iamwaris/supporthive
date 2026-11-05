<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Synchroniser-token CSRF protection.
 *
 * One token per session, rotated on login/logout. Verified with hash_equals so
 * comparison is constant-time. Every state-changing request (POST/PUT/DELETE)
 * must call Csrf::check() before touching the database.
 */
final class Csrf
{
    private const KEY = '_csrf_token';
    private const ISSUED = '_csrf_issued_at';
    public const FIELD = '_csrf';
    public const HEADER = 'HTTP_X_CSRF_TOKEN';

    public static function token(): string
    {
        $lifetime = (int) Config::get('security.csrf_lifetime', 7200);
        $issued = (int) Session::get(self::ISSUED, 0);

        if (!Session::has(self::KEY) || (time() - $issued) > $lifetime) {
            self::rotate();
        }

        return (string) Session::get(self::KEY, '');
    }

    public static function rotate(): void
    {
        Session::set(self::KEY, bin2hex(random_bytes(32)));
        Session::set(self::ISSUED, time());
    }

    public static function isValid(?string $candidate): bool
    {
        $stored = (string) Session::get(self::KEY, '');
        if ($stored === '' || $candidate === null || $candidate === '') {
            return false;
        }
        return hash_equals($stored, $candidate);
    }

    /**
     * Enforce CSRF on the current request. Aborts with 419 on failure.
     * Safe methods (GET/HEAD/OPTIONS) are skipped; they must never change state.
     *
     * $onFailure lets one route answer a rejected token its own way (see
     * Router::post()'s $onCsrfFailure). It runs instead of the handler, never
     * alongside it, and if it returns the request still ends in the 419, so
     * a recovery path can never fall through into the protected action.
     *
     * @param (callable():void)|null $onFailure
     */
    public static function check(?callable $onFailure = null): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        $candidate = $_POST[self::FIELD] ?? $_SERVER[self::HEADER] ?? null;

        if (!self::isValid(is_string($candidate) ? $candidate : null)) {
            Logger::security('CSRF token rejected', [
                'ip'     => Http::clientIp(),
                'path'   => Http::redactForLog((string) ($_SERVER['REQUEST_URI'] ?? '')),
                'method' => $method,
            ]);
            if ($onFailure !== null) {
                $onFailure();
            }
            Http::abort(419, 'Your session expired. Please reload the page and try again.');
        }
    }
}
