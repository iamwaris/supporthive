<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Domain\TransactionType;
use App\Models\Expense;
use App\Services\ExpenseNotifier;
use App\Services\Mail\Mailer;
use App\Services\RecurringRuleService;
use App\Services\Settings;
use App\Services\TransactionService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\BranchFixture;
use Tests\Support\RecordingMailTransport;

/**
 * Expense emails end to end through TransactionService::post(): sent once
 * the expense is committed, never for a rolled-back one or a non-expense, and
 * a mail failure never costs the user their saved expense.
 */
final class ExpenseNotificationTest extends TestCase
{
    private int $branchId;
    private int $userId;
    private int $bankId;
    private int $expenseCategoryId;
    private int $incomeCategoryId;
    private RecordingMailTransport $transport;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->cleanUp();

        $db = Database::instance();
        $this->branchId = BranchFixture::create('ENT');

        $this->userId = $db->insert('users', [
            'name' => 'ENT Tester',
            'email' => 'ent-tester@test.local',
            'password_hash' => password_hash('unused-in-this-test', PASSWORD_DEFAULT),
            'role' => 'admin',
            'branch_id' => $this->branchId,
            'status' => 'active',
        ]);
        $_SESSION['_auth_user_id'] = $this->userId;
        $_SESSION['_active_branch_id'] = $this->branchId;

        $this->bankId = $db->insert('accounts', [
            'branch_id' => $this->branchId,
            'name' => 'ENT Bank',
            'type' => 'bank',
            'opening_balance' => '0.00',
            'opening_date' => '2026-01-01',
            'is_active' => 1,
        ]);
        $this->expenseCategoryId = $db->insert('categories', [
            'branch_id' => $this->branchId,
            'name' => 'ENT Supplies',
            'type' => 'expense',
            'is_active' => 1,
        ]);
        $this->incomeCategoryId = $db->insert('categories', [
            'branch_id' => $this->branchId,
            'name' => 'ENT Sales',
            'type' => 'income',
            'is_active' => 1,
        ]);

        Settings::flush();
        Settings::set(ExpenseNotifier::SETTING_ENABLED, true);
        Settings::set(ExpenseNotifier::SETTING_RECIPIENTS, "owner@example.com\npartner@example.com");
        Settings::set('currency_symbol', 'Rs');

