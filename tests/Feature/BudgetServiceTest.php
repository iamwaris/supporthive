<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Domain\TransactionType;
use App\Services\BudgetService;
use App\Services\TransactionService;
use PHPUnit\Framework\TestCase;
use Tests\Support\BranchFixture;

/**
 * Spec §25: Budget Remaining = budget - actual, Budget Utilization =
 * actual / budget x 100. Extracted so the dashboard's Budget Used/Remaining
 * tiles, its Budget vs Actual chart and its Alerts widget all agree with the
 * Budgets screen by construction - there is exactly one place this arithmetic
 * happens.
 */
final class BudgetServiceTest extends TestCase
{
    private int $branchId;
    private int $bankId;
    private int $expenseCategoryId;
    private int $userId;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->cleanUp();

        $db = Database::instance();

        $this->branchId = BranchFixture::create('BS');

        $this->userId = $db->insert('users', [
            'name' => 'BS Tester',
            'email' => 'budgetservice-tester@test.local',
            'password_hash' => password_hash('unused-in-this-test', PASSWORD_DEFAULT),
            'role' => 'admin',
            'branch_id' => $this->branchId,
            'status' => 'active',
        ]);
        $_SESSION['_auth_user_id'] = $this->userId;
        $_SESSION['_active_branch_id'] = $this->branchId;

