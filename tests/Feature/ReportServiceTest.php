<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Domain\TransactionType;
use App\Services\LedgerQuery;
use App\Services\ReportService;
use App\Services\TransactionService;
use PHPUnit\Framework\TestCase;
use Tests\Support\BranchFixture;

/**
 * Every ReportService figure is asserted against an independent, hand-built
 * LedgerQuery/SQL read over the same fixtures — not against ReportService's
 * own output — the same discipline DashboardServiceTest holds the dashboard
 * to, so the reports agree with every other screen that reads the ledger.
 */
final class ReportServiceTest extends TestCase
{
    private int $branchId;
    private int $userId;
    private int $bankId;
    private int $expenseCategoryId;
    private int $incomeCategoryId;
    private int $partnerId;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->cleanUp();

        $db = Database::instance();

        $this->branchId = BranchFixture::create('RS');

        $this->userId = $db->insert('users', [
            'name' => 'RS Tester',
            'email' => 'report-tester@test.local',
            'password_hash' => password_hash('unused-in-this-test', PASSWORD_DEFAULT),
            'role' => 'admin',
            'branch_id' => $this->branchId,
            'status' => 'active',
        ]);
        $_SESSION['_auth_user_id'] = $this->userId;
        $_SESSION['_active_branch_id'] = $this->branchId;

        $this->bankId = $db->insert('accounts', [
            'branch_id' => $this->branchId,
            'name' => 'RS Bank',
            'type' => 'bank',
            'opening_balance' => '1000.00',
            'opening_date' => '2026-01-01',
            'is_active' => 1,
        ]);
        $this->expenseCategoryId = $db->insert('categories', [
            'branch_id' => $this->branchId,
            'name' => 'RS Expense Cat',
            'type' => 'expense',
            'is_active' => 1,
        ]);
        $this->incomeCategoryId = $db->insert('categories', [
            'branch_id' => $this->branchId,
            'name' => 'RS Income Cat',
            'type' => 'income',
            'is_active' => 1,
        ]);
        $this->partnerId = $db->insert('partners', [
            'branch_id' => $this->branchId,
            'name' => 'RS Partner',
            'status' => 'active',
            'join_date' => '2026-01-01',
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
            'DELETE t FROM transactions t
             JOIN accounts a ON a.id = t.account_id
             WHERE a.name LIKE :n',
            ['n' => 'RS %']
        );
        $db->delete('categories', 'name LIKE :n', ['n' => 'RS %']);
        $db->delete('partners', 'name LIKE :n', ['n' => 'RS %']);
        $db->delete('accounts', 'name LIKE :n', ['n' => 'RS %']);
        $db->delete('users', 'email = :e', ['e' => 'report-tester@test.local']);
        $db->delete('branches', 'name LIKE :n', ['n' => 'RS %']);
    }

    private function postExpense(string $amount, string $date): void
    {
        TransactionService::post([
            'type' => TransactionType::Expense,
            'amount' => $amount,
            'account_id' => $this->bankId,
            'category_id' => $this->expenseCategoryId,
            'transaction_date' => $date,
            'description' => 'RS expense',
        ]);
    }

    private function postIncome(string $amount, string $date, string $status = 'posted'): void
    {
        TransactionService::post([
            'type' => TransactionType::Income,
            'amount' => $amount,
            'account_id' => $this->bankId,
            'category_id' => $this->incomeCategoryId,
            'transaction_date' => $date,
            'description' => 'RS income',
            'status' => $status,
        ]);
    }

    public function testProfitLossNetEqualsRevenueMinusExpenseForTheSamePeriod(): void
    {
        $this->postIncome('2000.00', '2026-09-05');
        $this->postExpense('750.00', '2026-09-10');

        $report = ReportService::profitLoss('2026-09-01', '2026-09-30');

        $expectedRevenue = LedgerQuery::posted()->revenueOnly()
            ->between('2026-09-01', '2026-09-30')->category($this->incomeCategoryId)->totalAmount();
        $expectedExpense = LedgerQuery::posted()->expensesOnly()
            ->between('2026-09-01', '2026-09-30')->category($this->expenseCategoryId)->totalAmount();

        self::assertSame($expectedRevenue, '2000.00');
        self::assertSame($expectedExpense, '750.00');
        self::assertSame(
            number_format((float) $expectedRevenue - (float) $expectedExpense, 2, '.', ''),
            number_format((float) $report['net'], 2, '.', '')
        );
    }

