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
 * M7-4: the audit log viewer's data layer (App\Services\Audit::search() and
 * friends). The security-critical property is the same one AuditTest and
 * BranchIsolationTest establish elsewhere: every query here is scoped to
 * Auth::branchId(), so Branch A can never see Branch B's audit trail —
 * there is no separate "is this mine" check to get wrong, the WHERE clause
 * itself is the scope.
 */
final class AuditLogViewerTest extends TestCase
{
    private int $branchAId;
    private int $branchBId;
    private int $userAId;
    private int $userBId;
    private int $bankAId;
    private int $bankBId;
    private int $categoryAId;
    private int $categoryBId;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->cleanUp();

        $db = Database::instance();

        $this->branchAId = BranchFixture::create('ALV-A');
        $this->branchBId = BranchFixture::create('ALV-B');

        $this->userAId = $db->insert('users', [
            'name' => 'Alv Tester A',
            'email' => 'alv-tester-a@test.local',
            'password_hash' => password_hash('unused-in-this-test', PASSWORD_DEFAULT),
            'role' => 'admin',
            'branch_id' => $this->branchAId,
            'status' => 'active',
        ]);
        $this->userBId = $db->insert('users', [
            'name' => 'Alv Tester B',
            'email' => 'alv-tester-b@test.local',
            'password_hash' => password_hash('unused-in-this-test', PASSWORD_DEFAULT),
            'role' => 'admin',
            'branch_id' => $this->branchBId,
            'status' => 'active',
        ]);

        $this->bankAId = $db->insert('accounts', [
            'branch_id' => $this->branchAId, 'name' => 'ALV Bank', 'type' => 'bank',
            'opening_balance' => '0.00', 'opening_date' => '2026-01-01', 'is_active' => 1,
        ]);
        $this->bankBId = $db->insert('accounts', [
            'branch_id' => $this->branchBId, 'name' => 'ALV Bank', 'type' => 'bank',
            'opening_balance' => '0.00', 'opening_date' => '2026-01-01', 'is_active' => 1,
        ]);
        $this->categoryAId = $db->insert('categories', [
            'branch_id' => $this->branchAId, 'name' => 'ALV Expense Cat', 'type' => 'expense', 'is_active' => 1,
        ]);
        $this->categoryBId = $db->insert('categories', [
            'branch_id' => $this->branchBId, 'name' => 'ALV Expense Cat', 'type' => 'expense', 'is_active' => 1,
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
            ['n' => 'ALV Bank']
        );
        $db->delete('categories', 'name = :n', ['n' => 'ALV Expense Cat']);
        $db->delete('accounts', 'name = :n', ['n' => 'ALV Bank']);
        $db->delete('users', 'email LIKE :e', ['e' => 'alv-tester-%@test.local']);
        $db->run(
            'DELETE a FROM audit_log a JOIN branches b ON b.id = a.branch_id WHERE b.name LIKE :n',
            ['n' => 'ALV-%']
        );
        $db->delete('branches', 'name LIKE :n', ['n' => 'ALV-%']);
    }

    private function asBranchA(): void
    {
        $_SESSION['_auth_user_id'] = $this->userAId;
        $_SESSION['_active_branch_id'] = $this->branchAId;
    }

    private function asBranchB(): void
    {
        $_SESSION['_auth_user_id'] = $this->userBId;
        $_SESSION['_active_branch_id'] = $this->branchBId;
    }

    private function postExpense(int $accountId, int $categoryId, string $description): int
    {
        return TransactionService::post([
            'type' => TransactionType::Expense,
            'amount' => '10.00',
            'account_id' => $accountId,
            'category_id' => $categoryId,
            'transaction_date' => '2026-09-27',
            'description' => $description,
        ]);
    }

    public function testSearchNeverReturnsAnotherBranchsAuditRows(): void
    {
        $this->asBranchA();
        $this->postExpense($this->bankAId, $this->categoryAId, 'ALV expense A');

        $this->asBranchB();
        $this->postExpense($this->bankBId, $this->categoryBId, 'ALV expense B');

        $this->asBranchA();
        $rows = Audit::search(['entity_type' => 'transactions'], 1, 200);
        $descriptions = array_map(
            static function (array $r): string {
                $meta = json_decode((string) $r['meta'], true);

                return (string) ($meta['after']['description'] ?? '');
            },
            $rows
        );

        self::assertContains('ALV expense A', $descriptions);
        self::assertNotContains('ALV expense B', $descriptions, 'Branch A must never see Branch B\'s audit trail');
    }

    public function testSearchCountMatchesSearchForTheSameFilters(): void
    {
        $this->asBranchA();
        $this->postExpense($this->bankAId, $this->categoryAId, 'ALV expense count 1');
        $this->postExpense($this->bankAId, $this->categoryAId, 'ALV expense count 2');

        $count = Audit::searchCount(['entity_type' => 'transactions']);
        $rows = Audit::search(['entity_type' => 'transactions'], 1, 200);

        self::assertSame(count($rows), $count);
        self::assertGreaterThanOrEqual(2, $count);
    }

    public function testActionFilterNarrowsResults(): void
    {
        $this->asBranchA();
        $this->postExpense($this->bankAId, $this->categoryAId, 'ALV expense filtered');

        $matching = Audit::search(['action' => 'transaction.posted'], 1, 200);
        $nonMatching = Audit::search(['action' => 'transaction.void'], 1, 200);

        self::assertNotEmpty($matching);
        self::assertSame([], $nonMatching);
    }

    public function testDistinctActionsAndEntityTypesAreScopedToTheBranch(): void
    {
        $this->asBranchA();
        $this->postExpense($this->bankAId, $this->categoryAId, 'ALV expense distinct');

        self::assertContains('transaction.posted', Audit::distinctActions());
        self::assertContains('transactions', Audit::distinctEntityTypes());

        $this->asBranchB();
        // Branch B posted nothing in this test, so its distinct lists must
        // not pick up Branch A's actions.
        self::assertNotContains('transaction.posted', Audit::distinctActions());
    }

    public function testForEntityIsScopedToTheBranchToo(): void
    {
        $this->asBranchA();
        $idA = $this->postExpense($this->bankAId, $this->categoryAId, 'ALV expense forEntity A');

        $this->asBranchB();
        // Guessing Branch A's transaction id from a Branch B session must
        // not surface Branch A's audit history for it.
        self::assertSame([], Audit::forEntity('transactions', $idA));
    }
}
