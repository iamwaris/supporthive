<?php

declare(strict_types=1);

namespace App\Core;

/**
 * "Remember me on this device": a persistent login cookie using the
 * split-token pattern.
 *
 * The cookie holds `selector:validator`. The selector is a random lookup key
 * stored as-is; the validator is a 32-byte secret stored only as its sha256,
 * so a leaked remember_tokens table cannot be replayed as a login. Lookup is
 * by selector (an indexed equality, so no timing signal about the secret) and
 * the validator is compared with hash_equals().
 *
 * Every successful use rotates the token: the presented row is marked
 * consumed (not deleted) and a new selector and validator are issued, so a
 * cookie signs in exactly once. Marking is a conditional UPDATE whose
 * affected-row count must be 1, so two requests racing on the same cookie
 * cannot both win.
 *
 * Theft detection. Keeping the consumed row is what makes a stolen cookie
 * detectable: whichever of the thief and the victim uses it second presents
 * a selector that is already consumed. Later than REPLAY_GRACE_SECONDS after
 * the first use, that means the cookie was used on two devices, so every
 * token for that user is revoked and the event goes to the security log.
 * Inside the grace window a replay only fails, with nothing revoked: that is
 * the owner's own browser opening two tabs at once after an idle timeout,
 * both carrying the same cookie, which must not sign the owner out
 * everywhere. A selector found with the wrong validator (a forged or
 * partly-copied cookie) revokes everything too. Consumed rows are kept until
 * their own expiry so a replay is still recognised days later; prune()
 * removes them then.
 *
 * The cookie only ever restores a session through Auth::login(), so session
 * id rotation, CSRF token rotation, branch assignment and the audit row are
 * identical to a password login; Auth::mayStillSignIn() applies the same
 * active-account and unlocked-branch gates Auth::attempt() does. The
 * forced-password-change redirect is unaffected because Auth::requireLogin()
 * re-reads that flag on every request.
 */
final class RememberMe
{
    public const COOKIE = 'shremember';

    private const SELECTOR_BYTES = 12;
    private const VALIDATOR_BYTES = 32;
    private const COOKIE_PATTERN = '/^([a-f0-9]{24}):([a-f0-9]{64})\z/';

    /** How long after a token's use a second use of it is treated as a benign tab race. */
    public const REPLAY_GRACE_SECONDS = 60;

    /**
     * Restore a signed-out request from the remember cookie, if it carries one.
     *
     * Safe methods only. That keeps it off the login POST itself and away
     * from CSRF entirely: Auth::login() rotates the CSRF token, which would
     * otherwise reject the very POST that triggered the restore. A POST that
     * arrives after the session has idled out fails CSRF exactly as it did
     * before this feature, and the next GET (the /login redirect) restores.
     */
    public static function restoreSession(): void
    {
        if (Auth::check() || !in_array(Http::method(), ['GET', 'HEAD'], true)) {
            return;
        }
        if (!array_key_exists(self::COOKIE, $_COOKIE)) {
            return;
        }

        $cookie = $_COOKIE[self::COOKIE];
        $restored = is_string($cookie) ? self::consume($cookie, self::userAgent()) : null;

        if ($restored === null) {
            self::clearCookie();
            return;
        }

        Auth::login($restored['user_id']);
        self::writeCookie($restored['cookie']);
        Logger::security('Session restored from remember-me token', [
            'user_id' => $restored['user_id'],
            'ip' => Http::clientIp(),
        ]);
    }

    /** Issue a token for $userId and set it on this device. Called after a successful password login. */
    public static function remember(int $userId): void
    {
        self::writeCookie(self::issue($userId, self::userAgent()));
    }

    /**
     * Revoke this device's token, if it has one, and clear the cookie.
     *
     * With a signed-in user the delete is limited to that user's own rows:
     * a cookie left behind by a different account (or planted) is cleared
     * from this browser but never deletes another user's token.
     */
    public static function forgetCurrentDevice(): void
    {
        if (!array_key_exists(self::COOKIE, $_COOKIE)) {
            return;
        }

        $cookie = $_COOKIE[self::COOKIE];
        $parts = is_string($cookie) ? self::parse($cookie) : null;
        if ($parts !== null) {
            $userId = Auth::id();
            if ($userId === null) {
                Database::instance()->delete(
                    'remember_tokens',
                    'selector = :selector',
                    ['selector' => $parts['selector']]
                );
            } else {
                Database::instance()->delete(
                    'remember_tokens',
                    'selector = :selector AND user_id = :user_id',
                    ['selector' => $parts['selector'], 'user_id' => $userId]
                );
            }
        }
        self::clearCookie();
    }

    /**
     * True when this request carries a remember cookie. Used to re-issue one
     * for the device that just changed its own password, after every other
     * device's token has been revoked.
     */
    public static function deviceIsRemembered(): bool
    {
        $cookie = $_COOKIE[self::COOKIE] ?? null;
        return is_string($cookie) && self::parse($cookie) !== null;
    }

    /**
     * Create a token row and return the cookie value. The raw validator
     * exists only in the return value — it is never stored or logged.
     */
    public static function issue(int $userId, string $userAgent): string
    {
        $selector = bin2hex(random_bytes(self::SELECTOR_BYTES));
        $validator = bin2hex(random_bytes(self::VALIDATOR_BYTES));
        $now = time();

        Database::instance()->insert('remember_tokens', [
            'user_id' => $userId,
            'selector' => $selector,
            'validator_hash' => hash('sha256', $validator),
            'expires_at' => date('Y-m-d H:i:s', $now + self::lifetimeSeconds()),
            'last_used_at' => date('Y-m-d H:i:s', $now),
            'user_agent' => $userAgent === '' ? null : mb_substr($userAgent, 0, 255),
        ]);

        return $selector . ':' . $validator;
    }

