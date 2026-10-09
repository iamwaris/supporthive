<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Domain\TransactionType;
use App\Models\Expense;
use App\Services\ExpenseNotifier;
use App\Services\Mail\Mailer;
use App\Services\Settings;
use App\Services\TransactionService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\BranchFixture;
use Tests\Support\RecordingMailTransport;

/**
 * Void emails end to end through TransactionService::void(): one email to
 * the branch's notification list once the void commits, nothing for a void
 * that is refused, rolled back or repeated, and a mail failure never undoes
 * the void.
 */
final class TransactionVoidNotificationTest extends TestCase
{
    private int $branchId;
    private int $userId;
    private int $bankId;
    private int $cashId;
    private int $expenseCategoryId;
    private RecordingMailTransport $transport;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->cleanUp();

        $db = Database::instance();
        $this->branchId = BranchFixture::create('TVN');

        $this->userId = $db->insert('users', [
            'name' => 'TVN Tester',
            'email' => 'tvn-tester@test.local',
            'password_hash' => password_hash('unused-in-this-test', PASSWORD_DEFAULT),
            'role' => 'admin',
            'branch_id' => $this->branchId,
            'status' => 'active',
        ]);
        $_SESSION['_auth_user_id'] = $this->userId;
        $_SESSION['_active_branch_id'] = $this->branchId;

        $this->bankId = $this->account('TVN Bank', 'bank');
        $this->cashId = $this->account('TVN Cash', 'cash');
        $this->expenseCategoryId = $db->insert('categories', [
            'branch_id' => $this->branchId,
            'name' => 'TVN Supplies',
            'type' => 'expense',
            'is_active' => 1,
        ]);