        $this->bankId = $db->insert('accounts', [
            'branch_id' => $this->branchId,
            'name' => 'BS Bank',
            'type' => 'bank',
            'opening_balance' => '0.00',
            'opening_date' => '2026-01-01',
            'is_active' => 1,
        ]);
        $this->expenseCategoryId = $db->insert('categories', [
            'branch_id' => $this->branchId,
            'name' => 'BS Expense Cat',
            'type' => 'expense',
            'is_active' => 1,
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
        $db->delete('budgets', 'category_id IN (SELECT id FROM categories WHERE name LIKE :n)', ['n' => 'BS %']);
        $db->run(
            'DELETE t FROM transactions t
             JOIN accounts a ON a.id = t.account_id
             WHERE a.name LIKE :n',
            ['n' => 'BS %']
        );
        $db->delete('categories', 'name LIKE :n', ['n' => 'BS %']);
        $db->delete('accounts', 'name LIKE :n', ['n' => 'BS %']);
        $db->delete('users', 'email = :e', ['e' => 'budgetservice-tester@test.local']);
        $db->delete('branches', 'name LIKE :n', ['n' => 'BS %']);
    }

    public function testUtilisationAndRemainingMatchTheSpecFormula(): void
    {
        Database::instance()->insert('budgets', [
            'branch_id' => $this->branchId,
            'year' => 2026,
            'month' => 9,
            'category_id' => $this->expenseCategoryId,
            'amount' => '1000.00',
            'alert_threshold_pct' => 80,
        ]);

        TransactionService::post([
            'type' => TransactionType::Expense,
            'amount' => '650.00',
            'account_id' => $this->bankId,
            'category_id' => $this->expenseCategoryId,
            'transaction_date' => '2026-09-10',
            'description' => 'BS expense',
        ]);

        $summary = BudgetService::summaryForPeriod(2026, 9);

        self::assertCount(1, $summary);
        self::assertSame('650.00', $summary[0]['spent']);
        self::assertSame('350.00', $summary[0]['remaining'], 'remaining = budget - actual');
        self::assertSame(65.0, $summary[0]['utilisation_pct'], 'utilisation = actual / budget x 100');
        self::assertSame('ok', $summary[0]['state']);
    }

    public function testStateCrossesFromOkToWarningToExceededAtTheRightThresholds(): void
    {
        $categoryB = Database::instance()->insert('categories', [
            'branch_id' => $this->branchId, 'name' => 'BS Expense Cat B', 'type' => 'expense', 'is_active' => 1,
        ]);
        $categoryC = Database::instance()->insert('categories', [
            'branch_id' => $this->branchId, 'name' => 'BS Expense Cat C', 'type' => 'expense', 'is_active' => 1,
        ]);

        foreach ([$this->expenseCategoryId, $categoryB, $categoryC] as $categoryId) {
            Database::instance()->insert('budgets', [
                'branch_id' => $this->branchId,
                'year' => 2026, 'month' => 9, 'category_id' => $categoryId,
                'amount' => '1000.00', 'alert_threshold_pct' => 80,
            ]);
        }

        TransactionService::post([
            'type' => TransactionType::Expense, 'amount' => '500.00', 'account_id' => $this->bankId,
            'category_id' => $this->expenseCategoryId, 'transaction_date' => '2026-09-10', 'description' => 'BS ok',
        ]);
        TransactionService::post([
            'type' => TransactionType::Expense, 'amount' => '850.00', 'account_id' => $this->bankId,
            'category_id' => $categoryB, 'transaction_date' => '2026-09-10', 'description' => 'BS warning',
        ]);
        TransactionService::post([
            'type' => TransactionType::Expense, 'amount' => '1200.00', 'account_id' => $this->bankId,
            'category_id' => $categoryC, 'transaction_date' => '2026-09-10', 'description' => 'BS exceeded',
        ]);

        $byCategory = [];
        foreach (BudgetService::summaryForPeriod(2026, 9) as $row) {
            $byCategory[(int) $row['category_id']] = $row;
        }

        self::assertSame('ok', $byCategory[$this->expenseCategoryId]['state']);
        self::assertSame('warning', $byCategory[$categoryB]['state']);
        self::assertSame('exceeded', $byCategory[$categoryC]['state']);
    }

    public function testTotalsSumAcrossEveryBudgetedCategory(): void
    {
        $categoryB = Database::instance()->insert('categories', [
            'branch_id' => $this->branchId, 'name' => 'BS Expense Cat B', 'type' => 'expense', 'is_active' => 1,
        ]);

        Database::instance()->insert('budgets', [
            'branch_id' => $this->branchId,
            'year' => 2026, 'month' => 9, 'category_id' => $this->expenseCategoryId,
            'amount' => '1000.00', 'alert_threshold_pct' => 80,
        ]);
        Database::instance()->insert('budgets', [
            'branch_id' => $this->branchId,
            'year' => 2026, 'month' => 9, 'category_id' => $categoryB,
            'amount' => '500.00', 'alert_threshold_pct' => 80,
        ]);

        TransactionService::post([
            'type' => TransactionType::Expense, 'amount' => '400.00', 'account_id' => $this->bankId,
            'category_id' => $this->expenseCategoryId, 'transaction_date' => '2026-09-10', 'description' => 'BS a',
        ]);
        TransactionService::post([
            'type' => TransactionType::Expense, 'amount' => '600.00', 'account_id' => $this->bankId,
            'category_id' => $categoryB, 'transaction_date' => '2026-09-10', 'description' => 'BS b',
        ]);

        $totals = BudgetService::totals(BudgetService::summaryForPeriod(2026, 9));

        self::assertSame('1500.00', $totals['budgeted']);
        self::assertSame('1000.00', $totals['spent']);
        self::assertSame('500.00', $totals['remaining']);
        self::assertSame(2, $totals['count']);
    }

    public function testAlertsExcludeOkBudgetsAndRankExceededFirst(): void
    {
        $categoryB = Database::instance()->insert('categories', [
            'branch_id' => $this->branchId, 'name' => 'BS Expense Cat B', 'type' => 'expense', 'is_active' => 1,
        ]);
        $categoryC = Database::instance()->insert('categories', [
            'branch_id' => $this->branchId, 'name' => 'BS Expense Cat C', 'type' => 'expense', 'is_active' => 1,
        ]);

        Database::instance()->insert('budgets', [
            'branch_id' => $this->branchId,
            'year' => 2026, 'month' => 9, 'category_id' => $this->expenseCategoryId,
            'amount' => '1000.00', 'alert_threshold_pct' => 80,
        ]);
        Database::instance()->insert('budgets', [
            'branch_id' => $this->branchId,
            'year' => 2026, 'month' => 9, 'category_id' => $categoryB,
            'amount' => '1000.00', 'alert_threshold_pct' => 80,
        ]);
        Database::instance()->insert('budgets', [
            'branch_id' => $this->branchId,
            'year' => 2026, 'month' => 9, 'category_id' => $categoryC,
            'amount' => '1000.00', 'alert_threshold_pct' => 80,
        ]);

        TransactionService::post([
            'type' => TransactionType::Expense, 'amount' => '100.00', 'account_id' => $this->bankId,
            'category_id' => $this->expenseCategoryId, 'transaction_date' => '2026-09-10', 'description' => 'BS ok',
        ]);
        TransactionService::post([
            'type' => TransactionType::Expense, 'amount' => '900.00', 'account_id' => $this->bankId,
            'category_id' => $categoryB, 'transaction_date' => '2026-09-10', 'description' => 'BS warning',
        ]);
        TransactionService::post([
            'type' => TransactionType::Expense, 'amount' => '1100.00', 'account_id' => $this->bankId,
            'category_id' => $categoryC, 'transaction_date' => '2026-09-10', 'description' => 'BS exceeded',
        ]);

        $alerts = BudgetService::alerts(BudgetService::summaryForPeriod(2026, 9));

        self::assertCount(2, $alerts, 'the on-track budget must not appear as an alert');
        self::assertSame('exceeded', $alerts[0]['state'], 'exceeded outranks merely nearing the limit');
        self::assertSame('warning', $alerts[1]['state']);
    }

    public function testAMonthWithNoBudgetsReturnsAnEmptySummary(): void
    {
        self::assertSame([], BudgetService::summaryForPeriod(2030, 1));
    }
}