    /**
     * Verify a cookie value and, if it is good, rotate it.
     *
     * Returns the user id and the replacement cookie value, or null for any
     * failure; the caller clears the cookie either way. The presented token
     * is spent before the caller logs the user in, so it is used up even if
     * something later in the request fails. See the class docblock for how a
     * reused token is told apart from a tab race.
     *
     * @return array{user_id:int, cookie:string}|null
     */
    public static function consume(string $cookie, string $userAgent): ?array
    {
        $parts = self::parse($cookie);
        if ($parts === null) {
            return null;
        }

        $db = Database::instance();
        $row = $db->first(
            'SELECT id, user_id, validator_hash, expires_at, consumed_at
             FROM remember_tokens WHERE selector = :selector LIMIT 1',
            ['selector' => $parts['selector']]
        );
        if ($row === null) {
            return null;
        }

        $userId = (int) $row['user_id'];

        if (!hash_equals((string) $row['validator_hash'], hash('sha256', $parts['validator']))) {
            self::revokeAfterSuspectedTheft($userId, 'Remember-me validator mismatch, possible forged cookie');
            return null;
        }

        if ($row['consumed_at'] !== null) {
            if (time() - (int) strtotime((string) $row['consumed_at']) > self::REPLAY_GRACE_SECONDS) {
                self::revokeAfterSuspectedTheft(
                    $userId,
                    'Remember-me token reused after rotation, possible stolen cookie'
                );
            }
            return null;
        }

        if (strtotime((string) $row['expires_at']) <= time() || !Auth::mayStillSignIn($userId)) {
            $db->delete('remember_tokens', 'id = :id', ['id' => $row['id']]);
            return null;
        }

        // The atomic step: of any requests racing on this cookie, exactly
        // one sees an affected-row count of 1. The others fail quietly, and
        // any later replay finds consumed_at set.
        $claimed = $db->run(
            'UPDATE remember_tokens SET consumed_at = :now WHERE id = :id AND consumed_at IS NULL',
            ['now' => date('Y-m-d H:i:s'), 'id' => $row['id']]
        )->rowCount();
        if ($claimed !== 1) {
            return null;
        }

        return ['user_id' => $userId, 'cookie' => self::issue($userId, $userAgent)];
    }

    /**
     * Ends "remember me" on every device. Called on every event that must
     * sign a user out everywhere: password change or reset, deactivation,
     * role change.
     */
    public static function revokeAllForUser(int $userId): int
    {
        return Database::instance()->delete('remember_tokens', 'user_id = :user_id', ['user_id' => $userId]);
    }

    /**
     * Delete expired rows, consumed ones included. Run from
     * scripts/maintenance.php.
     *
     * A consumed row is kept until its own expires_at rather than dropped as
     * soon as the grace window passes: it is the record that lets a replay
     * of a stolen cookie be recognised, and the owner's stale copy may come
     * back days later. Past expires_at that cookie could not sign in anyway.
     */
    public static function prune(): int
    {
        return Database::instance()->delete(
            'remember_tokens',
            'expires_at <= :now',
            ['now' => date('Y-m-d H:i:s')]
        );
    }

    private static function revokeAfterSuspectedTheft(int $userId, string $message): void
    {
        $revoked = self::revokeAllForUser($userId);
        // Never the cookie or any part of it.
        Logger::security($message . ': all tokens revoked', [
            'user_id' => $userId,
            'revoked_count' => $revoked,
            'ip' => Http::clientIp(),
        ]);
    }

    /**
     * Split a cookie value into its two halves, or null if it is not exactly
     * the shape issue() produces.
     *
     * @return array{selector:string, validator:string}|null
     */
    public static function parse(string $cookie): ?array
    {
        if (preg_match(self::COOKIE_PATTERN, $cookie, $matches) !== 1) {
            return null;
        }

        return ['selector' => $matches[1], 'validator' => $matches[2]];
    }

    public static function lifetimeSeconds(): int
    {
        return max(1, (int) Config::get('session.remember_days', 30)) * 86400;
    }

    /**
     * Secure whenever the deployment is configured as HTTPS (SESSION_SECURE,
     * the flag the session cookie already uses and preflight.php requires in
     * production) or this particular request arrived over TLS. Either is
     * enough; neither can be switched off by the client.
     */
    public static function cookieIsSecure(): bool
    {
        $https = (string) ($_SERVER['HTTPS'] ?? '');

        return Config::get('session.secure', false) === true || ($https !== '' && strtolower($https) !== 'off');
    }

    /**
     * $_COOKIE is updated alongside the header so the rest of this request
     * (a logout, a password change) sees the device's current token. The
     * headers_sent() guard is for the CLI test runner, where output has
     * already started; a web request reaches here before any output.
     */
    private static function writeCookie(string $value): void
    {
        if (!headers_sent()) {
            setcookie(self::COOKIE, $value, self::cookieOptions(time() + self::lifetimeSeconds()));
        }
        $_COOKIE[self::COOKIE] = $value;
    }

    private static function clearCookie(): void
    {
        if (!headers_sent()) {
            setcookie(self::COOKIE, '', self::cookieOptions(time() - 42000));
        }
        unset($_COOKIE[self::COOKIE]);
    }

    /** @return array{expires:int, path:string, domain:string, secure:bool, httponly:bool, samesite:string} */
    private static function cookieOptions(int $expires): array
    {
        return [
            'expires' => $expires,
            'path' => '/',
            'domain' => '',
            'secure' => self::cookieIsSecure(),
            'httponly' => true,
            'samesite' => (string) Config::get('session.samesite', 'Lax'),
        ];
    }

    private static function userAgent(): string
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        return is_string($userAgent) ? $userAgent : '';
    }
}
