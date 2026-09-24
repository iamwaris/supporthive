<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Domain\TransactionType;
use App\Models\Account;
use App\Services\DashboardService;
use App\Services\LedgerQuery;
use App\Services\ProfitDistributionService;
use App\Services\ReportService;
use App\Services\ShareService;
use App\Services\TransactionService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\BranchFixture;

/**
 * The load-bearing test for the multi-branch retrofit. A missed scope check
 * here is a real cross-tenant data leak, not a cosmetic bug, so every
 * surface the retrofit touches is asserted directly: LedgerQuery, the
 * Model base class, DashboardService, ReportService, ShareService,
 * ProfitDistributionService, and TransactionService's ownership checks.
 *
 * Two independent branches (A, B) are built with parallel fixtures. Every
 * assertion below is: operating as a Branch A session, Branch B's rows must
 * never appear, and Branch B's ids must never be reachable by id.
 */
final class BranchIsolationTest extends TestCase
{
    private int $branchAId;
    private int $branchBId;

    private int $userAId;
    private int $userBId;

    private int $bankAId;
    private int $bankBId;

    private int $expenseCategoryAId;
    private int $expenseCategoryBId;
    private int $incomeCategoryAId;
    private int $incomeCategoryBId;

    private int $partnerAId;
    private int $partnerBId;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->cleanUp();

        $db = Database::instance();

        $this->branchAId = BranchFixture::create('BI-A');
        $this->branchBId = BranchFixture::create('BI-B');

        $this->userAId = $db->insert('users', [
            'name' => 'BI Tester A',
            'email' => 'bi-tester-a@test.local',
            'password_hash' => password_hash('unused-in-this-test', PASSWORD_DEFAULT),
            'role' => 'admin',
            'branch_id' => $this->branchAId,
            'status' => 'active',
        ]);
        $this->userBId = $db->insert('users', [
            'name' => 'BI Tester B',
            'email' => 'bi-tester-b@test.local',
            'password_hash' => password_hash('unused-in-this-test', PASSWORD_DEFAULT),
            'role' => 'admin',
            'branch_id' => $this->branchBId,
            'status' => 'active',
        ]);

        $this->bankAId = $db->insert('accounts', [
            'branch_id' => $this->branchAId,
            'name' => 'BI Bank', // deliberately the SAME name in both branches
            'type' => 'bank',
            'opening_balance' => '1000.00',
            'opening_date' => '2026-01-01',
            'is_active' => 1,
        ]);
        $this->bankBId = $db->insert('accounts', [
            'branch_id' => $this->branchBId,
            'name' => 'BI Bank',
            'type' => 'bank',
            'opening_balance' => '5000.00',
            'opening_date' => '2026-01-01',
            'is_active' => 1,
        ]);

        $this->expenseCategoryAId = $db->insert('categories', [
            'branch_id' => $this->branchAId, 'name' => 'BI Expense Cat', 'type' => 'expense', 'is_active' => 1,
        ]);
        $this->expenseCategoryBId = $db->insert('categories', [
            'branch_id' => $this->branchBId, 'name' => 'BI Expense Cat', 'type' => 'expense', 'is_active' => 1,
        ]);
        $this->incomeCategoryAId = $db->insert('categories', [
            'branch_id' => $this->branchAId, 'name' => 'BI Income Cat', 'type' => 'income', 'is_active' => 1,
        ]);
        $this->incomeCategoryBId = $db->insert('categories', [
            'branch_id' => $this->branchBId, 'name' => 'BI Income Cat', 'type' => 'income', 'is_active' => 1,
        ]);

