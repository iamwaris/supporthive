<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Http;
use PHPUnit\Framework\TestCase;

/**
 * The header list every PHP response carries (checked as a list because the
 * CLI SAPI sends no headers), and the log redaction of the joining-link token.
 */
final class HttpCacheAndLogPathTest extends TestCase
{
    public function testEveryResponseTellsLiteSpeedNotToCacheItAndVariesOnTheCookie(): void
    {
        foreach ([true, false] as $production) {
            $headers = Http::securityHeaders($production);

            self::assertContains('X-LiteSpeed-Cache-Control: no-cache', $headers);
            self::assertContains('Vary: Cookie', $headers);
        }
    }

    public function testHstsIsSentInProductionOnly(): void
    {
        $hsts = 'Strict-Transport-Security: max-age=31536000; includeSubDomains; preload';

        self::assertContains($hsts, Http::securityHeaders(true));
        self::assertNotContains($hsts, Http::securityHeaders(false));
    }

    public function testTheJoiningTokenIsRedactedFromALoggedPath(): void
    {
        $token = str_repeat('ab', 32);

        self::assertSame('/join/[redacted]', Http::redactForLog('/join/' . $token));
        self::assertSame('/join/[redacted]?resubmit=1', Http::redactForLog('/join/' . $token . '?resubmit=1'));
        self::assertSame(
            '/LedgerHive/public/join/[redacted]',
            Http::redactForLog('/LedgerHive/public/join/' . $token)
        );
    }

    public function testPathsWithoutASecretAreLoggedAsTheyAre(): void
    {
        foreach (['/join/submitted', '/join', '/joiners/12', '/joiners/link/regenerate', '/login?next=x'] as $path) {
            self::assertSame($path, Http::redactForLog($path));
        }
    }
}
