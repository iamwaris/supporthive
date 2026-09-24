<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Access;
use PHPUnit\Framework\TestCase;

/**
 * The access policy is the only thing standing between a partner and the
 * company's books, so every rule is asserted rather than assumed.
 */
final class AccessTest extends TestCase
{
    public function testAdminCanDoEverything(): void
    {
        self::assertTrue(Access::canWriteTransactions(Access::ADMIN));
        self::assertTrue(Access::canManageMasterData(Access::ADMIN));
        self::assertTrue(Access::canAdminister(Access::ADMIN));
        self::assertTrue(Access::canDistributeProfit(Access::ADMIN));
        self::assertTrue(Access::canViewFinancials(Access::ADMIN));
    }

    public function testPartnerCanWriteTransactionsButNotMasterDataOrAdmin(): void
    {
        self::assertTrue(Access::canViewFinancials(Access::PARTNER), 'partners must be able to read');
        self::assertTrue(Access::canWriteTransactions(Access::PARTNER), 'partners record/edit/void transactions');
        self::assertFalse(Access::canManageMasterData(Access::PARTNER));
        self::assertFalse(Access::canAdminister(Access::PARTNER));
        self::assertFalse(Access::canDistributeProfit(Access::PARTNER));
    }

    public function testOnlyAdminMayDistributeProfit(): void
    {
        foreach (Access::ALL as $role) {
            if ($role === Access::ADMIN) {
                continue;
            }
            self::assertFalse(
                Access::canDistributeProfit($role),
                "{$role} must not be able to record a payout"
            );
        }
    }

    /**
     * Fail closed. A role typo in the database, or one added to the enum but
     * never considered here, must be denied everything rather than inherit
     * permissions by accident.
     */
    public function testUnknownRoleIsDeniedEverything(): void
    {
        foreach (['', 'Admin', 'superuser', 'agent', 'customer'] as $role) {
            self::assertFalse(Access::isKnown($role), "{$role} should not be a known role");
            self::assertFalse(Access::canWriteTransactions($role));
            self::assertFalse(Access::canManageMasterData($role));
            self::assertFalse(Access::canAdminister($role));
            self::assertFalse(Access::canDistributeProfit($role));
            self::assertFalse(Access::canViewFinancials($role));
        }
    }

    public function testOnlyTwoRolesAreAssignableInV1(): void
    {
        self::assertSame([Access::ADMIN, Access::PARTNER], Access::ASSIGNABLE);

        foreach (Access::ASSIGNABLE as $role) {
            self::assertContains($role, Access::ALL);
        }
    }

    public function testFutureRolesAreAlreadyAnswered(): void
    {
        // These are in the schema but not assignable. They must still have a
        // defined answer, so enabling one later is a deliberate decision.
        self::assertTrue(Access::canWriteTransactions(Access::ACCOUNTANT));
        self::assertFalse(Access::canAdminister(Access::ACCOUNTANT));
        self::assertTrue(Access::canWriteTransactions(Access::DATA_ENTRY));
        self::assertFalse(Access::canManageMasterData(Access::DATA_ENTRY));
    }
}