        Settings::flush();
        $this->enableNotifications("owner@example.com\npartner@example.com");
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
        $branch = ['n' => 'TVN %'];
        $db->run('DELETE x FROM audit_log x JOIN branches b ON b.id = x.branch_id WHERE b.name LIKE :n', $branch);
        $db->run('DELETE x FROM transactions x JOIN branches b ON b.id = x.branch_id WHERE b.name LIKE :n', $branch);
        $db->run('DELETE x FROM settings x JOIN branches b ON b.id = x.branch_id WHERE b.name LIKE :n', $branch);
        $db->delete('categories', 'name LIKE :n', $branch);
        $db->delete('accounts', 'name LIKE :n', $branch);
        $db->delete('users', 'email = :e', ['e' => 'tvn-tester@test.local']);
        $db->delete('branches', 'name LIKE :n', $branch);
    }

    private function account(string $name, string $type): int
    {
        return Database::instance()->insert('accounts', [
            'branch_id' => $this->branchId,
            'name' => $name,
            'type' => $type,
            'opening_balance' => '0.00',
            'opening_date' => '2026-01-01',
            'is_active' => 1,
        ]);
    }

    private function enableNotifications(string $recipients): void
    {
        Settings::set(ExpenseNotifier::SETTING_ENABLED, true);
        Settings::set(ExpenseNotifier::SETTING_RECIPIENTS, $recipients);
    }

    /** Posts an expense, then swaps in a fresh transport so the expense-added email is not counted. */
    private function postExpense(): int
    {
        $id = TransactionService::post([
            'type' => TransactionType::Expense,
            'amount' => '1250.50',
            'account_id' => $this->bankId,
            'category_id' => $this->expenseCategoryId,
            'transaction_date' => '2026-09-14',
            'description' => 'TVN printer paper',
            'reference_no' => 'TVN-REF-1',
        ], static function (int $id): void {
            Expense::write($id, 'TVN Vendor', 'private note');
        });

        $this->useFreshTransport();

        return $id;
    }

    private function useFreshTransport(bool $failing = false): void
    {
        $this->transport = new RecordingMailTransport($failing);
        Mailer::useTransport($this->transport);
    }

    private function ledgerStatus(int $id): string
    {
        return (string) Database::instance()->value('SELECT status FROM transactions WHERE id = :id', ['id' => $id]);
    }

    public function testVoidEmailsTheBranchListOnceWithTheKeyFacts(): void
    {
        $id = $this->postExpense();

        self::assertSame(1, TransactionService::void($id, 'Entered twice'));

        self::assertCount(1, $this->transport->sent);
        $message = $this->transport->sent[0];
        self::assertSame(['owner@example.com', 'partner@example.com'], $message->to);
        self::assertStringStartsWith('Transaction voided: Expense Rs 1,250.50', $message->subject);
        foreach (
            [
                '#' . $id . ' (TVN-REF-1)', '14 Sep 2026', 'TVN Bank', 'TVN Supplies', 'TVN Vendor',
                'TVN printer paper', 'Voided by:   TVN Tester', 'Entered twice', 'TVN Branch',
            ] as $expected
        ) {
            self::assertStringContainsString($expected, $message->textBody);
        }
        self::assertStringNotContainsString('private note', $message->textBody, 'notes are not emailed');
    }

    public function testVoidingATransferLegSendsOneEmailForBothLegs(): void
    {
        $group = TransactionService::postTransfer($this->bankId, $this->cashId, '300.00', '2026-09-15', 'TVN float');
        $legId = (int) Database::instance()->value(
            "SELECT id FROM transactions WHERE transfer_group = :g AND type = 'transfer_out'",
            ['g' => $group]
        );

        self::assertSame(2, TransactionService::void($legId, 'Wrong account'));

        self::assertCount(1, $this->transport->sent);
        self::assertStringContainsString('Transfer out', $this->transport->sent[0]->subject);
        self::assertStringContainsString('both legs were voided', $this->transport->sent[0]->textBody);
    }

    public function testNoRecipientsMeansNoEmail(): void
    {
        $id = $this->postExpense();
        $this->enableNotifications('');

        TransactionService::void($id, 'Entered twice');

        self::assertSame('void', $this->ledgerStatus($id));
        self::assertSame(0, $this->transport->attempts);
    }

    public function testSwitchedOffMeansNoEmail(): void
    {
        $id = $this->postExpense();
        Settings::set(ExpenseNotifier::SETTING_ENABLED, false);

        TransactionService::void($id, 'Entered twice');

        self::assertSame('void', $this->ledgerStatus($id));
        self::assertSame(0, $this->transport->attempts);
    }

    public function testRejectedVoidsSendNothing(): void
    {
        $id = $this->postExpense();

        try {
            TransactionService::void($id, '   ');
            self::fail('a void without a reason must be refused');
        } catch (InvalidArgumentException) {
        }

        // Another branch, with its own list switched on, still cannot reach this row.
        $otherBranch = BranchFixture::create('TVN Other');
        $_SESSION['_active_branch_id'] = $otherBranch;
        Settings::flush();
        $this->enableNotifications('other@example.com');
        try {
            TransactionService::void($id, 'Not yours');
            self::fail('a row from another branch must not be voidable');
        } catch (RuntimeException $e) {
            self::assertSame('That transaction no longer exists.', $e->getMessage());
        }
        $_SESSION['_active_branch_id'] = $this->branchId;
        Settings::flush();

        unset($_SESSION['_auth_user_id']);
        try {
            TransactionService::void($id, 'Nobody signed in');
            self::fail('an unattributed void must be refused');
        } catch (RuntimeException) {
        }

        self::assertSame('posted', $this->ledgerStatus($id));
        self::assertSame(0, $this->transport->attempts);
    }

    public function testRolledBackVoidSendsNothing(): void
    {
        $id = $this->postExpense();

        try {
            Database::instance()->transaction(static function () use ($id): void {
                TransactionService::void($id, 'Entered twice');
                throw new RuntimeException('outer failure');
            });
        } catch (RuntimeException) {
        }

        self::assertSame('posted', $this->ledgerStatus($id));
        self::assertSame(0, $this->transport->attempts);
    }

    public function testMailFailureStillVoidsAndIsLogged(): void
    {
        $id = $this->postExpense();
        $this->useFreshTransport(failing: true);
        $log = STORAGE_PATH . '/logs/app-' . date('Y-m-d') . '.log';
        clearstatcache();
        $offset = is_file($log) ? (int) filesize($log) : 0;

        self::assertSame(1, TransactionService::void($id, 'Entered twice'));

        self::assertSame(1, $this->transport->attempts);
        self::assertSame('void', $this->ledgerStatus($id), 'a mail failure must never undo the void');
        $written = (string) file_get_contents($log, false, null, $offset);
        self::assertStringContainsString('Void notification email was not sent', $written);
        self::assertStringContainsString('"transaction_id":' . $id, $written);
    }

    public function testVoidingAgainSendsNoSecondEmail(): void
    {
        $id = $this->postExpense();
        TransactionService::void($id, 'Entered twice');

        try {
            TransactionService::void($id, 'And again');
            self::fail('a second void must be refused');
        } catch (RuntimeException $e) {
            self::assertSame('That transaction is already voided.', $e->getMessage());
        }

        self::assertSame(1, $this->transport->attempts);
        self::assertSame(
            1,
            (int) Database::instance()->value(
                "SELECT COUNT(*) FROM audit_log WHERE action = 'transaction.voided' AND entity_id = :id",
                ['id' => $id]
            )
        );
    }
}
