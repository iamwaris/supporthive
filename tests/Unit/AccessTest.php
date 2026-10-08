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

    public function testOnlyAdminOrSuperAdminMayDistributeProfit(): void
    {
        foreach (Access::ALL as $role) {
            if (in_array($role, [Access::ADMIN, Access::SUPER_ADMIN], true)) {
                continue;
            }
            self::assertFalse(
                Access::canDistributeProfit($role),
                "{$role} must not be able to record a payout"
            );
        }
    }

    /**
     * Super admin is not assignable in-app (Access::ASSIGNABLE) — granted by
     * direct DB action only — but once it has switched into a branch
     * (Auth::branchId() non-null) it operates as a full admin of that
     * branch, plus the one ability specific to it: managing branches
     * themselves.
     */
    public function testSuperAdminOperatesAsAFullAdminPlusManagingBranches(): void
    {
        self::assertTrue(Access::canWriteTransactions(Access::SUPER_ADMIN));
        self::assertTrue(Access::canManageMasterData(Access::SUPER_ADMIN));
        self::assertTrue(Access::canAdminister(Access::SUPER_ADMIN));
        self::assertTrue(Access::canDistributeProfit(Access::SUPER_ADMIN));
        self::assertTrue(Access::canViewFinancials(Access::SUPER_ADMIN));
        self::assertTrue(Access::canManageBranches(Access::SUPER_ADMIN));
        self::assertNotContains(Access::SUPER_ADMIN, Access::ASSIGNABLE);
    }

    public function testOnlySuperAdminMayManageBranches(): void
    {
        foreach (Access::ALL as $role) {
            if ($role === Access::SUPER_ADMIN) {
                continue;
            }
            self::assertFalse(Access::canManageBranches($role), "{$role} must not be able to manage branches");
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
            self::assertFalse(Access::canManageBranches($role));
            self::assertFalse(Access::canUseEmployeePortal($role));
            self::assertFalse(Access::canReadEmployeeDocuments($role));
        }
    }

    public function testOnlyTwoRolesAreAssignableInV1(): void
    {
        self::assertSame([Access::ADMIN, Access::PARTNER], Access::ASSIGNABLE);

        foreach (Access::ASSIGNABLE as $role) {
            self::assertContains($role, Access::ALL);
        }
    }

    /**
     * The employee role must never inherit a finance or admin ability. The
     * specific bug this guards: canViewFinancials() used to answer "every
     * known role", so adding employee to ALL alone would have opened every
     * can:view route — dashboard, ledger, reports — to employees.
     */
    public function testEmployeeIsDeniedEveryFinanceAndAdminAbility(): void
    {
        self::assertFalse(Access::canViewFinancials(Access::EMPLOYEE));
        self::assertFalse(Access::canWriteTransactions(Access::EMPLOYEE));
        self::assertFalse(Access::canManageMasterData(Access::EMPLOYEE));
        self::assertFalse(Access::canAdminister(Access::EMPLOYEE));
        self::assertFalse(Access::canDistributeProfit(Access::EMPLOYEE));
        self::assertFalse(Access::canManageBranches(Access::EMPLOYEE));

        foreach (['view', 'write', 'master', 'administer', 'distribute'] as $ability) {
            self::assertFalse(Access::allows($ability, Access::EMPLOYEE), "employee must not have can:{$ability}");
        }
    }

    public function testOnlyEmployeesUseThePortal(): void
    {
        foreach (Access::ALL as $role) {
            self::assertSame(
                $role === Access::EMPLOYEE,
                Access::canUseEmployeePortal($role),
                "portal access for {$role}"
            );
            self::assertSame($role === Access::EMPLOYEE, Access::allows('portal', $role));
        }
    }

    public function testEmployeeDocumentsAreReadableByEmployeesAndTheAdminsWhoPublishThem(): void
    {
        $readers = [Access::EMPLOYEE, Access::ADMIN, Access::SUPER_ADMIN];

        foreach (Access::ALL as $role) {
            $expected = in_array($role, $readers, true);
            self::assertSame($expected, Access::canReadEmployeeDocuments($role), "employee documents for {$role}");
            self::assertSame($expected, Access::allows('employee-documents', $role));
        }
    }

    public function testEmployeesLandOnThePortalAndEveryoneElseOnTheDashboard(): void
    {
        self::assertSame('/portal', Access::landingPath(Access::EMPLOYEE));

        $others = [Access::ADMIN, Access::PARTNER, Access::ACCOUNTANT, Access::DATA_ENTRY, Access::SUPER_ADMIN, ''];
        foreach ($others as $role) {
            self::assertSame('/dashboard', Access::landingPath($role), "landing path for '{$role}'");
        }
    }

    public function testAllowsMatchesEachNamedAbility(): void
    {
        foreach (Access::ALL as $role) {
            self::assertSame(Access::canWriteTransactions($role), Access::allows('write', $role));
            self::assertSame(Access::canManageMasterData($role), Access::allows('master', $role));
            self::assertSame(Access::canAdminister($role), Access::allows('administer', $role));
            self::assertSame(Access::canDistributeProfit($role), Access::allows('distribute', $role));
            self::assertSame(Access::canViewFinancials($role), Access::allows('view', $role));
        }
    }

    /** A typo in a route's can:<ability> must deny, never widen. */
    public function testUnknownAbilityIsDeniedForEveryRole(): void
    {
        foreach (array_merge(Access::ALL, ['', 'agent']) as $role) {
            foreach (['', 'View', 'admin', 'documents', 'portal ', 'employee_documents'] as $ability) {
                self::assertFalse(Access::allows($ability, $role), "can:{$ability} must deny {$role}");
            }
        }
    }

    public function testEmployeeIsAKnownRoleButNotAssignableFromTheUsersScreen(): void
    {
        self::assertContains(Access::EMPLOYEE, Access::ALL);
        self::assertTrue(Access::isKnown(Access::EMPLOYEE));
        self::assertNotContains(Access::EMPLOYEE, Access::ASSIGNABLE);
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
