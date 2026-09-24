<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Domain\TransactionType;
use App\Services\LedgerQuery;
use App\Services\ProfitDistributionService;
use App\Services\ShareService;
use App\Services\TransactionService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\BranchFixture;

/**
 * This is the module that decides who gets paid, so every documented "Done
 * when" from docs/MODULES.md Module 7 is asserted directly:
 *
 *   - allocations sum EXACTLY to distributable profit, rounding remainder
 *     assigned deterministically, never dropped;
 *   - a mid-period share change produces the correct split;
 *   - cash basis: pending income is not profit;
 *   - calculated ≠ distributed, both stored, and only move through
 *     calculate → approve → distribute in that order.
 */
final class ProfitDistributionServiceTest extends TestCase
{
    /** @var list<int> */
    private array $partnerIds = [];
    private int $branchId;
    private int $bankId;
    private int $incomeCategoryId;
    private int $expenseCategoryId;
    private int $userId;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->cleanUp();

        $db = Database::instance();

        $this->branchId = BranchFixture::create('PD');

        $this->userId = $db->insert('users', [
            'name' => 'PD Tester',
            'email' => 'pd-tester@test.local',
            'password_hash' => password_hash('unused-in-this-test', PASSWORD_DEFAULT),
            'role' => 'admin',
            'branch_id' => $this->branchId,
            'status' => 'active',
        ]);
        $_SESSION['_auth_user_id'] = $this->userId;
        $_SESSION['_active_branch_id'] = $this->branchId;

        foreach (['PD Partner A', 'PD Partner B', 'PD Partner C'] as $name) {
            $this->partnerIds[] = $db->insert('partners', [
                'branch_id' => $this->branchId,
                'name' => $name,
                'join_date' => '2024-01-01',
                'status' => 'active',
            ]);
        }

