<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Core\RateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * M7-6 (pre-launch security checklist) calls for rate limits "verified by
 * actually exceeding them" — this had never been tested at all, despite
 * CLAUDE.md rule 9 requiring every unauthenticated endpoint to use it and
 * AuthController relying on it for login brute-force protection. Exercises
 * the limiter directly rather than the login endpoint, since the endpoint
 * itself is a thin wrapper around exactly this class.
 */
final class RateLimiterTest extends TestCase
{
    private const KEY = 'rl-test:someone@example.com';

    protected function setUp(): void
    {
        RateLimiter::clear(self::KEY);
    }

    protected function tearDown(): void
    {
        RateLimiter::clear(self::KEY);
    }

    public function testAttemptsCountsHitsWithinTheWindow(): void
    {
        self::assertSame(0, RateLimiter::attempts(self::KEY, 900));

        RateLimiter::hit(self::KEY);
        RateLimiter::hit(self::KEY);

        self::assertSame(2, RateLimiter::attempts(self::KEY, 900));
    }

    public function testTooManyAttemptsTripsOnlyAtTheConfiguredThreshold(): void
    {
        for ($i = 0; $i < 5; $i++) {
            self::assertFalse(
                RateLimiter::tooManyAttempts(self::KEY, 5, 900),
                'must not trip before the 5th recorded attempt'
            );
            RateLimiter::hit(self::KEY);
        }

        self::assertTrue(
            RateLimiter::tooManyAttempts(self::KEY, 5, 900),
            'the 5th recorded attempt must trip a max of 5'
        );
    }

    public function testClearResetsTheCounter(): void
    {
        RateLimiter::hit(self::KEY);
        RateLimiter::hit(self::KEY);
        self::assertTrue(RateLimiter::tooManyAttempts(self::KEY, 2, 900));

        RateLimiter::clear(self::KEY);

        self::assertSame(0, RateLimiter::attempts(self::KEY, 900));
        self::assertFalse(RateLimiter::tooManyAttempts(self::KEY, 2, 900));
    }

    public function testAttemptsOutsideTheDecayWindowDoNotCount(): void
    {
        Database::instance()->insert('rate_limits', [
            'limit_key' => hash('sha256', strtolower(trim(self::KEY))),
            'ip' => '127.0.0.1',
            'created_at' => date('Y-m-d H:i:s', time() - 3600),
        ]);

        // A one-hour-old attempt must not count against a 15-minute window.
        self::assertSame(0, RateLimiter::attempts(self::KEY, 900));
    }

    public function testSecondsRemainingCountsDownFromTheOldestAttemptInWindow(): void
    {
        RateLimiter::hit(self::KEY);

        $remaining = RateLimiter::secondsRemaining(self::KEY, 900);

        self::assertGreaterThan(0, $remaining);
        self::assertLessThanOrEqual(900, $remaining);
    }

    public function testSecondsRemainingIsZeroWithNoAttempts(): void
    {
        self::assertSame(0, RateLimiter::secondsRemaining(self::KEY, 900));
    }

    public function testPruneRemovesOnlyAttemptsOlderThanTheCutoff(): void
    {
        $db = Database::instance();
        $hashedKey = hash('sha256', strtolower(trim(self::KEY)));

        $db->insert('rate_limits', [
            'limit_key' => $hashedKey, 'ip' => '127.0.0.1',
            'created_at' => date('Y-m-d H:i:s', time() - 200000),
        ]);
        RateLimiter::hit(self::KEY);

        RateLimiter::prune(86400);

        self::assertSame(
            1,
            RateLimiter::attempts(self::KEY, 999999),
            'only the fresh hit should survive a prune with an 86400s cutoff'
        );
    }
}