        $this->transport = new RecordingMailTransport();
        Mailer::useTransport($this->transport);
    }

    protected function tearDown(): void
    {
        Mailer::useTransport(null);
        $this->cleanUp();
        $_SESSION = [];
        Settings::flush();
    }

    private function cleanUp(): void
    {
        $db = Database::instance();
        $branch = ['n' => 'ENT %'];
        // Children before parents: occurrences point at transactions, and
        // everything points at the branch.
        $db->run('DELETE x FROM audit_log x JOIN branches b ON b.id = x.branch_id WHERE b.name LIKE :n', $branch);
        $db->run(
            'DELETE x FROM recurring_occurrences x JOIN branches b ON b.id = x.branch_id WHERE b.name LIKE :n',
            $branch
        );
        $db->run('DELETE x FROM recurring_rules x JOIN branches b ON b.id = x.branch_id WHERE b.name LIKE :n', $branch);
        $db->run('DELETE x FROM transactions x JOIN branches b ON b.id = x.branch_id WHERE b.name LIKE :n', $branch);
        $db->run('DELETE x FROM settings x JOIN branches b ON b.id = x.branch_id WHERE b.name LIKE :n', $branch);
        $db->delete('categories', 'name LIKE :n', ['n' => 'ENT %']);
        $db->delete('accounts', 'name LIKE :n', ['n' => 'ENT %']);
        $db->delete('users', 'email = :e', ['e' => 'ent-tester@test.local']);
        $db->delete('branches', 'name LIKE :n', ['n' => 'ENT %']);
    }

    private function postExpense(?callable $writeDetail = null): int
    {
        return TransactionService::post([
            'type' => TransactionType::Expense,
            'amount' => '1250.50',
            'account_id' => $this->bankId,
            'category_id' => $this->expenseCategoryId,
            'transaction_date' => '2026-09-14',
            'description' => 'ENT expense',
        ], $writeDetail ?? static function (int $id): void {
            Expense::write($id, 'ENT Vendor', 'private note');
        });
    }

    public function testManualExpenseEmailsTheBranchList(): void
    {
        $this->postExpense();

        self::assertCount(1, $this->transport->sent);
        $message = $this->transport->sent[0];
        self::assertSame(['owner@example.com', 'partner@example.com'], $message->to);
        self::assertStringContainsString('Rs 1,250.50', $message->subject);
        foreach (['ENT Supplies', 'ENT Vendor', 'ENT Tester', 'Manual entry', 'ENT Branch'] as $expected) {
            self::assertStringContainsString($expected, $message->textBody);
        }
        self::assertStringNotContainsString('private note', $message->textBody, 'notes are not emailed');
    }

    public function testExpenseIsSavedEvenWhenTheMailServerFails(): void
    {
        $failing = new RecordingMailTransport(failing: true);
        Mailer::useTransport($failing);

        $id = $this->postExpense();

        self::assertSame(1, $failing->attempts);
        self::assertNotNull(
            Database::instance()->first('SELECT id FROM transactions WHERE id = :id', ['id' => $id]),
            'a mail failure must never roll back or hide the expense'
        );
        self::assertNotNull(
            Database::instance()->first('SELECT transaction_id FROM expenses WHERE transaction_id = :id', ['id' => $id])
        );
    }

    public function testRolledBackExpenseSendsNothing(): void
    {
        try {
            $this->postExpense(static function (): void {
                throw new RuntimeException('detail write failed');
            });
            self::fail('the detail failure must propagate');
        } catch (RuntimeException $e) {
            self::assertSame('detail write failed', $e->getMessage());
        }

        self::assertSame(0, $this->transport->attempts);
    }

    public function testNestedPostWaitsForTheOuterCommitAndIsDroppedOnOuterRollback(): void
    {
        $sentInside = null;
        Database::instance()->transaction(function () use (&$sentInside): void {
            $this->postExpense();
            $sentInside = $this->transport->attempts;
        });
        self::assertSame(0, $sentInside, 'nothing is sent while the outer transaction is still open');
        self::assertSame(1, $this->transport->attempts);

        try {
            Database::instance()->transaction(function (): void {
                $this->postExpense();
                throw new RuntimeException('outer failure');
            });
        } catch (RuntimeException) {
        }
        self::assertSame(1, $this->transport->attempts, 'a rolled-back outer transaction sends nothing');
    }

    public function testIncomeSendsNothing(): void
    {
        TransactionService::post([
            'type' => TransactionType::Income,
            'amount' => '500.00',
            'account_id' => $this->bankId,
            'category_id' => $this->incomeCategoryId,
            'transaction_date' => '2026-09-14',
            'description' => 'ENT income',
        ]);

        self::assertSame(0, $this->transport->attempts);
    }

    public function testDisabledBranchSendsNothing(): void
    {
        Settings::set(ExpenseNotifier::SETTING_ENABLED, false);

        $this->postExpense();

        self::assertSame(0, $this->transport->attempts);
    }

    public function testApprovedRecurringExpenseIsLabelledAsSuch(): void
    {
        $db = Database::instance();
        $ruleId = $db->insert('recurring_rules', [
            'branch_id' => $this->branchId,
            'type' => 'expense',
            'description' => 'ENT rent',
            'amount' => '900.00',
            'account_id' => $this->bankId,
            'category_id' => $this->expenseCategoryId,
            'frequency' => 'monthly',
            'day_of_month' => 1,
            'start_date' => '2026-01-01',
            'is_active' => 1,
            'created_by' => $this->userId,
        ]);
        $occurrenceId = $db->insert('recurring_occurrences', [
            'rule_id' => $ruleId,
            'branch_id' => $this->branchId,
            'occurrence_date' => '2026-09-01',
            'type' => 'expense',
            'amount' => '900.00',
            'account_id' => $this->bankId,
            'category_id' => $this->expenseCategoryId,
            'description' => 'ENT rent',
            'vendor' => 'ENT Landlord',
            'status' => 'pending_review',
        ]);

        RecurringRuleService::approve($occurrenceId, []);

        self::assertCount(1, $this->transport->sent);
        self::assertStringContainsString('Approved recurring transaction', $this->transport->sent[0]->textBody);
        self::assertStringContainsString('ENT Landlord', $this->transport->sent[0]->textBody);
    }
}
