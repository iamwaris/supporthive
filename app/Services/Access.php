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
 */
final class Access
{
    public const ADMIN = 'admin';
    public const PARTNER = 'partner';
    public const ACCOUNTANT = 'accountant';
    public const DATA_ENTRY = 'data_entry';
    public const SUPER_ADMIN = 'super_admin';

    /** Roles an administrator may actually assign in V1. */
    public const ASSIGNABLE = [self::ADMIN, self::PARTNER];

    /** Every role the schema permits, assignable or not. */
    public const ALL = [self::ADMIN, self::PARTNER, self::ACCOUNTANT, self::DATA_ENTRY, self::SUPER_ADMIN];

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

    /** May this role read financial data at all? */
    public static function canViewFinancials(string $role): bool
    {
        return in_array($role, self::ALL, true);
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
