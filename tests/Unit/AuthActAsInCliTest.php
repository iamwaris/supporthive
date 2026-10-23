<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Auth;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Auth::actAsInCli() (used by the AR/AP backfill) must hand back the
 * previous identity however the work ends, so one failing entry can never
 * leave the next one attributed to the wrong user or branch.
 */
final class AuthActAsInCliTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testTheIdentityIsSwappedForTheWorkAndRestoredAfter(): void
    {
        $_SESSION = ['_auth_user_id' => 5, '_active_branch_id' => 9];

        $seen = Auth::actAsInCli(11, 22, static fn (): array => [Auth::id(), Auth::branchId()]);

        self::assertSame([11, 22], $seen);
        self::assertSame(['_auth_user_id' => 5, '_active_branch_id' => 9], $_SESSION);
    }

    public function testTheIdentityIsRestoredWhenTheWorkThrows(): void
    {
        $_SESSION = ['_auth_user_id' => 5, '_active_branch_id' => 9];

        try {
            Auth::actAsInCli(11, 22, static function (): never {
                throw new RuntimeException('entry failed');
            });
            self::fail('the exception must propagate');
        } catch (RuntimeException $e) {
            self::assertSame('entry failed', $e->getMessage());
        }

        self::assertSame(5, Auth::id());
        self::assertSame(9, Auth::branchId());
    }

    public function testNoPreviousIdentityMeansNoneIsLeftBehind(): void
    {
        $_SESSION = [];

        try {
            Auth::actAsInCli(11, 22, static function (): never {
                throw new RuntimeException('entry failed');
            });
        } catch (RuntimeException) {
            // expected
        }

        self::assertSame([], $_SESSION);
        self::assertNull(Auth::id());
    }
}
