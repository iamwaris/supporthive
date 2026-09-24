<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Domain\TransactionType;
use App\Models\Budget;
use App\Services\LedgerQuery;
use App\Services\TransactionService;
use PDOException;
use PHPUnit\Framework\TestCase;
use Tests\Support\BranchFixture;

/**
 * Module 6's "Done when": budget-vs-actual for a month equals a hand-written
 * SUM() over the same period. Also covers the unique-per-period constraint
 * that stops a category getting two conflicting budgets in one month.
 */
final class BudgetTest extends TestCase
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

        $this->branchId = BranchFixture::create('BG');

        $this->userId = $db->insert('users', [
            'name' => 'Budget Tester',
            'email' => 'budget-tester@test.local',
            'password_hash' => password_hash('unused-in-this-test', PASSWORD_DEFAULT),
            'role' => 'admin',
            'branch_id' => $this->branchId,
            'status' => 'active',
        ]);
        $_SESSION['_auth_user_id'] = $this->userId;
        $_SESSION['_active_branch_id'] = $this->branchId;

        $this->bankId = $db->insert('accounts', [
            'branch_id' => $this->branchId,
            'name' => 'BG Bank',
            'type' => 'bank',
            'opening_balance' => '0.00',
            'opening_date' => '2026-01-01',
            'is_active' => 1,
        ]);
        $this->expenseCategoryId = $db->insert('categories', [
            'branch_id' => $this->branchId,
            'name' => 'BG Expense Cat',
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
        $db->delete('budgets', 'category_id IN (SELECT id FROM categories WHERE name LIKE :n)', ['n' => 'BG %']);
        $db->run(
            'DELETE t FROM transactions t
             JOIN accounts a ON a.id = t.account_id
             WHERE a.name LIKE :n',
            ['n' => 'BG %']
        );
        $db->delete('categories', 'name LIKE :n', ['n' => 'BG %']);
        $db->delete('accounts', 'name LIKE :n', ['n' => 'BG %']);
        $db->delete('users', 'email = :e', ['e' => 'budget-tester@test.local']);
        $db->delete('branches', 'name LIKE :n', ['n' => 'BG %']);
    }

    private function postExpense(string $amount, string $date): void
    {
        TransactionService::post([
            'type' => TransactionType::Expense,
            'amount' => $amount,
            'account_id' => $this->bankId,
            'category_id' => $this->expenseCategoryId,
            'transaction_date' => $date,
            'description' => 'BG expense',
        ]);
    }

    public function testABudgetIsUniquePerYearMonthAndCategory(): void
    {
        $budgets = new Budget();

        $budgets->createFor([
            'year' => 2026,
            'month' => 9,
            'category_id' => $this->expenseCategoryId,
            'amount' => '5000.00',
            'alert_threshold_pct' => 80,
        ]);

        self::assertTrue($budgets->existsFor(2026, 9, $this->expenseCategoryId));
        self::assertFalse(
            $budgets->existsFor(2026, 10, $this->expenseCategoryId),
            'a different month is a different budget'
        );

        $this->expectException(PDOException::class);
        Database::instance()->insert('budgets', [
            'branch_id' => $this->branchId,
            'year' => 2026,
            'month' => 9,
            'category_id' => $this->expenseCategoryId,
            'amount' => '9999.00',
        ]);
    }

    /**
     * The acceptance criterion, verbatim: budget-vs-actual must equal a
     * hand-written SUM() over posted transactions in the same period.
     */
    public function testActualSpendMatchesAHandWrittenSumOverTheSamePeriod(): void
    {
        $this->postExpense('1200.00', '2026-09-03');
        $this->postExpense('300.50', '2026-09-20');
        // Outside the period and must not count.
        $this->postExpense('999.00', '2026-08-31');
        $this->postExpense('999.00', '2026-10-01');

        $handWritten = Database::instance()->value(
            "SELECT COALESCE(SUM(amount), 0) FROM transactions
             WHERE category_id = :cat AND status = 'posted' AND type = 'expense'
             AND transaction_date BETWEEN '2026-09-01' AND '2026-09-30'",
            ['cat' => $this->expenseCategoryId]
        );

        $viaLedgerQuery = LedgerQuery::posted()
            ->expensesOnly()
            ->between('2026-09-01', '2026-09-30')
            ->category($this->expenseCategoryId)
            ->totalAmount();

        self::assertSame((string) $handWritten, $viaLedgerQuery);
        self::assertSame('1500.50', $viaLedgerQuery);
    }

    public function testAVoidedExpenseDoesNotCountTowardActualSpend(): void
    {
        TransactionService::post([
            'type' => TransactionType::Expense,
            'amount' => '400.00',
            'account_id' => $this->bankId,
            'category_id' => $this->expenseCategoryId,
            'transaction_date' => '2026-09-05',
            'description' => 'BG to be voided',
        ]);
        $id = (int) Database::instance()->value(
            "SELECT id FROM transactions WHERE description = 'BG to be voided'"
        );
        TransactionService::void($id, 'keyed in error');

        $total = LedgerQuery::posted()
            ->expensesOnly()
            ->between('2026-09-01', '2026-09-30')
            ->category($this->expenseCategoryId)
            ->totalAmount();

        self::assertSame('0.00', $total);
    }
}