        $this->bankId = $db->insert('accounts', [
            'branch_id' => $this->branchId,
            'name' => 'PD Bank',
            'type' => 'bank',
            'opening_balance' => '0.00',
            'opening_date' => '2026-01-01',
            'is_active' => 1,
        ]);
        $this->incomeCategoryId = $db->insert('categories', [
            'branch_id' => $this->branchId,
            'name' => 'PD Income Cat',
            'type' => 'income',
            'is_active' => 1,
        ]);
        $this->expenseCategoryId = $db->insert('categories', [
            'branch_id' => $this->branchId,
            'name' => 'PD Expense Cat',
            'type' => 'expense',
            'is_active' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        $this->partnerIds = [];
        $_SESSION = [];
    }

    private function cleanUp(): void
    {
        $db = Database::instance();
        $db->delete('audit_log', "entity_type = 'profit_distributions'");
        $db->delete(
            'profit_distributions',
            'partner_id IN (SELECT id FROM partners WHERE name LIKE :n)',
            ['n' => 'PD %']
        );
        $db->run(
            'DELETE t FROM transactions t
             JOIN accounts a ON a.id = t.account_id
             WHERE a.name LIKE :n',
            ['n' => 'PD %']
        );
        $db->delete('partners', 'name LIKE :n', ['n' => 'PD %']);
        $db->delete('categories', 'name LIKE :n', ['n' => 'PD %']);
        $db->delete('accounts', 'name LIKE :n', ['n' => 'PD %']);
        $db->delete('users', 'email = :e', ['e' => 'pd-tester@test.local']);
        $db->run(
            'DELETE a FROM audit_log a JOIN branches b ON b.id = a.branch_id WHERE b.name LIKE :n',
            ['n' => 'PD %']
        );
        $db->delete('branches', 'name LIKE :n', ['n' => 'PD %']);
    }

    private function postIncome(string $amount, string $date): void
    {
        TransactionService::post([
            'type' => TransactionType::Income,
            'amount' => $amount,
            'account_id' => $this->bankId,
            'category_id' => $this->incomeCategoryId,
            'transaction_date' => $date,
            'description' => 'PD income',
        ]);
    }

    private function postPendingIncome(string $amount, string $date): void
    {
        TransactionService::post([
            'type' => TransactionType::Income,
            'amount' => $amount,
            'account_id' => $this->bankId,
            'category_id' => $this->incomeCategoryId,
            'transaction_date' => $date,
            'description' => 'PD pending income',
            'status' => 'pending',
        ]);
    }

    private function postExpense(string $amount, string $date): void
    {
        TransactionService::post([
            'type' => TransactionType::Expense,
            'amount' => $amount,
            'account_id' => $this->bankId,
            'category_id' => $this->expenseCategoryId,
            'transaction_date' => $date,
            'description' => 'PD expense',
        ]);
    }

    // ------------------------------------------------------- distributable profit

    public function testDistributableProfitIsCashBasisIncomeLessExpense(): void
    {
        $this->postIncome('1000.00', '2026-09-05');
        $this->postExpense('400.00', '2026-09-10');
        $this->postPendingIncome('5000.00', '2026-09-12');

        $profit = ProfitDistributionService::distributableProfit('2026-09-01', '2026-09-30');

        self::assertSame(600.0, (float) $profit, 'pending income must not count as profit');
    }

    // ------------------------------------------------------------------ blocking

    public function testCalculationIsBlockedWhenSharesDoNotTotalOneHundredPercent(): void
    {
        $this->postIncome('1000.00', '2026-09-05');
        // No split activated at all: totalBpOn() is 0, not 100%.

        $this->expectException(RuntimeException::class);
        ProfitDistributionService::calculate('2026-09-01', '2026-09-30');
    }

    public function testCalculationIsBlockedWhenThereIsNoDistributableProfit(): void
    {
        [$a, $b] = $this->partnerIds;
        ShareService::activateSplit([$a => 600000, $b => 400000], '2026-01-01');

        $this->postExpense('400.00', '2026-09-10');
        // No income at all: profit is negative.

        $this->expectException(InvalidArgumentException::class);
        ProfitDistributionService::calculate('2026-09-01', '2026-09-30');
    }

    public function testTheSamePeriodCannotBeCalculatedTwice(): void
    {
        [$a, $b] = $this->partnerIds;
        ShareService::activateSplit([$a => 600000, $b => 400000], '2026-01-01');
        $this->postIncome('1000.00', '2026-09-05');

        ProfitDistributionService::calculate('2026-09-01', '2026-09-30');

        $this->expectException(RuntimeException::class);
        ProfitDistributionService::calculate('2026-09-01', '2026-09-30');
    }

    // -------------------------------------------------------------- rounding

    /**
     * The reason allocate() exists at all. Profit that does not divide evenly
     * across basis-point shares must still sum to exactly the whole — a
     * dropped or invented cent is not acceptable in money that pays people.
     */
    public function testAllocationsSumExactlyToDistributableProfit(): void
    {
        [$a, $b, $c] = $this->partnerIds;
        ShareService::activateSplit([
            $a => 333333,
            $b => 333333,
            $c => 333334,
        ], '2026-01-01');

        $this->postIncome('100.01', '2026-09-05');

        $batch = ProfitDistributionService::calculate('2026-09-01', '2026-09-30');
        $rows = ProfitDistributionService::batchRows($batch);

        $sum = '0.00';
        foreach ($rows as $row) {
            $sum = bcadd($sum, (string) $row['calculated_amount'], 2);
        }

        self::assertSame('100.01', $sum, 'the parts must sum to exactly the whole, not float-close');
    }

    /**
     * With an odd cent to place, it must go to the partner with the largest
     * fractional remainder — here, unambiguously partner C.
     */
    public function testTheOddCentGoesToTheLargestRemainder(): void
    {
        [$a, $b, $c] = $this->partnerIds;
        ShareService::activateSplit([
            $a => 333333,
            $b => 333333,
            $c => 333334,
        ], '2026-01-01');

        $this->postIncome('0.01', '2026-09-05');

        $batch = ProfitDistributionService::calculate('2026-09-01', '2026-09-30');
        $byPartner = [];
        foreach (ProfitDistributionService::batchRows($batch) as $row) {
            $byPartner[(int) $row['partner_id']] = (string) $row['calculated_amount'];
        }

        self::assertSame('0.00', $byPartner[$a]);
        self::assertSame('0.00', $byPartner[$b]);
        self::assertSame('0.01', $byPartner[$c], 'C has the largest share and so the largest remainder');
    }

    /**
     * Two partners with an EQUAL remainder must resolve to the same partner
     * every time — lower partner id — rather than depending on array or sort
     * order, which is not something a payout may depend on.
     */
    public function testATiedRemainderIsBrokenByPartnerIdDeterministically(): void
    {
        [$a, $b] = $this->partnerIds;
        ShareService::activateSplit([$a => 500000, $b => 500000], '2026-01-01');

        $this->postIncome('0.01', '2026-09-05');

        $batch = ProfitDistributionService::calculate('2026-09-01', '2026-09-30');
        $byPartner = [];
        foreach (ProfitDistributionService::batchRows($batch) as $row) {
            $byPartner[(int) $row['partner_id']] = (string) $row['calculated_amount'];
        }

        self::assertSame('0.01', $byPartner[$a], 'a tie must resolve to the lower partner id');
        self::assertSame('0.00', $byPartner[$b]);
    }

    // ------------------------------------------------------------- mid-period

    /**
     * The whole reason distribution reads the split "as of the period end":
     * an ownership change partway through must not be ignored, but the split
     * used must be the one in force when the period closes.
     */
    public function testUsesTheOwnershipSplitInForceOnThePeriodEnd(): void
    {
        [$a, $b, $c] = $this->partnerIds;

        ShareService::activateSplit([$a => 550000, $b => 450000], '2026-01-01');
        ShareService::activateSplit([$a => 400000, $b => 350000, $c => 250000], '2026-07-01');

        $this->postIncome('1000.00', '2026-07-15');

        $batch = ProfitDistributionService::calculate('2026-07-01', '2026-07-31');
        $byPartner = [];
        foreach (ProfitDistributionService::batchRows($batch) as $row) {
            $byPartner[(int) $row['partner_id']] = $row;
        }

        self::assertCount(3, $byPartner, 'July uses the three-way split');
        self::assertSame(400000, (int) $byPartner[$a]['share_bp']);
        self::assertSame('400.00', (string) $byPartner[$a]['calculated_amount']);
        self::assertSame('350.00', (string) $byPartner[$b]['calculated_amount']);
        self::assertSame('250.00', (string) $byPartner[$c]['calculated_amount']);
    }

    // ---------------------------------------------------------- approve/distribute

    public function testApproveThenDistributePostsOneLedgerRowPerPartner(): void
    {
        [$a, $b] = $this->partnerIds;
        ShareService::activateSplit([$a => 600000, $b => 400000], '2026-01-01');
        $this->postIncome('1000.00', '2026-09-05');

        $batch = ProfitDistributionService::calculate('2026-09-01', '2026-09-30');
        ProfitDistributionService::approve($batch);
        ProfitDistributionService::distribute($batch, $this->bankId);

        $rows = ProfitDistributionService::batchRows($batch);
        foreach ($rows as $row) {
            self::assertSame('distributed', (string) $row['status']);
            self::assertSame((string) $row['calculated_amount'], (string) $row['distributed_amount']);
            self::assertNotNull($row['transaction_id']);
        }

        $posted = Database::instance()->value(
            "SELECT COUNT(*) FROM transactions WHERE type = 'profit_distribution' AND account_id = :a",
            ['a' => $this->bankId]
        );
        self::assertSame(2, (int) $posted, 'one ledger row per partner');
    }

    public function testDistributionDoesNotTouchProfitAndLoss(): void
    {
        [$a, $b] = $this->partnerIds;
        ShareService::activateSplit([$a => 600000, $b => 400000], '2026-01-01');
        $this->postIncome('1000.00', '2026-09-05');

        $profitBefore = LedgerQuery::posted()->profitAndLossOnly()->netAmount();

        $batch = ProfitDistributionService::calculate('2026-09-01', '2026-09-30');
        ProfitDistributionService::approve($batch);
        ProfitDistributionService::distribute($batch, $this->bankId);

        $profitAfter = LedgerQuery::posted()->profitAndLossOnly()->netAmount();

        self::assertSame((float) $profitBefore, (float) $profitAfter, 'paying out profit is not itself an expense');
    }

    public function testDistributingBeforeApprovalIsRefused(): void
    {
        [$a, $b] = $this->partnerIds;
        ShareService::activateSplit([$a => 600000, $b => 400000], '2026-01-01');
        $this->postIncome('1000.00', '2026-09-05');

        $batch = ProfitDistributionService::calculate('2026-09-01', '2026-09-30');

        $this->expectException(RuntimeException::class);
        ProfitDistributionService::distribute($batch, $this->bankId);
    }

    public function testApprovingTwiceIsRefused(): void
    {
        [$a, $b] = $this->partnerIds;
        ShareService::activateSplit([$a => 600000, $b => 400000], '2026-01-01');
        $this->postIncome('1000.00', '2026-09-05');

        $batch = ProfitDistributionService::calculate('2026-09-01', '2026-09-30');
        ProfitDistributionService::approve($batch);

        $this->expectException(RuntimeException::class);
        ProfitDistributionService::approve($batch);
    }
}
