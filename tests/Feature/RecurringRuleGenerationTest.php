<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Models\RecurringOccurrence;
use App\Services\RecurringRuleService;
use PHPUnit\Framework\TestCase;
use Tests\Support\BranchFixture;

/**
 * Covers the generation path only (the approval/web path is a separate
 * task): RecurringRuleService::generateForBranch() turning active
 * `recurring_rules` into draft `recurring_occurrences` rows.
 *
 * Deliberately does NOT set `$_SESSION['_auth_user_id']` /
 * `$_SESSION['_active_branch_id']` the way BudgetTest does — the whole point
 * of the explicit-branch CLI path (RecurringRule::activeForBranch() /
 * advanceCursor(), RecurringOccurrence::createForBranch(),
 * RecurringRuleService::generateForBranch()) is that it works with no
 * session at all, the way the real nightly cron runs it. Leaving $_SESSION
 * empty throughout is the regression test for that contract, not just a
 * convenience.
 */
final class RecurringRuleGenerationTest extends TestCase
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

        $this->branchId = BranchFixture::create('RG');

        $this->userId = $db->insert('users', [
            'name' => 'Recurring Tester',
            'email' => 'rg-tester@test.local',
            'password_hash' => password_hash('unused-in-this-test', PASSWORD_DEFAULT),
            'role' => 'admin',
            'branch_id' => $this->branchId,
            'status' => 'active',
        ]);

        $this->bankId = $db->insert('accounts', [
            'branch_id' => $this->branchId,
            'name' => 'RG Bank',
            'type' => 'bank',
            'opening_balance' => '0.00',
            'opening_date' => '2026-01-01',
            'is_active' => 1,
        ]);
        $this->expenseCategoryId = $db->insert('categories', [
            'branch_id' => $this->branchId,
            'name' => 'RG Expense Cat',
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
        $db->run(
            'DELETE o FROM recurring_occurrences o
             JOIN recurring_rules r ON r.id = o.rule_id
             JOIN branches b ON b.id = r.branch_id
             WHERE b.name LIKE :n',
            ['n' => 'RG%']
        );
        $db->run(
            'DELETE r FROM recurring_rules r
             JOIN branches b ON b.id = r.branch_id
             WHERE b.name LIKE :n',
            ['n' => 'RG%']
        );
        $db->delete('categories', 'name LIKE :n', ['n' => 'RG%']);
        $db->delete('accounts', 'name LIKE :n', ['n' => 'RG%']);
        $db->delete('users', 'email LIKE :e', ['e' => 'rg-tester%@test.local']);
        $db->run(
            'DELETE a FROM audit_log a JOIN branches b ON b.id = a.branch_id WHERE b.name LIKE :n',
            ['n' => 'RG%']
        );
        $db->delete('branches', 'name LIKE :n', ['n' => 'RG%']);
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function createRule(
        int $branchId,
        int $accountId,
        int $categoryId,
        int $userId,
        array $overrides = []
    ): int {
        $defaults = [
            'branch_id' => $branchId,
            'type' => 'expense',
            'description' => 'RG recurring expense',
            'amount' => '100.00',
            'account_id' => $accountId,
            'category_id' => $categoryId,
            'vendor' => 'RG Vendor',
            'customer_id' => null,
            'reference_no' => null,
            'notes' => null,
            'frequency' => 'daily',
            'day_of_month' => null,
            'day_of_week' => null,
            'start_date' => '2026-09-25',
            'end_date' => null,
            'is_active' => 1,
            'last_generated_date' => null,
            'created_by' => $userId,
            // Explicit, rather than relying on DEFAULT CURRENT_TIMESTAMP, so
            // "no backfill before the rule's own creation" (effectiveFrom in
            // RecurringRuleService) never fights with a fixture's start_date
            // set in the past — tests below control both independently.
            'created_at' => '2026-01-01 00:00:00',
        ];

        return Database::instance()->insert('recurring_rules', array_merge($defaults, $overrides));
    }

    /** @return list<array<string,mixed>> */
    private function occurrencesForRule(int $ruleId): array
    {
        return Database::instance()->all(
            'SELECT * FROM recurring_occurrences WHERE rule_id = :rule_id ORDER BY occurrence_date ASC',
            ['rule_id' => $ruleId]
        );
    }

    public function testGenerationWritesOccurrenceRowsAndDoesNotTouchTransactions(): void
    {
        $transactionsBefore = (int) Database::instance()->value('SELECT COUNT(*) FROM transactions');

        $ruleId = $this->createRule($this->branchId, $this->bankId, $this->expenseCategoryId, $this->userId, [
            'frequency' => 'daily',
            'start_date' => '2026-09-25',
        ]);

        $generated = RecurringRuleService::generateForBranch($this->branchId, '2026-09-25');

        self::assertSame(1, $generated);
        $occurrences = $this->occurrencesForRule($ruleId);
        self::assertCount(1, $occurrences);
        self::assertSame('2026-09-25', substr((string) $occurrences[0]['occurrence_date'], 0, 10));
        self::assertSame('expense', $occurrences[0]['type']);
        self::assertSame('100.00', $occurrences[0]['amount']);
        self::assertSame('pending_review', $occurrences[0]['status']);

        $transactionsAfter = (int) Database::instance()->value('SELECT COUNT(*) FROM transactions');
        self::assertSame($transactionsBefore, $transactionsAfter, 'generation must never touch the ledger');
    }

    public function testRunningGenerationTwiceOnTheSameDayIsIdempotent(): void
    {
        $ruleId = $this->createRule($this->branchId, $this->bankId, $this->expenseCategoryId, $this->userId, [
            'frequency' => 'daily',
            'start_date' => '2026-09-25',
        ]);

        $firstRun = RecurringRuleService::generateForBranch($this->branchId, '2026-09-25');
        self::assertSame(1, $firstRun);

        // Ordinary re-run: the cursor already advanced past today, so a
        // second call the same day generates nothing new.
        $secondRun = RecurringRuleService::generateForBranch($this->branchId, '2026-09-25');
        self::assertSame(0, $secondRun);
        self::assertCount(1, $this->occurrencesForRule($ruleId));

        // Force the harder case: reset the cursor as if it had never
        // advanced, so the generator recomputes the exact same due date and
        // must hit `uq_occurrence_rule_date` on the insert. This must be
        // caught and logged, not thrown, and must not create a duplicate row.
        Database::instance()->update(
            'recurring_rules',
            ['last_generated_date' => null],
            'id = :id',
            ['id' => $ruleId]
        );
        $thirdRun = RecurringRuleService::generateForBranch($this->branchId, '2026-09-25');
        self::assertSame(0, $thirdRun, 'a caught duplicate-key insert must not count as newly generated');
        self::assertCount(
            1,
            $this->occurrencesForRule($ruleId),
            'the unique key backstop must prevent a duplicate row'
        );
    }

    public function testAPausedRuleGeneratesNothing(): void
    {
        $ruleId = $this->createRule($this->branchId, $this->bankId, $this->expenseCategoryId, $this->userId, [
            'frequency' => 'daily',
            'start_date' => '2026-09-25',
            'is_active' => 0,
        ]);

        $generated = RecurringRuleService::generateForBranch($this->branchId, '2026-09-25');

        self::assertSame(0, $generated);
        self::assertCount(0, $this->occurrencesForRule($ruleId));
    }

    public function testGeneratingForOneBranchLeavesAnotherBranchsRulesUntouched(): void
    {
        $branchBId = BranchFixture::create('RGB');
        $db = Database::instance();

        $bankB = $db->insert('accounts', [
            'branch_id' => $branchBId,
            'name' => 'RGB Bank',
            'type' => 'bank',
            'opening_balance' => '0.00',
            'opening_date' => '2026-01-01',
            'is_active' => 1,
        ]);
        $categoryB = $db->insert('categories', [
            'branch_id' => $branchBId,
            'name' => 'RGB Expense Cat',
            'type' => 'expense',
            'is_active' => 1,
        ]);
        $userB = $db->insert('users', [
            'name' => 'Recurring Tester B',
            'email' => 'rg-tester-b@test.local',
            'password_hash' => password_hash('unused-in-this-test', PASSWORD_DEFAULT),
            'role' => 'admin',
            'branch_id' => $branchBId,
            'status' => 'active',
        ]);

        $ruleAId = $this->createRule($this->branchId, $this->bankId, $this->expenseCategoryId, $this->userId, [
            'frequency' => 'daily',
            'start_date' => '2026-09-25',
        ]);
        $ruleBId = $this->createRule($branchBId, $bankB, $categoryB, $userB, [
            'frequency' => 'daily',
            'start_date' => '2026-09-25',
        ]);

        $generated = RecurringRuleService::generateForBranch($this->branchId, '2026-09-25');

        self::assertSame(1, $generated);
        self::assertCount(1, $this->occurrencesForRule($ruleAId));
        self::assertCount(0, $this->occurrencesForRule($ruleBId), 'branch B must not be touched by a branch A run');

        // Clean up branch B's own fixtures (outside the shared RG% cleanUp scope by user email).
        $db->run(
            'DELETE o FROM recurring_occurrences o WHERE o.rule_id = :rule_id',
            ['rule_id' => $ruleBId]
        );
        $db->delete('recurring_rules', 'id = :id', ['id' => $ruleBId]);
        $db->delete('users', 'email = :e', ['e' => 'rg-tester-b@test.local']);
    }

    public function testAnExpiredRuleStopsGeneratingButEarlierOccurrencesRemain(): void
    {
        // end_date is fixed well in the past (real calendar time, not
        // relative to any $asOf passed below), so this reproduces the state
        // a real branch reaches once its rule's window has genuinely closed:
        // RecurringRule::activeForBranch()'s own CURDATE() check already
        // excludes it, on top of RecurringSchedule's endDate handling.
        $ruleId = $this->createRule($this->branchId, $this->bankId, $this->expenseCategoryId, $this->userId, [
            'frequency' => 'daily',
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-05',
            'last_generated_date' => '2026-09-05',
        ]);

        // Seed the draft an earlier run (while the rule was still active)
        // would have produced. Inserted directly via the model's own
        // CLI-path write, not through the service, since reproducing it via
        // generateForBranch would require the rule to still be un-expired
        // at call time — the opposite of what this test needs to hold.
        $earlierOccurrenceId = (new RecurringOccurrence())->createForBranch($this->branchId, [
            'rule_id' => $ruleId,
            'occurrence_date' => '2026-09-05',
            'type' => 'expense',
            'amount' => '100.00',
            'account_id' => $this->bankId,
            'category_id' => $this->expenseCategoryId,
            'description' => 'RG recurring expense',
            'vendor' => 'RG Vendor',
            'customer_id' => null,
            'reference_no' => null,
            'notes' => null,
        ]);

        $generated = RecurringRuleService::generateForBranch($this->branchId, '2026-09-25');

        self::assertSame(0, $generated, 'an expired rule must not generate anything further');
        $occurrences = $this->occurrencesForRule($ruleId);
        self::assertCount(1, $occurrences, 'the occurrence generated before expiry must still be there');
        self::assertSame($earlierOccurrenceId, (int) $occurrences[0]['id']);
    }

    public function testAMultiMonthGapProducesThreeSeparateDatedOccurrences(): void
    {
        $ruleId = $this->createRule($this->branchId, $this->bankId, $this->expenseCategoryId, $this->userId, [
            'frequency' => 'monthly',
            'day_of_month' => 15,
            'start_date' => '2026-01-15',
            // Simulates the generator not having run for three months.
            'last_generated_date' => '2026-06-15',
        ]);

        $generated = RecurringRuleService::generateForBranch($this->branchId, '2026-09-25');

        self::assertSame(3, $generated);
        $occurrences = $this->occurrencesForRule($ruleId);
        self::assertCount(3, $occurrences);
        self::assertSame(
            ['2026-07-15', '2026-08-15', '2026-09-15'],
            array_map(static fn (array $row): string => substr((string) $row['occurrence_date'], 0, 10), $occurrences)
        );
    }
}
