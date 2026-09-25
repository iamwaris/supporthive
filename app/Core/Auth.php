<?php

declare(strict_types=1);

namespace App\Core;

use App\Services\Audit;
use RuntimeException;

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
    private const BRANCH_SESSION_KEY = '_active_branch_id';

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
            'SELECT u.id, u.email, u.password_hash, u.status, u.branch_id, b.is_active AS branch_active
             FROM users u
             LEFT JOIN branches b ON b.id = u.branch_id
             WHERE u.email = :email LIMIT 1',
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

        // Locked by a super admin. Checked after the password so this can
        // never be used to probe whether an email/password pair is valid —
        // a wrong password always fails first, regardless of branch state.
        if ($user['branch_id'] !== null && (int) ($user['branch_active'] ?? 0) !== 1) {
            Logger::security(
                'Login blocked: branch locked',
                ['user_id' => $user['id'], 'branch_id' => $user['branch_id']]
            );
            return false;
        }

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            $db->update('users', ['password_hash' => self::hash($password)], 'id = :id', ['id' => $user['id']]);
        }

        self::login((int) $user['id']);
        return true;
    }

    /**
     * Establishes the authenticated session. Always rotates the session id.
     *
     * Sets the active branch from the user's own row: NULL for a super
     * admin (not tied to any branch — see requireActiveBranch()), otherwise
     * that user's one branch. A branch-scoped user never chooses their
     * branch; it is fixed at login.
     */
    public static function login(int $userId): void
    {
        Session::regenerate();
        Csrf::rotate();
        Session::set(self::SESSION_KEY, $userId);
        Session::set('_auth_at', time());
        self::$cached = null;

        $branchId = Database::instance()->value(
            'SELECT branch_id FROM users WHERE id = :id',
            ['id' => $userId]
        );
        Session::set(self::BRANCH_SESSION_KEY, $branchId === null ? null : (int) $branchId);

        Database::instance()->update(
            'users',
            ['last_login_at' => date('Y-m-d H:i:s'), 'last_login_ip' => Http::clientIp()],
            'id = :id',
            ['id' => $userId]
        );
        Logger::security('Login success', ['user_id' => $userId, 'ip' => Http::clientIp()]);
        // branch_id is already set above, so Audit::record() (which reads
        // Auth::branchId() itself) stamps the right one — the same reason
        // this call sits after that line rather than before it.
        Audit::record('auth.login', 'users', $userId, null, ['ip' => Http::clientIp()]);
    }

    public static function logout(): void
    {
        $id = self::id();
        // Recorded before Session::destroy(), since Audit::record() reads
        // Auth::id()/Auth::branchId() from the very session being destroyed.
        Audit::record('auth.logout', 'users', $id, null, null);
        Session::destroy();
        self::$cached = null;
        Logger::security('Logout', ['user_id' => $id]);
    }

    /**
     * Set a new password for the signed-in user and clear the forced-change
     * flag. Rotates the session id — a changed password is exactly the kind
     * of privilege-adjacent event login()/logout() already rotate for.
     */
    public static function updatePassword(string $newPassword): void
    {
        $userId = self::id();
        if ($userId === null) {
            throw new RuntimeException('Changing password requires a signed-in user.');
        }

        Database::instance()->update(
            'users',
            ['password_hash' => self::hash($newPassword), 'must_change_password' => 0],
            'id = :id',
            ['id' => $userId]
        );

        Session::regenerate();
        self::$cached = null;
        Logger::security('Password changed', ['user_id' => $userId]);
    }

    /** The branch the current session is operating within, or null (super admin, none chosen yet). */
    public static function branchId(): ?int
    {
        $id = Session::get(self::BRANCH_SESSION_KEY);
        return is_int($id) || ctype_digit((string) $id) ? (int) $id : null;
    }

    /**
     * Gate for every branch-scoped route.
     *
     * A signed-in user with no active branch is a super admin — the only
     * role that ever has none, since super admin manages branches
     * (create/lock/delete) and never operates inside one. Sent to the
     * branch picker rather than 403, since that is the expected next step
     * for that identity, not a permissions failure.
     *
     * A branch-scoped user's branch is fixed at login and re-verified as
     * still active on every request here: a super admin locking a branch
     * mid-session must take effect immediately, not just block the next
     * login.
     */
    public static function requireActiveBranch(): void
    {
        self::requireLogin();

        $branchId = self::branchId();
        if ($branchId === null) {
            Http::redirect('/admin/branches');
        }

        $active = Database::instance()->value(
            'SELECT is_active FROM branches WHERE id = :id',
            ['id' => $branchId]
        );
        if ((int) ($active ?? 0) !== 1) {
            Logger::security(
                'Session ended: branch locked mid-session',
                ['user_id' => self::id(), 'branch_id' => $branchId]
            );
            self::logout();
            Session::start();
            Session::flash('error', 'This branch has been locked. Contact your administrator.');
            Http::redirect('/login');
        }
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
            'SELECT id, name, email, role, status, branch_id, must_change_password, created_at
             FROM users WHERE id = :id LIMIT 1',
            ['id' => $id]
        );
        return self::$cached;
    }

    public static function hasRole(string ...$roles): bool
    {
        $user = self::user();
        return $user !== null && in_array((string) ($user['role'] ?? ''), $roles, true);
    }

    /**
     * Gate for protected routes.
     *
     * Also enforces a forced password change: any account created (or
     * reset) with must_change_password=1 — scripts/create-user.php sets
     * this on every account it touches — is redirected to the change
     * screen before it can reach anything else, /logout excepted. The
     * screen itself is excluded by path so this cannot loop.
     */
    public static function requireLogin(): void
    {
        if (!self::check()) {
            Session::flash('error', 'Please sign in to continue.');
            Http::redirect('/login');
        }

        $user = self::user();
        $exempt = in_array(Http::path(), ['/account/password', '/logout'], true);
        if ($user !== null && (int) ($user['must_change_password'] ?? 0) === 1 && !$exempt) {
            Http::redirect('/account/password');
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
