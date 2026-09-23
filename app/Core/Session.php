<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Hardened session handling: strict mode, HttpOnly + SameSite cookies,
 * periodic ID rotation, idle timeout, and user-agent binding.
 */
final class Session
{
    private const ROTATE_EVERY = 900; // seconds

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');
        ini_set('session.gc_maxlifetime', (string) Config::get('session.lifetime', 7200));

        session_name((string) Config::get('session.name', 'shsid'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => (bool) Config::get('session.secure', false),
            'httponly' => true,
            'samesite' => (string) Config::get('session.samesite', 'Lax'),
        ]);

        session_start();

        $now = time();
        $lifetime = (int) Config::get('session.lifetime', 7200);

        if (isset($_SESSION['_last_seen']) && $now - (int) $_SESSION['_last_seen'] > $lifetime) {
            self::destroy();
            session_start();
        }

        // Bind the session to the user agent: a stolen cookie replayed elsewhere is dropped.
        $fingerprint = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . (string) Config::get('app.key', ''));
        if (!isset($_SESSION['_fp'])) {
            $_SESSION['_fp'] = $fingerprint;
        } elseif (!hash_equals((string) $_SESSION['_fp'], $fingerprint)) {
            Logger::security('Session fingerprint mismatch', ['ip' => Http::clientIp()]);
            self::destroy();
            session_start();
            $_SESSION['_fp'] = $fingerprint;
        }

        if (!isset($_SESSION['_rotated_at']) || $now - (int) $_SESSION['_rotated_at'] > self::ROTATE_EVERY) {
            self::regenerate();
        }

        $_SESSION['_last_seen'] = $now;
    }

    /** Call on every privilege change: login, logout, role change, password reset. */
    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            $_SESSION['_rotated_at'] = time();
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Write a read-once message, or read and clear one. */
    public static function flash(string $key, mixed $value = null): mixed
    {
        if ($value !== null) {
            $_SESSION['_flash'][$key] = $value;
            return null;
        }
        $out = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);
        return $out;
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie((string) session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
