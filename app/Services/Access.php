<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Who may do what, as pure functions of a role.
 *
 * The rules live here rather than inline in middleware for one reason: this is
 * testable. `Auth::requireRole()` ends the request, which makes the decision
 * itself awkward to assert; a function returning a boolean is not.
 *
 * V1 has two assignable roles. `accountant` and `data_entry` exist in the
 * schema so adding them later is data rather than a migration, and they are
 * answered here already so a half-configured user cannot fall through to
 * "allowed" by accident.
 *
 * `super_admin` (decision 2026-09-25, multi-branch retrofit) is not
 * assignable in-app either — it is granted by direct DB action only, since
 * there is no user-management UI yet to assign it from. It answers true to
 * every admin-shaped ability here because once it has switched into a branch
 * (`Auth::branchId()` non-null) it operates as a full admin of that branch;
 * `canManageBranches()` is the one ability specific to it.
 *
 * `employee` (decision 2026-10-08) is a self-service role with no access to
 * the books at all: it reads its own profile and the documents its branch's
 * admins publish, nothing else. Every finance/admin ability below therefore
 * lists its roles explicitly rather than deriving from ALL, so adding a role
 * to the schema can never widen what it may reach.
 */
final class Access
{
    public const ADMIN = 'admin';
    public const PARTNER = 'partner';
    public const ACCOUNTANT = 'accountant';
    public const DATA_ENTRY = 'data_entry';
    public const SUPER_ADMIN = 'super_admin';
    public const EMPLOYEE = 'employee';

    /**
     * Roles an administrator may switch an existing login between on the
     * Users screen. Employees are deliberately absent: a login's role must
     * never cross the employee boundary, because the employee profile row is
     * written only when the login is created (see CREATABLE).
     */
    public const ASSIGNABLE = [self::ADMIN, self::PARTNER];

    /**
     * Roles the Users screen's "Add user" form may create. Wider than
     * ASSIGNABLE on purpose: a new employee is created with its profile row
     * through App\Services\EmployeeOnboarding, but an existing login's role
     * may never be edited across the employee boundary — that would leave a
     * profile row orphaned, or an employee login with none.
     */
    public const CREATABLE = [self::ADMIN, self::PARTNER, self::EMPLOYEE];

    /** Every role the schema permits, assignable or not. */
    public const ALL = [
        self::ADMIN, self::PARTNER, self::ACCOUNTANT, self::DATA_ENTRY, self::SUPER_ADMIN, self::EMPLOYEE,
    ];

    /**
     * May this role create, edit or void financial records?
     *
     * Partners can record and void transactions alongside admin, accountant
     * and data-entry (decision 2026-09-24: widened from read-only). Master
     * data (accounts, categories, partners, customers, budgets) and profit
     * distribution stay admin-only — see canManageMasterData() and
     * canDistributeProfit().
     */
    public static function canWriteTransactions(string $role): bool
    {
        return in_array(
            $role,
            [self::ADMIN, self::PARTNER, self::ACCOUNTANT, self::DATA_ENTRY, self::SUPER_ADMIN],
            true
        );
    }

    /** May this role change master data — partners, categories, accounts? */
    public static function canManageMasterData(string $role): bool
    {
        return in_array($role, [self::ADMIN, self::SUPER_ADMIN], true);
    }

    /** May this role manage users, settings and other configuration? */
    public static function canAdminister(string $role): bool
    {
        return in_array($role, [self::ADMIN, self::SUPER_ADMIN], true);
    }

    /** May this role approve or record a profit distribution? */
    public static function canDistributeProfit(string $role): bool
    {
        return in_array($role, [self::ADMIN, self::SUPER_ADMIN], true);
    }

    /**
     * May this role read financial data at all?
     *
     * An explicit list, never self::ALL: employee is a known role that must
     * not see the books, and every `can:view` route depends on this answer.
     */
    public static function canViewFinancials(string $role): bool
    {
        return in_array(
            $role,
            [self::ADMIN, self::PARTNER, self::ACCOUNTANT, self::DATA_ENTRY, self::SUPER_ADMIN],
            true
        );
    }

    /** May this role use the employee self-service portal? Employees only. */
    public static function canUseEmployeePortal(string $role): bool
    {
        return $role === self::EMPLOYEE;
    }

    /**
     * May this role open (preview/download) an employee document? Employees
     * read them; the admins who publish them must be able to check what they
     * uploaded.
     */
    public static function canReadEmployeeDocuments(string $role): bool
    {
        return in_array($role, [self::EMPLOYEE, self::ADMIN, self::SUPER_ADMIN], true);
    }

    /** Where a signed-in user of this role lands after sign-in or at "/". */
    public static function landingPath(string $role): string
    {
        return $role === self::EMPLOYEE ? '/portal' : '/dashboard';
    }

    /**
     * The `can:<ability>` route gate. Unknown abilities deny — a typo in a
     * route's middleware must never widen access.
     */
    public static function allows(string $ability, string $role): bool
    {
        return match ($ability) {
            'write'              => self::canWriteTransactions($role),
            'master'             => self::canManageMasterData($role),
            'administer'         => self::canAdminister($role),
            'distribute'         => self::canDistributeProfit($role),
            'view'               => self::canViewFinancials($role),
            'portal'             => self::canUseEmployeePortal($role),
            'employee-documents' => self::canReadEmployeeDocuments($role),
            default              => false,
        };
    }

    /** May this role create/list branches and switch between them? Super admin only. */
    public static function canManageBranches(string $role): bool
    {
        return $role === self::SUPER_ADMIN;
    }

    /**
     * Unknown roles are denied everything. A typo in the database, or a role
     * added to the enum but not considered here, must fail closed.
     */
    public static function isKnown(string $role): bool
    {
        return in_array($role, self::ALL, true);
    }
}
