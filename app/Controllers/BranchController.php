<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Http;
use App\Core\Logger;
use App\Core\Session;
use RuntimeException;

/**
 * Branch administration — super admin only (multi-branch retrofit, decision
 * 2026-09-25). Deliberately outside the branch-scoped app: these actions
 * either have no branch yet (index/store) or change which branch the
 * session is in (switchTo/exit), so none of them can go through
 * Router::requireAbility(), which now requires an active branch first.
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
            'pageMeta' => Auth::branchId() === null
                ? 'Pick a branch to work in, or create a new one'
                : null,
            'branches' => $branches,
            'activeBranchId' => Auth::branchId(),
        ]);
    }

    public function store(): void
    {
        $clean = $this->validate([
            'name' => 'required|max:120',
        ], '/admin/branches');

        $name = trim((string) $clean['name']);
        $exists = (int) Database::instance()->value(
            'SELECT COUNT(*) FROM branches WHERE name = :name',
            ['name' => $name]
        ) > 0;

        if ($exists) {
            Session::flash('errors', ['name' => ['A branch with that name already exists.']]);
            Session::flash('error', 'That branch already exists.');
            Http::redirect('/admin/branches');
        }

        $createdBy = Auth::id();
        $branchId = (int) Database::instance()->transaction(
            static function (Database $db) use ($name, $createdBy): int {
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

                return $branchId;
            }
        );

        Logger::security('Branch created', ['branch_id' => $branchId, 'name' => $name, 'by' => Auth::id()]);
        Session::flash('success', $name . ' created. Switch into it to start recording.');
        Http::redirect('/admin/branches');
    }

    /** @param array<string,string> $params */
    public function switchTo(array $params): void
    {
        $branchId = (int) ($params['id'] ?? 0);

        try {
            Auth::switchBranch($branchId);
        } catch (RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            Http::redirect('/admin/branches');
        }

        Http::redirect('/dashboard');
    }

    public function exit(): void
    {
        Auth::exitBranch();
        Http::redirect('/admin/branches');
    }
}
