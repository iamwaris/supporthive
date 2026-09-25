<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\RecurringOccurrenceController;
use App\Core\Database;
use PHPUnit\Framework\TestCase;
use Tests\Support\BranchFixture;
use Tests\Support\ControllerActionRunner;

/**
 * The approval queue (App\Controllers\RecurringOccurrenceController,
 * App\Services\RecurringRuleService::approve()/reject()/approveBulk()).
 * Rule CRUD (RecurringRuleControllerTest) and generation
 * (RecurringRuleGenerationTest) are separate features — this covers only
 * what happens to a draft already sitting in `recurring_occurrences`:
 *
 *   - approving posts a real transaction through TransactionService::post()
 *     and links it back onto the occurrence;
 *   - the type-specific detail row (expenses/sales) lands with it;
 *   - the approver's edits — not the generated snapshot — are what get
 *     posted, and the audit trail keeps both values when something changed;
 *   - rejecting never touches `transactions`;
 *   - tenant isolation and the already-reviewed guard, mirroring
 *     RecurringRuleControllerTest's approach for the sibling screen;
 *   - a bulk approval does not let one bad row abort the rest.
 */
final class RecurringOccurrenceApprovalTest extends TestCase
{
    private int $branchId;
    private int $userId;
    private int $bankId;
    private int $expenseCategoryId;
    private int $incomeCategoryId;
    private int $customerId;
    private int $ruleId;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->cleanUp();

        $db = Database::instance();

        $this->branchId = BranchFixture::create('ROA');

        $this->userId = $db->insert('users', [
            'name' => 'Recurring Occurrence Tester',
            'email' => 'roa-tester@test.local',
            'password_hash' => password_hash('unused-in-this-test', PASSWORD_DEFAULT),
            'role' => 'admin',
            'branch_id' => $this->branchId,
            'status' => 'active',
        ]);

        $this->bankId = $db->insert('accounts', [
            'branch_id' => $this->branchId,
            'name' => 'ROA Bank',
            'type' => 'bank',
            'opening_balance' => '0.00',
            'opening_date' => '2026-01-01',
            'is_active' => 1,
        ]);
        $this->expenseCategoryId = $db->insert('categories', [
            'branch_id' => $this->branchId,
            'name' => 'ROA Expense Cat',
            'type' => 'expense',
            'is_active' => 1,
        ]);
        $this->incomeCategoryId = $db->insert('categories', [
            'branch_id' => $this->branchId,
            'name' => 'ROA Income Cat',
            'type' => 'income',
            'is_active' => 1,
        ]);
        $this->customerId = $db->insert('customers', [
            'branch_id' => $this->branchId,
            'name' => 'ROA Customer',
            'is_active' => 1,
        ]);