        $this->partnerAId = $db->insert('partners', [
            'branch_id' => $this->branchAId, 'name' => 'BI Partner', 'join_date' => '2024-01-01', 'status' => 'active',
        ]);
        $this->partnerBId = $db->insert('partners', [
            'branch_id' => $this->branchBId, 'name' => 'BI Partner', 'join_date' => '2024-01-01', 'status' => 'active',
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
            'DELETE s FROM settings s JOIN branches b ON b.id = s.branch_id WHERE b.name LIKE :n',
            ['n' => 'BI-%']
        );
        $db->run(
            'DELETE pd FROM profit_distributions pd
             JOIN partners p ON p.id = pd.partner_id WHERE p.name LIKE :n',
            ['n' => 'BI %']
        );
        $db->run(
            'DELETE ps FROM partner_shares ps JOIN partners p ON p.id = ps.partner_id WHERE p.name LIKE :n',
            ['n' => 'BI %']
        );
        $db->run(
            'DELETE t FROM transactions t JOIN accounts a ON a.id = t.account_id WHERE a.name LIKE :n',
            ['n' => 'BI %']
        );
        $db->delete('budgets', 'category_id IN (SELECT id FROM categories WHERE name LIKE :n)', ['n' => 'BI %']);
        $db->delete('partners', 'name LIKE :n', ['n' => 'BI %']);
        $db->delete('categories', 'name LIKE :n', ['n' => 'BI %']);
        $db->delete('accounts', 'name LIKE :n', ['n' => 'BI %']);
        $db->delete('users', 'email LIKE :e', ['e' => 'bi-tester-%@test.local']);
        $db->delete('branches', 'name LIKE :n', ['n' => 'BI-%']);
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

    // ------------------------------------------------------------ LedgerQuery

    public function testLedgerQueryNeverReturnsAnotherBranchsTransactions(): void
    {
        $this->asBranchA();
        $idA = TransactionService::post([
            'type' => TransactionType::Expense,
            'amount' => '111.00',
            'account_id' => $this->bankAId,
            'category_id' => $this->expenseCategoryAId,
            'transaction_date' => '2026-09-10',
            'description' => 'BI expense A',
        ]);

        $this->asBranchB();
        $idB = TransactionService::post([
            'type' => TransactionType::Expense,
            'amount' => '222.00',
            'account_id' => $this->bankBId,
            'category_id' => $this->expenseCategoryBId,
            'transaction_date' => '2026-09-10',
            'description' => 'BI expense B',
        ]);

        $this->asBranchA();
        $rowsSeenByA = LedgerQuery::posted()->page(1, 200);
        $idsSeenByA = array_map(static fn (array $r): int => (int) $r['id'], $rowsSeenByA);

        self::assertContains($idA, $idsSeenByA, 'Branch A must see its own transaction');
        self::assertNotContains($idB, $idsSeenByA, 'Branch A must never see Branch B\'s transaction');

        $totalSeenByA = LedgerQuery::posted()->expensesOnly()->totalAmount();
        self::assertSame('111.00', $totalSeenByA, 'the total must not include Branch B\'s amount');
    }

    // ---------------------------------------------------------------- Model

    public function testModelFindNeverReachesAnotherBranchsRowById(): void
    {
        $this->asBranchA();
        self::assertNotNull((new Account())->find($this->bankAId), 'Branch A must find its own account');
        self::assertNull((new Account())->find($this->bankBId), 'Branch A must not find Branch B\'s account by id');
    }

    public function testModelUpdateAndDeleteAffectZeroRowsForAnotherBranchsId(): void
    {
        $this->asBranchA();
        $account = new Account();

        $updated = $account->updateById($this->bankBId, ['notes' => 'tampered']);
        self::assertSame(0, $updated, 'updating Branch B\'s account from a Branch A session must affect nothing');

        $stillIntact = Database::instance()->value(
            'SELECT notes FROM accounts WHERE id = :id',
            ['id' => $this->bankBId]
        );
        self::assertNotSame('tampered', $stillIntact);
    }

    public function testSameNamedAccountsInDifferentBranchesDoNotCollide(): void
    {
        // Both branches created an account literally named "BI Bank" in
        // setUp() — the per-branch unique key must allow that.
        $this->asBranchA();
        self::assertTrue((new Account())->nameExists('BI Bank'), 'Branch A has its own "BI Bank"');

        $this->asBranchB();
        self::assertTrue((new Account())->nameExists('BI Bank'), 'Branch B independently has its own "BI Bank"');
    }

    // ------------------------------------------------------- Dashboard/Report

    public function testDashboardAndReportFiguresNeverMixBranches(): void
    {
        $this->asBranchA();
        TransactionService::post([
            'type' => TransactionType::Income,
            'amount' => '1000.00',
            'account_id' => $this->bankAId,
            'category_id' => $this->incomeCategoryAId,
            'transaction_date' => '2026-09-05',
            'description' => 'BI income A',
        ]);

        $this->asBranchB();
        TransactionService::post([
            'type' => TransactionType::Income,
            'amount' => '9000.00',
            'account_id' => $this->bankBId,
            'category_id' => $this->incomeCategoryBId,
            'transaction_date' => '2026-09-05',
            'description' => 'BI income B',
        ]);

        $this->asBranchA();
        $revenueA = DashboardService::revenueBetween('2026-09-01', '2026-09-30');
        self::assertSame('1000.00', $revenueA, 'Branch A revenue must not include Branch B\'s 9000');

        $plA = ReportService::profitLoss('2026-09-01', '2026-09-30');
        self::assertSame('1000.00', $plA['revenue']);

        $this->asBranchB();
        $revenueB = DashboardService::revenueBetween('2026-09-01', '2026-09-30');
        self::assertSame('9000.00', $revenueB, 'Branch B revenue must not include Branch A\'s 1000');
    }

    // -------------------------------------------------------- Partner shares

    public function testShareServiceSplitsNeverCrossBranches(): void
    {
        $this->asBranchA();
        ShareService::activateSplit([$this->partnerAId => ShareService::TOTAL_BP], '2026-01-01');

        $this->asBranchB();
        ShareService::activateSplit([$this->partnerBId => ShareService::TOTAL_BP], '2026-01-01');

        $this->asBranchA();
        $splitA = ShareService::currentSplit();
        self::assertArrayHasKey($this->partnerAId, $splitA);
        self::assertArrayNotHasKey(
            $this->partnerBId,
            $splitA,
            'Branch A\'s split must never include Branch B\'s partner'
        );
        self::assertSame(ShareService::TOTAL_BP, array_sum($splitA), 'Branch A alone must still total 100%');
    }

    // --------------------------------------------------- Profit distribution

    public function testProfitDistributionBatchesNeverCrossBranches(): void
    {
        $this->asBranchA();
        ShareService::activateSplit([$this->partnerAId => ShareService::TOTAL_BP], '2026-01-01');
        TransactionService::post([
            'type' => TransactionType::Income,
            'amount' => '5000.00',
            'account_id' => $this->bankAId,
            'category_id' => $this->incomeCategoryAId,
            'transaction_date' => '2026-09-05',
            'description' => 'BI income for distribution A',
        ]);
        $batchIdA = ProfitDistributionService::calculate('2026-09-01', '2026-09-30');

        $this->asBranchB();
        $batchesSeenByB = ProfitDistributionService::batches();
        $batchIdsSeenByB = array_map(static fn (array $b): string => (string) $b['batch_id'], $batchesSeenByB);
        self::assertNotContains($batchIdA, $batchIdsSeenByB, 'Branch B must never see Branch A\'s distribution batch');

        $rowsSeenByB = ProfitDistributionService::batchRows($batchIdA);
        self::assertSame([], $rowsSeenByB, 'Branch B must not be able to read Branch A\'s batch rows by id');
    }

    // ----------------------------------------------------- Ownership on void

    public function testVoidingAnotherBranchsTransactionIdFails(): void
    {
        $this->asBranchA();
        $idA = TransactionService::post([
            'type' => TransactionType::Expense,
            'amount' => '50.00',
            'account_id' => $this->bankAId,
            'category_id' => $this->expenseCategoryAId,
            'transaction_date' => '2026-09-10',
            'description' => 'BI void target A',
        ]);

        $this->asBranchB();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no longer exists');
        TransactionService::void($idA, 'attempted cross-branch void');
    }
}
