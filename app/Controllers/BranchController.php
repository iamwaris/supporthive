<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Http;
use App\Core\Logger;
use App\Core\Session;

/**
 * Branch administration — super admin only (multi-branch retrofit, decision
 * 2026-09-25). Deliberately outside the branch-scoped app: super admin
 * manages branches but never operates inside one (decision 2026-09-26 —
 * there is no "switch into a branch" any more; each branch is signed into
 * directly by its own admin login, which store() creates). None of these
 * actions can go through Router::requireAbility(), which requires an
 * active branch first and super admin never has one.
 */
final class BranchController extends Controller
{
    /**
     * A new branch starts with the same starter category set the very
     * first migration seeded — the alternative, an empty category list,
     * would mean nothing can be recorded on it until someone builds the
     * whole category tree by hand first.
     */
    private const STARTER_CATEGORIES = [
        ['Salaries', 'expense', 10],
        ['Office Rent', 'expense', 20],
        ['Electricity', 'expense', 30],
        ['Internet', 'expense', 40],
        ['Marketing', 'expense', 50],
        ['Transportation', 'expense', 60],
        ['Software', 'expense', 70],
        ['Equipment', 'expense', 80],
        ['Food', 'expense', 90],
        ['Office Supplies', 'expense', 100],
        ['Utilities', 'expense', 110],
        ['Miscellaneous', 'expense', 120],
        ['Service Revenue', 'income', 10],
        ['Product Sales', 'income', 20],
        ['Other Income', 'income', 30],
    ];

    /** Same defaults the very first migration seeded for the original branch. */
    private const DEFAULT_SETTINGS = [
        'fiscal_year_start' => ['7', 'int'],
        'budget_alert_pct' => ['80', 'int'],
        'approval_threshold' => ['0', 'decimal'],
    ];

    public function index(): void
    {
        $branches = Database::instance()->all(
            'SELECT b.*, u.name AS created_by_name,
                    (SELECT COUNT(*) FROM users WHERE branch_id = b.id) AS user_count
             FROM branches b
             LEFT JOIN users u ON u.id = b.created_by
             ORDER BY b.is_active DESC, b.name ASC'
        );

        $this->view('pages/admin/branches', [
            'title' => 'Branches',
            'nav' => 'branches',
            'pageTitle' => 'Branches',
            'pageMeta' => 'Create, lock or delete a branch',
            'branches' => $branches,
        ]);
    }

    /**
     * Creates the branch, its starter categories and settings, and its
     * first admin login in one transaction — a branch with no way to sign
     * into it is not usable, so the two are never allowed to happen apart.
     * The admin's password is a generated one-time credential, shown once
     * on success: the same forced-change treatment scripts/create-user.php
     * gives every account it creates (must_change_password = 1).
     */
    public function store(): void
    {
        $clean = $this->validate([
            'name' => 'required|max:120',
            'currency_code' => 'nullable|max:10',
            'currency_symbol' => 'nullable|max:10',
            'admin_name' => 'required|max:120',
            'admin_email' => 'required|email|max:190',
        ], '/admin/branches');

        $name = trim((string) $clean['name']);
        $currencyCode = $clean['currency_code'] !== null ? trim((string) $clean['currency_code']) : '';
        $currencySymbol = $clean['currency_symbol'] !== null ? trim((string) $clean['currency_symbol']) : '';
        $adminName = trim((string) $clean['admin_name']);
        $adminEmail = strtolower(trim((string) $clean['admin_email']));

        $db = Database::instance();
        $errors = [];

        if ((int) $db->value('SELECT COUNT(*) FROM branches WHERE name = :name', ['name' => $name]) > 0) {
            $errors['name'] = ['A branch with that name already exists.'];
        }
        if ((int) $db->value('SELECT COUNT(*) FROM users WHERE email = :email', ['email' => $adminEmail]) > 0) {
            $errors['admin_email'] = ['That email is already in use by another account.'];
        }

        if ($errors !== []) {
            Session::set('_old', $_POST);
            Session::flash('errors', $errors);
            Session::flash('error', 'Please correct the highlighted fields.');
            Http::redirect('/admin/branches');
        }

        $createdBy = Auth::id();
        $tempPassword = bin2hex(random_bytes(9));

        [$branchId, $adminId] = Database::instance()->transaction(
            static function (Database $db) use (
                $name,
                $currencyCode,
                $currencySymbol,
                $adminName,
                $adminEmail,
                $createdBy,
                $tempPassword
            ): array {
                $branchId = $db->insert('branches', [
                    'name' => $name,
                    'is_active' => 1,
                    'created_by' => $createdBy,
                ]);

                foreach (self::STARTER_CATEGORIES as [$categoryName, $type, $sortOrder]) {
                    $db->insert('categories', [
                        'branch_id' => $branchId,
                        'name' => $categoryName,
                        'type' => $type,
                        'parent_id' => null,
                        'is_active' => 1,
                        'sort_order' => $sortOrder,
                    ]);
                }

                $settings = self::DEFAULT_SETTINGS + [
                    'company_name' => [$name, 'string'],
                    'currency_code' => [$currencyCode !== '' ? $currencyCode : 'PKR', 'string'],
                    'currency_symbol' => [$currencySymbol !== '' ? $currencySymbol : 'Rs', 'string'],
                ];
                foreach ($settings as $key => [$value, $type]) {
                    $db->insert('settings', [
                        'branch_id' => $branchId,
                        'setting_key' => $key,
                        'setting_value' => $value,
                        'value_type' => $type,
                        'updated_by' => $createdBy,
                    ]);
                }

                $adminId = $db->insert('users', [
                    'name' => $adminName,
                    'email' => $adminEmail,
                    'password_hash' => Auth::hash($tempPassword),
                    'role' => 'admin',
                    'branch_id' => $branchId,
                    'status' => 'active',
                    'must_change_password' => 1,
                ]);

                return [$branchId, $adminId];
            }
        );

        Logger::security('Branch created', [
            'branch_id' => $branchId, 'name' => $name, 'admin_user_id' => $adminId, 'by' => $createdBy,
        ]);

        Session::flash(
            'success',
            $name . ' created. Admin login: ' . $adminEmail . ' / temporary password: ' . $tempPassword
            . ' — this is shown once; the account must change it on first sign-in.'
        );
        Http::redirect('/admin/branches');
    }