        $this->ruleId = $this->createRule();
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
            "DELETE t FROM transactions t
             JOIN recurring_occurrences o ON o.transaction_id = t.id
             JOIN branches b ON b.id = o.branch_id
             WHERE b.name LIKE 'ROA%'"
        );
        $db->run(
            "DELETE o FROM recurring_occurrences o
             JOIN branches b ON b.id = o.branch_id
             WHERE b.name LIKE 'ROA%'"
        );
        $db->run(
            "DELETE r FROM recurring_rules r
             JOIN branches b ON b.id = r.branch_id
             WHERE b.name LIKE 'ROA%'"
        );
        $db->delete('categories', 'name LIKE :n', ['n' => 'ROA%']);
        $db->delete('accounts', 'name LIKE :n', ['n' => 'ROA%']);
        $db->delete('customers', 'name LIKE :n', ['n' => 'ROA%']);
        $db->delete('users', 'email LIKE :e', ['e' => 'roa-%@test.local']);
        $db->run(
            "DELETE a FROM audit_log a JOIN branches b ON b.id = a.branch_id WHERE b.name LIKE 'ROA%'"
        );
        $db->delete('branches', 'name LIKE :n', ['n' => 'ROA%']);
    }

    /** @param array<string,mixed> $overrides */
    private function createRule(array $overrides = []): int
    {
        $defaults = [
            'branch_id' => $this->branchId,
            'type' => 'expense',
            'description' => 'ROA rule',
            'amount' => '100.00',
            'account_id' => $this->bankId,
            'category_id' => $this->expenseCategoryId,
            'frequency' => 'monthly',
            'day_of_month' => 1,
            'start_date' => '2026-01-01',
            'is_active' => 1,
            'created_by' => $this->userId,
        ];

        return Database::instance()->insert('recurring_rules', array_merge($defaults, $overrides));
    }

    /** @param array<string,mixed> $overrides */
    private function createOccurrence(array $overrides = []): int
    {
        $defaults = [
            'rule_id' => $this->ruleId,
            'branch_id' => $this->branchId,
            'occurrence_date' => '2026-01-15',
            'type' => 'expense',
            'amount' => '250.00',
            'account_id' => $this->bankId,
            'category_id' => $this->expenseCategoryId,
            'description' => 'ROA occurrence',
            'vendor' => 'ROA Vendor',
            'customer_id' => null,
            'reference_no' => null,
            'notes' => 'ROA notes',
            'status' => 'pending_review',
        ];

        return Database::instance()->insert('recurring_occurrences', array_merge($defaults, $overrides));
    }

    /** @return array<string,mixed>|null */
    private function occurrence(int $id): ?array
    {
        return Database::instance()->first('SELECT * FROM recurring_occurrences WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    private function transaction(int $id): ?array
    {
        return Database::instance()->first('SELECT * FROM transactions WHERE id = :id', ['id' => $id]);
    }

    private function transactionsCount(): int
    {
        return (int) Database::instance()->value('SELECT COUNT(*) FROM transactions');
    }

    /**
     * @param array<string,mixed> $post
     * @return array{status:int,body:string}
     */
    private function approve(int $occurrenceId, array $post = []): array
    {
        $defaults = [
            'amount' => '250.00',
            'transaction_date' => '2026-01-15',
            'description' => 'ROA occurrence',
        ];

        return ControllerActionRunner::run(
            RecurringOccurrenceController::class,
            'approve',
            ['_auth_user_id' => $this->userId, '_active_branch_id' => $this->branchId],
            array_merge($defaults, $post),
            [],
            'POST',
            ['id' => (string) $occurrenceId]
        );
    }

    /**
     * @param array<string,mixed> $post
     * @return array{status:int,body:string}
     */
    private function reject(int $occurrenceId, array $post = []): array
    {
        return ControllerActionRunner::run(
            RecurringOccurrenceController::class,
            'reject',
            ['_auth_user_id' => $this->userId, '_active_branch_id' => $this->branchId],
            $post,
            [],
            'POST',
            ['id' => (string) $occurrenceId]
        );
    }

    /**
     * @param list<int> $ids
     * @return array{status:int,body:string}
     */
    private function approveBulk(array $ids): array
    {
        return ControllerActionRunner::run(
            RecurringOccurrenceController::class,
            'approveBulk',
            ['_auth_user_id' => $this->userId, '_active_branch_id' => $this->branchId],
            ['occurrence_ids' => array_map('strval', $ids)]
        );
    }

    public function testApprovingPostsARealTransactionAndLinksItBackToTheOccurrence(): void
    {
        $before = $this->transactionsCount();
        $occurrenceId = $this->createOccurrence();

        $result = $this->approve($occurrenceId);

        self::assertSame(302, $result['status']);
        self::assertSame($before + 1, $this->transactionsCount());

        $occurrence = $this->occurrence($occurrenceId);
        self::assertNotNull($occurrence);
        self::assertSame('approved', $occurrence['status']);
        self::assertNotNull($occurrence['transaction_id']);
        self::assertSame($this->userId, (int) $occurrence['reviewed_by']);
        self::assertNotNull($occurrence['reviewed_at']);

        $transaction = $this->transaction((int) $occurrence['transaction_id']);
        self::assertNotNull($transaction);
        self::assertSame('expense', $transaction['type']);
        self::assertSame('250.00', $transaction['amount']);
        self::assertSame('2026-01-15', substr((string) $transaction['transaction_date'], 0, 10));
        self::assertSame($this->bankId, (int) $transaction['account_id']);
        self::assertSame($this->expenseCategoryId, (int) $transaction['category_id']);
    }

    public function testApprovingAnExpenseDraftWritesTheExpensesDetailRow(): void
    {
        $occurrenceId = $this->createOccurrence(['vendor' => 'ROA Specific Vendor', 'notes' => 'ROA specific notes']);

        $this->approve($occurrenceId);

        $occurrence = $this->occurrence($occurrenceId);
        self::assertNotNull($occurrence);

        $expense = Database::instance()->first(
            'SELECT * FROM expenses WHERE transaction_id = :id',
            ['id' => (int) $occurrence['transaction_id']]
        );
        self::assertNotNull($expense, 'approving an expense draft must write the expenses detail row');
        self::assertSame('ROA Specific Vendor', $expense['vendor']);
        self::assertSame('ROA specific notes', $expense['notes']);

        self::assertNull(
            Database::instance()->first(
                'SELECT * FROM sales WHERE transaction_id = :id',
                ['id' => (int) $occurrence['transaction_id']]
            ),
            'an expense draft must not also write a sales row'
        );
    }

    public function testApprovingAnIncomeDraftWritesTheSalesRowWithNoInvoiceNumber(): void
    {
        $incomeRuleId = $this->createRule([
            'type' => 'income',
            'description' => 'ROA income rule',
            'category_id' => $this->incomeCategoryId,
            'customer_id' => $this->customerId,
            'vendor' => null,
        ]);
        $occurrenceId = $this->createOccurrence([
            'rule_id' => $incomeRuleId,
            'type' => 'income',
            'category_id' => $this->incomeCategoryId,
            'vendor' => null,
            'customer_id' => $this->customerId,
            'description' => 'ROA income occurrence',
        ]);

        $result = $this->approve($occurrenceId, ['description' => 'ROA income occurrence']);
        self::assertSame(302, $result['status']);

        $occurrence = $this->occurrence($occurrenceId);
        self::assertNotNull($occurrence);

        $sale = Database::instance()->first(
            'SELECT * FROM sales WHERE transaction_id = :id',
            ['id' => (int) $occurrence['transaction_id']]
        );
        self::assertNotNull($sale, 'approving an income draft must write the sales detail row');
        self::assertSame($this->customerId, (int) $sale['customer_id']);
        self::assertNull($sale['invoice_no'], 'a recurring rule has no invoice number to draw one from');
    }

    public function testEditedAmountDateAndDescriptionArePostedInsteadOfTheGeneratedSnapshot(): void
    {
        $occurrenceId = $this->createOccurrence([
            'amount' => '100.00',
            'occurrence_date' => '2026-02-01',
            'description' => 'ROA original description',
        ]);

        $this->approve($occurrenceId, [
            'amount' => '150.00',
            'transaction_date' => '2026-02-10',
            'description' => 'ROA edited description',
        ]);

        $occurrence = $this->occurrence($occurrenceId);
        self::assertNotNull($occurrence);
        $transaction = $this->transaction((int) $occurrence['transaction_id']);
        self::assertNotNull($transaction);

        self::assertSame('150.00', $transaction['amount'], 'the edited amount must be posted, not the snapshot');
        self::assertSame(
            '2026-02-10',
            substr((string) $transaction['transaction_date'], 0, 10),
            'the edited date must be posted, not the snapshot'
        );
        self::assertSame(
            'ROA edited description',
            $transaction['description'],
            'the edited description must be posted, not the snapshot'
        );

        // The occurrence itself keeps its original snapshot — editing at
        // approval time changes what is posted, not the historical draft.
        self::assertSame('100.00', $occurrence['amount']);
        self::assertSame('ROA original description', $occurrence['description']);
    }

    public function testAuditPayloadRecordsBothTheOriginalAndThePostedValueWhenEdited(): void
    {
        $occurrenceId = $this->createOccurrence([
            'amount' => '100.00',
            'occurrence_date' => '2026-03-01',
            'description' => 'ROA audit original',
        ]);

        $this->approve($occurrenceId, [
            'amount' => '175.50',
            'transaction_date' => '2026-03-01',
            'description' => 'ROA audit edited',
        ]);

        $entry = Database::instance()->first(
            "SELECT * FROM audit_log
             WHERE action = 'recurring_occurrence.approved' AND entity_type = 'recurring_occurrences'
               AND entity_id = :id",
            ['id' => $occurrenceId]
        );
        self::assertNotNull($entry, 'approving must write an audit_log row');

        /** @var array<string,mixed> $meta */
        $meta = json_decode((string) $entry['meta'], true, 512, JSON_THROW_ON_ERROR);
        $edited = $meta['after']['edited'] ?? null;
        self::assertIsArray($edited, 'an edited approval must record what changed');

        self::assertSame('100.00', $edited['amount']['original']);
        self::assertSame('175.50', $edited['amount']['posted']);
        self::assertSame('ROA audit original', $edited['description']['original']);
        self::assertSame('ROA audit edited', $edited['description']['posted']);
    }

    public function testApprovingWithoutAnyEditsRecordsNoEditedPayload(): void
    {
        $occurrenceId = $this->createOccurrence([
            'amount' => '100.00',
            'occurrence_date' => '2026-04-01',
            'description' => 'ROA unedited',
        ]);

        $this->approve($occurrenceId, [
            'amount' => '100.00',
            'transaction_date' => '2026-04-01',
            'description' => 'ROA unedited',
        ]);

        $entry = Database::instance()->first(
            "SELECT * FROM audit_log
             WHERE action = 'recurring_occurrence.approved' AND entity_type = 'recurring_occurrences'
               AND entity_id = :id",
            ['id' => $occurrenceId]
        );
        self::assertNotNull($entry);

        /** @var array<string,mixed> $meta */
        $meta = json_decode((string) $entry['meta'], true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey(
            'edited',
            $meta['after'],
            'nothing changed, so there must be no edited payload to report'
        );
    }

    public function testRejectingNeverCreatesATransaction(): void
    {
        $before = $this->transactionsCount();
        $occurrenceId = $this->createOccurrence();

        $result = $this->reject($occurrenceId, ['reject_reason' => 'ROA duplicate of another entry']);

        self::assertSame(302, $result['status']);
        self::assertSame($before, $this->transactionsCount(), 'rejecting must never touch the ledger');

        $occurrence = $this->occurrence($occurrenceId);
        self::assertNotNull($occurrence);
        self::assertSame('rejected', $occurrence['status']);
        self::assertNull($occurrence['transaction_id']);
        self::assertSame('ROA duplicate of another entry', $occurrence['reject_reason']);
        self::assertSame($this->userId, (int) $occurrence['reviewed_by']);
    }

    public function testAnotherBranchsOccurrenceCannotBeApprovedOrRejected(): void
    {
        $db = Database::instance();
        $otherBranchId = BranchFixture::create('ROAB');
        $otherUserId = $db->insert('users', [
            'name' => 'ROAB Other Branch Tester',
            'email' => 'roa-other-tester@test.local',
            'password_hash' => password_hash('unused-in-this-test', PASSWORD_DEFAULT),
            'role' => 'admin',
            'branch_id' => $otherBranchId,
            'status' => 'active',
        ]);
        $otherBankId = $db->insert('accounts', [
            'branch_id' => $otherBranchId,
            'name' => 'ROAB Bank',
            'type' => 'bank',
            'opening_balance' => '0.00',
            'opening_date' => '2026-01-01',
            'is_active' => 1,
        ]);
        $otherCategoryId = $db->insert('categories', [
            'branch_id' => $otherBranchId,
            'name' => 'ROAB Expense Cat',
            'type' => 'expense',
            'is_active' => 1,
        ]);
        $otherRuleId = $db->insert('recurring_rules', [
            'branch_id' => $otherBranchId,
            'type' => 'expense',
            'description' => 'ROAB rule',
            'amount' => '50.00',
            'account_id' => $otherBankId,
            'category_id' => $otherCategoryId,
            'frequency' => 'monthly',
            'day_of_month' => 1,
            'start_date' => '2026-01-01',
            'is_active' => 1,
            'created_by' => $otherUserId,
        ]);
        $otherOccurrenceId = $db->insert('recurring_occurrences', [
            'rule_id' => $otherRuleId,
            'branch_id' => $otherBranchId,
            'occurrence_date' => '2026-01-15',
            'type' => 'expense',
            'amount' => '50.00',
            'account_id' => $otherBankId,
            'category_id' => $otherCategoryId,
            'description' => 'ROAB occurrence',
            'status' => 'pending_review',
        ]);

        $before = $this->transactionsCount();

        try {
            // Both attempts run as THIS test's Branch A session, targeting
            // Branch B's occurrence id — RecurringRuleService::approve()/
            // reject() load it via the tenant-scoped Model::find(), so it
            // must simply not be found, the same guarantee
            // RecurringRuleControllerTest establishes for the sibling
            // screen's rule ids.
            $approveResult = $this->approve($otherOccurrenceId, [
                'amount' => '50.00',
                'transaction_date' => '2026-01-15',
                'description' => 'ROAB occurrence',
            ]);
            self::assertSame(302, $approveResult['status']);

            $rejectResult = $this->reject($otherOccurrenceId);
            self::assertSame(302, $rejectResult['status']);

            self::assertSame($before, $this->transactionsCount(), 'neither attempt may have posted anything');

            $stillIntact = $db->first(
                'SELECT * FROM recurring_occurrences WHERE id = :id',
                ['id' => $otherOccurrenceId]
            );
            self::assertNotNull($stillIntact);
            self::assertSame(
                'pending_review',
                $stillIntact['status'],
                'another branch\'s draft must be untouched by either attempt'
            );
        } finally {
            $db->delete('recurring_occurrences', 'id = :id', ['id' => $otherOccurrenceId]);
            $db->delete('recurring_rules', 'id = :id', ['id' => $otherRuleId]);
            $db->delete('categories', 'id = :id', ['id' => $otherCategoryId]);
            $db->delete('accounts', 'id = :id', ['id' => $otherBankId]);
            $db->delete('users', 'id = :id', ['id' => $otherUserId]);
            $db->delete('branches', 'id = :id', ['id' => $otherBranchId]);
        }
    }

    public function testAnAlreadyApprovedOccurrenceCannotBeApprovedAgain(): void
    {
        $occurrenceId = $this->createOccurrence();
        $this->approve($occurrenceId);

        $occurrenceAfterFirst = $this->occurrence($occurrenceId);
        self::assertNotNull($occurrenceAfterFirst);
        $firstTransactionId = (int) $occurrenceAfterFirst['transaction_id'];
        $countAfterFirst = $this->transactionsCount();

        $secondResult = $this->approve($occurrenceId, ['amount' => '999.00']);

        self::assertSame(302, $secondResult['status']);
        self::assertSame(
            $countAfterFirst,
            $this->transactionsCount(),
            'approving an already-reviewed draft a second time must not post again'
        );

        $occurrenceAfterSecond = $this->occurrence($occurrenceId);
        self::assertNotNull($occurrenceAfterSecond);
        self::assertSame(
            $firstTransactionId,
            (int) $occurrenceAfterSecond['transaction_id'],
            'the original posted transaction must be left exactly as it was'
        );
    }

    public function testBulkApproveSkipsOneAlreadyReviewedItemWithoutAbortingTheRest(): void
    {
        $before = $this->transactionsCount();

        // Distinct occurrence_date per row: all three share the same
        // rule_id, and uq_occurrence_rule_date(rule_id, occurrence_date)
        // would otherwise reject the second and third insert.
        $firstId = $this->createOccurrence([
            'description' => 'ROA bulk one', 'amount' => '10.00', 'occurrence_date' => '2026-05-01',
        ]);
        $alreadyReviewedId = $this->createOccurrence([
            'description' => 'ROA bulk two', 'amount' => '20.00', 'occurrence_date' => '2026-05-02',
        ]);
        $thirdId = $this->createOccurrence([
            'description' => 'ROA bulk three', 'amount' => '30.00', 'occurrence_date' => '2026-05-03',
        ]);

        // Pre-approve the middle one directly through the service path, so
        // by the time the bulk call reaches it, it is no longer pending —
        // exactly the "one bad row" case the bulk action must tolerate.
        $this->approve($alreadyReviewedId, [
            'amount' => '20.00', 'transaction_date' => '2026-05-02', 'description' => 'ROA bulk two',
        ]);
        $occurrenceBeforeBulk = $this->occurrence($alreadyReviewedId);
        self::assertNotNull($occurrenceBeforeBulk);
        $preBulkTransactionId = (int) $occurrenceBeforeBulk['transaction_id'];
        // The pre-approval above already posted its own transaction; the
        // bulk call below must add exactly two more (first + third), not
        // three, since the already-reviewed row must be skipped, not
        // re-posted.
        $countBeforeBulk = $this->transactionsCount();
        self::assertSame($before + 1, $countBeforeBulk);

        $result = $this->approveBulk([$firstId, $alreadyReviewedId, $thirdId]);

        self::assertSame(302, $result['status']);
        self::assertSame(
            $countBeforeBulk + 2,
            $this->transactionsCount(),
            'the two valid drafts must post, the already-reviewed one must be skipped, not retried'
        );

        $first = $this->occurrence($firstId);
        $third = $this->occurrence($thirdId);
        self::assertNotNull($first);
        self::assertNotNull($third);
        self::assertSame('approved', $first['status']);
        self::assertSame('approved', $third['status']);
        self::assertNotNull($first['transaction_id']);
        self::assertNotNull($third['transaction_id']);

        $stillReviewed = $this->occurrence($alreadyReviewedId);
        self::assertNotNull($stillReviewed);
        self::assertSame(
            $preBulkTransactionId,
            (int) $stillReviewed['transaction_id'],
            'the already-approved row must be left exactly as it was, not double-posted'
        );
    }
}
