<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Database-backed fixed-window rate limiter.
 *
 * Apply it to every unauthenticated endpoint that can be brute-forced or abused:
 * login, registration, password reset, contact forms, ticket creation, search.
 * Keys should combine the action with a stable identifier, e.g. "login:" . $email.
 */
final class RateLimiter
{
    public static function tooManyAttempts(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        return self::attempts($key, $decaySeconds) >= $maxAttempts;
    }

    public static function attempts(string $key, int $decaySeconds): int
    {
        return (int) Database::instance()->value(
            'SELECT COUNT(*) FROM rate_limits WHERE limit_key = :k AND created_at > :since',
            ['k' => self::normalise($key), 'since' => date('Y-m-d H:i:s', time() - $decaySeconds)]
        );
    }

    public static function hit(string $key): void
    {
        Database::instance()->insert('rate_limits', [
            'limit_key'  => self::normalise($key),
            'ip'         => Http::clientIp(),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function clear(string $key): void
    {
        Database::instance()->delete('rate_limits', 'limit_key = :k', ['k' => self::normalise($key)]);
    }

    public static function secondsRemaining(string $key, int $decaySeconds): int
    {
        $oldest = Database::instance()->value(
            'SELECT MIN(created_at) FROM rate_limits WHERE limit_key = :k AND created_at > :since',
            ['k' => self::normalise($key), 'since' => date('Y-m-d H:i:s', time() - $decaySeconds)]
        );
        if ($oldest === null) {
            return 0;
        }
        return max(0, $decaySeconds - (time() - strtotime((string) $oldest)));
    }

    /**
     * Guard helper: records the attempt and aborts with 429 once the limit is hit.
     */
    public static function guard(string $key, int $maxAttempts, int $decaySeconds): void
    {
        if (self::tooManyAttempts($key, $maxAttempts, $decaySeconds)) {
            $wait = self::secondsRemaining($key, $decaySeconds);
            Logger::security('Rate limit exceeded', ['key' => $key, 'ip' => Http::clientIp()]);
            header('Retry-After: ' . $wait);
            Http::abort(429, 'Too many attempts. Try again in ' . ceil($wait / 60) . ' minute(s).');
        }
        self::hit($key);
    }

    /** Housekeeping: call from a cron or occasionally at runtime. */
    public static function prune(int $olderThanSeconds = 86400): void
    {
        Database::instance()->delete(
            'rate_limits',
            'created_at < :cutoff',
            ['cutoff' => date('Y-m-d H:i:s', time() - $olderThanSeconds)]
        );
    }

    private static function normalise(string $key): string
    {
        return substr(hash('sha256', strtolower(trim($key))), 0, 64);
    }
}