    /**
     * Locks or unlocks a branch. A locked branch's users cannot sign in
     * (Auth::attempt()) and any of their sessions already open are ended on
     * their next request (Auth::requireActiveBranch()) — this is meant to
     * take effect immediately, not just block future logins.
     *
     * @param array<string,string> $params
     */
    public function toggleActive(array $params): void
    {
        $branchId = (int) ($params['id'] ?? 0);
        $branch = Database::instance()->first(
            'SELECT id, name, is_active FROM branches WHERE id = :id',
            ['id' => $branchId]
        );

        if ($branch === null) {
            Session::flash('error', 'That branch no longer exists.');
            Http::redirect('/admin/branches');
        }

        $wasActive = (int) $branch['is_active'] === 1;
        Database::instance()->update('branches', ['is_active' => $wasActive ? 0 : 1], 'id = :id', ['id' => $branchId]);

        Logger::security($wasActive ? 'Branch locked' : 'Branch unlocked', [
            'branch_id' => $branchId, 'name' => $branch['name'], 'by' => Auth::id(),
        ]);
        Session::flash(
            'success',
            (string) $branch['name'] . ($wasActive ? ' locked. Its users can no longer sign in.' : ' unlocked.')
        );
        Http::redirect('/admin/branches');
    }

    /**
     * Permanently deletes a branch and everything in it. Confirmed two
     * ways: typing the branch's name exactly (a checkbox is too easy to
     * click past on an action this destructive) and the acting super
     * admin's own current password (so a hijacked or left-open session
     * cannot wipe a branch without the password that unlocked it).
     *
     * Deletion order respects the FK graph rather than relying on
     * ON DELETE CASCADE everywhere: profit_distributions before
     * partners/accounts (its own FKs to them are RESTRICT), transactions
     * before accounts/categories/partners (same reason — its expenses/sales
     * satellites do cascade automatically). categories cascades to budgets
     * and partners cascades to partner_shares via the FKs those tables
     * already had before this feature existed. audit_log is detached
     * (branch_id set NULL) rather than deleted — an audit trail outliving
     * the thing it records is the point of having one.
     *
     * @param array<string,string> $params
     */
    public function destroy(array $params): void
    {
        $branchId = (int) ($params['id'] ?? 0);
        $confirmName = trim((string) ($_POST['confirm_name'] ?? ''));
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        $branch = Database::instance()->first('SELECT id, name FROM branches WHERE id = :id', ['id' => $branchId]);
        if ($branch === null) {
            Session::flash('error', 'That branch no longer exists.');
            Http::redirect('/admin/branches');
        }

        $name = (string) $branch['name'];
        if ($confirmName !== $name) {
            Session::flash('error', 'Type the branch name exactly to confirm deletion. Nothing was deleted.');
            Http::redirect('/admin/branches');
        }

        $deletedBy = Auth::id();
        $ownHash = Database::instance()->value('SELECT password_hash FROM users WHERE id = :id', ['id' => $deletedBy]);
        if (!is_string($ownHash) || !password_verify($confirmPassword, $ownHash)) {
            Logger::security('Branch deletion denied: wrong password', ['branch_id' => $branchId, 'by' => $deletedBy]);
            Session::flash('error', 'Your password was not correct. Nothing was deleted.');
            Http::redirect('/admin/branches');
        }

        Database::instance()->transaction(static function (Database $db) use ($branchId): void {
            $db->run('UPDATE audit_log SET branch_id = NULL WHERE branch_id = :id', ['id' => $branchId]);
            $db->delete('profit_distributions', 'branch_id = :id', ['id' => $branchId]);
            $db->delete('transactions', 'branch_id = :id', ['id' => $branchId]);
            $db->delete('categories', 'branch_id = :id', ['id' => $branchId]);
            $db->delete('partners', 'branch_id = :id', ['id' => $branchId]);
            $db->delete('accounts', 'branch_id = :id', ['id' => $branchId]);
            $db->delete('customers', 'branch_id = :id', ['id' => $branchId]);
            $db->delete('settings', 'branch_id = :id', ['id' => $branchId]);
            $db->delete('users', 'branch_id = :id', ['id' => $branchId]);
            $db->delete('branches', 'id = :id', ['id' => $branchId]);
        });

        Logger::security('Branch deleted', ['branch_id' => $branchId, 'name' => $name, 'by' => $deletedBy]);
        Session::flash('success', $name . ' and everything in it has been permanently deleted.');
        Http::redirect('/admin/branches');
    }
}