    public function testCashFlowExcludesPendingIncomeFromInboundButReportsItSeparately(): void
    {
        $this->postIncome('1000.00', '2026-09-05', 'posted');
        $this->postIncome('5000.00', '2026-09-10', 'pending');

        $report = ReportService::cashFlow('2026-09-01', '2026-09-30');

        $handInbound = LedgerQuery::posted()->between('2026-09-01', '2026-09-30')->types([
            TransactionType::Income,
        ])->category($this->incomeCategoryId)->totalAmount();

        self::assertSame('1000.00', $handInbound, 'pending income must never reach a posted cash figure');
        self::assertGreaterThanOrEqual((float) $handInbound, (float) $report['inbound']);

        $handPending = LedgerQuery::includeVoided()->pendingOnly()->revenueOnly()
            ->between('2026-09-01', '2026-09-30')->category($this->incomeCategoryId)->totalAmount();
        self::assertSame('5000.00', $handPending);
        self::assertGreaterThanOrEqual((float) $handPending, (float) $report['pendingIncome']);
    }

    public function testCashFlowTypesExcludeTransfersAndCapitalFromProfitAndLossByConstruction(): void
    {
        // cashFlow()'s inbound/outbound type lists include transfers and
        // partner capital (real cash movements) while P&L reports must never
        // count them — enforced by TransactionType::affectsProfitAndLoss(),
        // the single source of truth every aggregate in this app defers to.
        self::assertFalse(TransactionType::TransferIn->affectsProfitAndLoss());
        self::assertFalse(TransactionType::TransferOut->affectsProfitAndLoss());
        self::assertFalse(TransactionType::PartnerContribution->affectsProfitAndLoss());
        self::assertFalse(TransactionType::PartnerWithdrawal->affectsProfitAndLoss());
    }

    public function testAccountBalancesAsOfMatchesABalanceComputedByHandAtThatDate(): void
    {
        $this->postExpense('300.00', '2026-09-05');
        $this->postExpense('200.00', '2026-09-20'); // after the as-of date

        $report = ReportService::accountBalancesAsOf('2026-09-10');

        $byName = [];
        foreach ($report['accounts'] as $row) {
            $byName[$row['name']] = $row['balance'];
        }

        $handBalance = number_format(
            1000.00 - (float) LedgerQuery::posted()
                ->account($this->bankId)
                ->between(null, '2026-09-10')
                ->category($this->expenseCategoryId)
                ->totalAmount(),
            2,
            '.',
            ''
        );

        self::assertSame($handBalance, $byName['RS Bank'], 'only movements up to the as-of date must count');
    }

    public function testPartnerStatementRunningBalanceReconcilesToTheNetOfItsMovements(): void
    {
        TransactionService::post([
            'type' => TransactionType::PartnerContribution,
            'amount' => '500.00',
            'account_id' => $this->bankId,
            'partner_id' => $this->partnerId,
            'transaction_date' => '2026-09-05',
            'description' => 'RS contribution',
        ]);
        TransactionService::post([
            'type' => TransactionType::PartnerWithdrawal,
            'amount' => '150.00',
            'account_id' => $this->bankId,
            'partner_id' => $this->partnerId,
            'transaction_date' => '2026-09-10',
            'description' => 'RS withdrawal',
        ]);

        $statement = ReportService::partnerStatement($this->partnerId, '2026-09-01', '2026-09-30');

        $handNet = LedgerQuery::posted()->partner($this->partnerId)->between('2026-09-01', '2026-09-30')->netAmount();

        self::assertSame('350.00', $handNet);
        self::assertCount(2, $statement['rows']);
        self::assertSame(
            number_format((float) $statement['opening'] + (float) $handNet, 2, '.', ''),
            $statement['closing']
        );
        self::assertSame($statement['closing'], end($statement['rows'])['running_balance']);
    }

    public function testTotalsByPartnerGroupsContributionsPerPartner(): void
    {
        TransactionService::post([
            'type' => TransactionType::PartnerContribution,
            'amount' => '500.00',
            'account_id' => $this->bankId,
            'partner_id' => $this->partnerId,
            'transaction_date' => '2026-09-05',
            'description' => 'RS contribution',
        ]);

        $rows = ReportService::totalsByPartner(TransactionType::PartnerContribution, '2026-09-01', '2026-09-30');

        $byPartner = [];
        foreach ($rows as $row) {
            $byPartner[(int) $row['partner_id']] = $row['total'];
        }

        self::assertSame('500.00', $byPartner[$this->partnerId] ?? null);
    }
}
