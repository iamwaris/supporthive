<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Domain\TransactionType;
use App\Models\Attachment;
use App\Services\TransactionService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\BranchFixture;

/**
 * Attachments (Module 10 / decision D-4): the file itself is streamed only by
 * App\Controllers\DocumentController@show, which resolves an id through
 * Attachment::find() — a plain App\Core\Model call, branch-scoped like every
 * other table since the multi-branch retrofit. This is the test for that
 * guarantee: a receipt id from another branch must not exist from here, the
 * same way BranchIsolationTest asserts it for every other table.
 */
final class AttachmentTest extends TestCase
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

        $this->branchAId = BranchFixture::create('ATT-A');
        $this->branchBId = BranchFixture::create('ATT-B');

        $this->userAId = $db->insert('users', [
            'name' => 'Att Tester A',
            'email' => 'att-tester-a@test.local',
            'password_hash' => password_hash('unused-in-this-test', PASSWORD_DEFAULT),
            'role' => 'admin',
            'branch_id' => $this->branchAId,
            'status' => 'active',
        ]);
        $this->userBId = $db->insert('users', [
            'name' => 'Att Tester B',
            'email' => 'att-tester-b@test.local',
            'password_hash' => password_hash('unused-in-this-test', PASSWORD_DEFAULT),
            'role' => 'admin',
            'branch_id' => $this->branchBId,
            'status' => 'active',
        ]);

        $this->bankAId = $db->insert('accounts', [
            'branch_id' => $this->branchAId, 'name' => 'ATT Bank', 'type' => 'bank',
            'opening_balance' => '0.00', 'opening_date' => '2026-01-01', 'is_active' => 1,
        ]);
        $this->bankBId = $db->insert('accounts', [
            'branch_id' => $this->branchBId, 'name' => 'ATT Bank', 'type' => 'bank',
            'opening_balance' => '0.00', 'opening_date' => '2026-01-01', 'is_active' => 1,
        ]);

        $this->categoryAId = $db->insert('categories', [
            'branch_id' => $this->branchAId, 'name' => 'ATT Expense Cat', 'type' => 'expense', 'is_active' => 1,
        ]);
        $this->categoryBId = $db->insert('categories', [
            'branch_id' => $this->branchBId, 'name' => 'ATT Expense Cat', 'type' => 'expense', 'is_active' => 1,
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
            'DELETE a FROM attachments a JOIN transactions t ON t.id = a.transaction_id
             JOIN accounts ac ON ac.id = t.account_id WHERE ac.name = :n',
            ['n' => 'ATT Bank']
        );
        $db->run(
            'DELETE t FROM transactions t JOIN accounts a ON a.id = t.account_id WHERE a.name = :n',
            ['n' => 'ATT Bank']
        );
        $db->delete('categories', 'name = :n', ['n' => 'ATT Expense Cat']);
        $db->delete('accounts', 'name = :n', ['n' => 'ATT Bank']);
        $db->delete('users', 'email LIKE :e', ['e' => 'att-tester-%@test.local']);
        $db->run(
            'DELETE a FROM audit_log a JOIN branches b ON b.id = a.branch_id WHERE b.name LIKE :n',
            ['n' => 'ATT-%']
        );
        $db->delete('branches', 'name LIKE :n', ['n' => 'ATT-%']);
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

    private function recordAttachment(int $transactionId, int $userId): int
    {
        return (new Attachment())->create([
            'transaction_id' => $transactionId,
            'original_filename' => 'receipt.pdf',
            'stored_filename' => bin2hex(random_bytes(16)) . '.pdf',
            'mime' => 'application/pdf',
            'size' => 1024,
            'uploaded_by' => $userId,
        ]);
    }

    public function testFindNeverReachesAnotherBranchsAttachmentById(): void
    {
        $this->asBranchA();
        $txA = $this->postExpense($this->bankAId, $this->categoryAId, 'ATT expense A');
        $attachmentAId = $this->recordAttachment($txA, $this->userAId);

        $this->asBranchB();
        $txB = $this->postExpense($this->bankBId, $this->categoryBId, 'ATT expense B');
        $attachmentBId = $this->recordAttachment($txB, $this->userBId);

        $this->asBranchA();
        self::assertNotNull(
            (new Attachment())->find($attachmentAId),
            'Branch A must find its own attachment — this is what DocumentController@show relies on'
        );
        self::assertNull(
            (new Attachment())->find($attachmentBId),
            'Branch A must not be able to resolve Branch B\'s attachment id — a leaked/guessed id must 404, not stream'
        );
    }

    public function testForTransactionOnlyReturnsSameBranchRows(): void
    {
        $this->asBranchA();
        $txA = $this->postExpense($this->bankAId, $this->categoryAId, 'ATT expense A2');
        $this->recordAttachment($txA, $this->userAId);
        $this->recordAttachment($txA, $this->userAId);

        $rows = (new Attachment())->forTransaction($txA);
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame($this->branchAId, (int) $row['branch_id']);
        }
    }

    public function testForTransactionsBatchesByTransactionIdWithinOneBranch(): void
    {
        $this->asBranchA();
        $txOne = $this->postExpense($this->bankAId, $this->categoryAId, 'ATT expense A3');
        $txTwo = $this->postExpense($this->bankAId, $this->categoryAId, 'ATT expense A4');
        $this->recordAttachment($txOne, $this->userAId);

        $byTransaction = (new Attachment())->forTransactions([$txOne, $txTwo]);

        self::assertArrayHasKey($txOne, $byTransaction);
        self::assertArrayNotHasKey($txTwo, $byTransaction, 'a transaction with no attachment must not get an entry');
        self::assertCount(1, $byTransaction[$txOne]);
    }

    public function testAttachUploadRejectsAnOversizedFileWithoutTouchingTheFilesystem(): void
    {
        $this->asBranchA();
        $txA = $this->postExpense($this->bankAId, $this->categoryAId, 'ATT expense A5');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('too large');

        (new Attachment())->attachUpload($txA, [
            'name' => 'huge.pdf',
            'type' => 'application/pdf',
            'tmp_name' => '',
            'error' => UPLOAD_ERR_FORM_SIZE,
            'size' => 0,
        ]);
    }
}
