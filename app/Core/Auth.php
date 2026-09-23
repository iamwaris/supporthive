<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Session-based authentication.
 *
 * Password storage uses password_hash() with the platform default algorithm
 * (Argon2id where available, bcrypt otherwise) and rehashes transparently on
 * login when the cost or algorithm changes. Plain-text or md5/sha1 passwords
 * are never written or compared anywhere in this codebase.
 */
final class Auth
{
    private const SESSION_KEY = '_auth_user_id';

    /** @var array<string,mixed>|null */
    private static ?array $cached = null;

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * Attempt a login. Returns true only on success; the caller decides messaging.
     * Failures are deliberately indistinguishable (unknown user vs wrong password)
     * so the endpoint cannot be used to enumerate accounts.
     */
    public static function attempt(string $email, string $password): bool
    {
        $db = Database::instance();
        $user = $db->first(
            'SELECT id, email, password_hash, status FROM users WHERE email = :email LIMIT 1',
            ['email' => $email]
        );

        if ($user === null) {
            // Equalise timing against the hash verification branch.
            password_verify($password, '$2y$12$usesomesillystringforsalttoequalisetimingxxxxxxxxxxxxxxxxxxx');
            Logger::security('Login failed: unknown email', ['ip' => Http::clientIp()]);
            return false;
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            Logger::security('Login failed: bad password', ['user_id' => $user['id'], 'ip' => Http::clientIp()]);
            return false;
        }

        if (($user['status'] ?? 'active') !== 'active') {
            Logger::security('Login blocked: inactive account', ['user_id' => $user['id']]);
            return false;
        }

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            $db->update('users', ['password_hash' => self::hash($password)], 'id = :id', ['id' => $user['id']]);
        }

        self::login((int) $user['id']);
        return true;
    }

    /** Establishes the authenticated session. Always rotates the session id. */
    public static function login(int $userId): void
    {
        Session::regenerate();
        Csrf::rotate();
        Session::set(self::SESSION_KEY, $userId);
        Session::set('_auth_at', time());
        self::$cached = null;

        Database::instance()->update(
            'users',
            ['last_login_at' => date('Y-m-d H:i:s'), 'last_login_ip' => Http::clientIp()],
            'id = :id',
            ['id' => $userId]
        );
        Logger::security('Login success', ['user_id' => $userId, 'ip' => Http::clientIp()]);
    }

    public static function logout(): void
    {
        $id = self::id();
        Session::destroy();
        self::$cached = null;
        Logger::security('Logout', ['user_id' => $id]);
    }

    public static function check(): bool
    {
        return self::id() !== null;
    }

    public static function id(): ?int
    {
        $id = Session::get(self::SESSION_KEY);
        return is_int($id) || ctype_digit((string) $id) ? (int) $id : null;
    }

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        if (self::$cached !== null) {
            return self::$cached;
        }
        $id = self::id();
        if ($id === null) {
            return null;
        }
        self::$cached = Database::instance()->first(
            'SELECT id, name, email, role, status, created_at FROM users WHERE id = :id LIMIT 1',
            ['id' => $id]
        );
        return self::$cached;
    }

    public static function hasRole(string ...$roles): bool
    {
        $user = self::user();
        return $user !== null && in_array((string) ($user['role'] ?? ''), $roles, true);
    }

    /** Gate for protected routes. */
    public static function requireLogin(): void
    {
        if (!self::check()) {
            Session::flash('error', 'Please sign in to continue.');
            Http::redirect('/login');
        }
    }

    /** Gate for role-restricted routes. Authorisation is checked server-side, never in the view. */
    public static function requireRole(string ...$roles): void
    {
        self::requireLogin();
        if (!self::hasRole(...$roles)) {
            Logger::security('Authorisation denied', [
                'user_id' => self::id(),
                'path'    => Http::path(),
                'needed'  => $roles,
            ]);
            Http::abort(403);
        }
    }
}
