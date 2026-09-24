<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Domain\TransactionType;
use App\Services\Audit;
use App\Services\TransactionService;
use PHPUnit\Framework\TestCase;
use Tests\Support\BranchFixture;

/**
 * App\Services\Audit — M7-3 (wiring the audit trail to every write path).
 *
 * Regression test for a bug found while doing that work: Audit::record()
 * inserted into audit_log without ever setting branch_id, even though the
 * column exists specifically so a future per-branch audit viewer (M7-4) has
 * something to scope its query on. Every audit row written before this fix —
 * including the ones TransactionService and ProfitDistributionService were
 * already producing — silently landed with branch_id = NULL.
 */
final class AuditTest extends TestCase
{
    private int $branchAId;
    private int $branchBId;
    private int $userAId;
    private int $userBId;
    private int $bankAId;
    private int $categoryAId;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->cleanUp();

        $db = Database::instance();

        $this->branchAId = BranchFixture::create('AUD-A');
        $this->branchBId = BranchFixture::create('AUD-B');

        $this->userAId = $db->insert('users', [
            'name' => 'Aud Tester A',
            'email' => 'aud-tester-a@test.local',
            'password_hash' => password_hash('unused-in-this-test', PASSWORD_DEFAULT),
            'role' => 'admin',
            'branch_id' => $this->branchAId,
            'status' => 'active',
        ]);
        $this->userBId = $db->insert('users', [
            'name' => 'Aud Tester B',
            'email' => 'aud-tester-b@test.local',
            'password_hash' => password_hash('unused-in-this-test', PASSWORD_DEFAULT),
            'role' => 'super_admin',
            'branch_id' => null,
            'status' => 'active',
        ]);

        $this->bankAId = $db->insert('accounts', [
            'branch_id' => $this->branchAId, 'name' => 'AUD Bank', 'type' => 'bank',
            'opening_balance' => '0.00', 'opening_date' => '2026-01-01', 'is_active' => 1,
        ]);
        $this->categoryAId = $db->insert('categories', [
            'branch_id' => $this->branchAId, 'name' => 'AUD Expense Cat', 'type' => 'expense', 'is_active' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        $_SESSION = [];
    }

    private function cleanUp(): void
    {
        $db = Database::instance();
        $db->run(
            'DELETE t FROM transactions t JOIN accounts a ON a.id = t.account_id WHERE a.name = :n',
            ['n' => 'AUD Bank']
        );
        $db->delete('categories', 'name = :n', ['n' => 'AUD Expense Cat']);
        $db->delete('accounts', 'name = :n', ['n' => 'AUD Bank']);
        $db->delete('users', 'email LIKE :e', ['e' => 'aud-tester-%@test.local']);
        $db->run(
            'DELETE a FROM audit_log a JOIN branches b ON b.id = a.branch_id WHERE b.name LIKE :n',
            ['n' => 'AUD-%']
        );
        $db->delete('audit_log', "action = 'test.super_admin_action'");
        $db->delete('branches', 'name LIKE :n', ['n' => 'AUD-%']);
    }

    public function testRecordStampsTheActingUsersActiveBranch(): void
    {
        $_SESSION['_auth_user_id'] = $this->userAId;
        $_SESSION['_active_branch_id'] = $this->branchAId;

        $id = TransactionService::post([
            'type' => TransactionType::Expense,
            'amount' => '25.00',
            'account_id' => $this->bankAId,
            'category_id' => $this->categoryAId,
            'transaction_date' => '2026-09-27',
            'description' => 'AUD expense',
        ]);

        $branchId = Database::instance()->value(
            "SELECT branch_id FROM audit_log WHERE entity_type = 'transactions' AND entity_id = :id",
            ['id' => $id]
        );

        self::assertSame(
            $this->branchAId,
            (int) $branchId,
            'the audit row for a branch-scoped action must carry that branch, not NULL'
        );
    }

    public function testRecordLeavesBranchIdNullForASuperAdminAction(): void
    {
        $_SESSION['_auth_user_id'] = $this->userBId;
        $_SESSION['_active_branch_id'] = null;

        Audit::record('test.super_admin_action', 'branches', $this->branchAId, null, ['note' => 'created']);

        $branchId = Database::instance()->value(
            "SELECT branch_id FROM audit_log WHERE action = 'test.super_admin_action'"
        );

        self::assertNull($branchId, 'a super admin has no active branch, so its own actions carry no branch_id');
    }
}
