<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\RecurringRuleController;
use App\Core\Database;
use PHPUnit\Framework\TestCase;
use Tests\Support\BranchFixture;
use Tests\Support\ControllerActionRunner;

/**
 * The rule CRUD screen (App\Controllers\RecurringRuleController,
 * App\Views\pages\recurring-rules.php). Generation and the approval queue
 * are separate features, covered by RecurringRuleGenerationTest instead —
 * this covers only the branching logic in the CRUD path itself:
 *
 *   - the schedule shape (day_of_month XOR day_of_week, depending on
 *     frequency) that chk_recurring_rule_schedule enforces in the database
 *     and this controller enforces again, by hand, for a friendly error;
 *   - end_date >= start_date;
 *   - the resume-clears-the-backfill-window behavior
 *     (RecurringRuleService::toggleActive() / RecurringRule::resume()) —
 *     the highest-value test here, since a bug in it would silently
 *     backfill drafts for the paused window the next time the generator
 *     runs;
 *   - tenant isolation on update()/toggle(), mirroring
 *     BranchIsolationTest's approach for every other controller.
 */
final class RecurringRuleControllerTest extends TestCase
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

        $this->branchId = BranchFixture::create('RRC');

        $this->userId = $db->insert('users', [
            'name' => 'Recurring Rule Tester',
            'email' => 'rrc-tester@test.local',
            'password_hash' => password_hash('unused-in-this-test', PASSWORD_DEFAULT),
            'role' => 'admin',
            'branch_id' => $this->branchId,
            'status' => 'active',
        ]);

        $this->bankId = $db->insert('accounts', [
            'branch_id' => $this->branchId,
            'name' => 'RRC Bank',
            'type' => 'bank',
            'opening_balance' => '0.00',
            'opening_date' => '2026-01-01',
            'is_active' => 1,
        ]);
        $this->expenseCategoryId = $db->insert('categories', [
            'branch_id' => $this->branchId,
            'name' => 'RRC Expense Cat',
            'type' => 'expense',
            'is_active' => 1,
        ]);
        // An income category exists too, even though most tests below use
        // expense — store()/update() must still be exercisable with a
        // second type present without it bleeding into the expense rows.
        $db->insert('categories', [
            'branch_id' => $this->branchId,
            'name' => 'RRC Income Cat',
            'type' => 'income',
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
            'DELETE r FROM recurring_rules r JOIN branches b ON b.id = r.branch_id WHERE b.name LIKE :n',
            ['n' => 'RRC%']
        );
        $db->delete('categories', 'name LIKE :n', ['n' => 'RRC%']);
        $db->delete('accounts', 'name LIKE :n', ['n' => 'RRC%']);
        $db->delete('users', 'email LIKE :e', ['e' => 'rrc-%@test.local']);
        $db->run(
            'DELETE a FROM audit_log a JOIN branches b ON b.id = a.branch_id WHERE b.name LIKE :n',
            ['n' => 'RRC%']
        );
        $db->delete('branches', 'name LIKE :n', ['n' => 'RRC%']);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function validMonthlyExpensePost(array $overrides = []): array
    {
        $defaults = [
            'type' => 'expense',
            'description' => 'RRC recurring expense',
            'amount' => '250.00',
            'account_id' => (string) $this->bankId,
            'category_id_expense' => (string) $this->expenseCategoryId,
            'category_id_income' => '',
            'frequency' => 'monthly',
            'day_of_month' => '15',
            'day_of_week' => '',
            'start_date' => '2026-01-01',
            'end_date' => '',
            'vendor' => '',
            'customer_id' => '',
            'reference_no' => '',
            'notes' => '',
        ];

        return array_merge($defaults, $overrides);
    }

    /**
     * @param array<string,mixed> $post
     * @return array{status:int,body:string}
     */
    private function store(array $post): array
    {
        return ControllerActionRunner::run(
            RecurringRuleController::class,
            'store',
            ['_auth_user_id' => $this->userId, '_active_branch_id' => $this->branchId],
            $post
        );
    }

    /** @return array<string,mixed>|null */
    private function ruleByDescription(string $description): ?array
    {
        return Database::instance()->first(
            'SELECT * FROM recurring_rules WHERE description = :d',
            ['d' => $description]
        );
    }

    public function testAValidMonthlyRuleIsCreatedWithDayOfMonthPersistedAndDayOfWeekNull(): void
    {
        $result = $this->store($this->validMonthlyExpensePost(['description' => 'RRC monthly rule']));

        self::assertSame(302, $result['status']);

        $rule = $this->ruleByDescription('RRC monthly rule');
        self::assertNotNull($rule, 'a valid monthly rule must be created');
        self::assertSame('monthly', $rule['frequency']);
        self::assertSame(15, (int) $rule['day_of_month']);
        self::assertNull($rule['day_of_week']);
    }

    public function testAValidWeeklyRuleIsCreatedWithDayOfWeekPersistedAndDayOfMonthNull(): void
    {
        $post = $this->validMonthlyExpensePost([
            'description' => 'RRC weekly rule',
            'frequency' => 'weekly',
            'day_of_month' => '',
            'day_of_week' => '3',
        ]);

        $result = $this->store($post);

        self::assertSame(302, $result['status']);

        $rule = $this->ruleByDescription('RRC weekly rule');
        self::assertNotNull($rule, 'a valid weekly rule must be created');
        self::assertSame('weekly', $rule['frequency']);
        self::assertSame(3, (int) $rule['day_of_week']);
        self::assertNull($rule['day_of_month']);
    }

    public function testAMonthlyRuleWithoutDayOfMonthFailsValidationAndCreatesNothing(): void
    {
        $post = $this->validMonthlyExpensePost([
            'description' => 'RRC monthly missing dom',
            'day_of_month' => '',
        ]);

        $result = $this->store($post);

        self::assertSame(302, $result['status'], 'a validation failure still redirects, back to the form');
        self::assertNull(
            $this->ruleByDescription('RRC monthly missing dom'),
            'a monthly rule with no day_of_month must not be created'
        );
    }

    public function testAWeeklyRuleWithoutDayOfWeekFailsValidationAndCreatesNothing(): void
    {
        $post = $this->validMonthlyExpensePost([
            'description' => 'RRC weekly missing dow',
            'frequency' => 'weekly',
            'day_of_month' => '',
            'day_of_week' => '',
        ]);

        $result = $this->store($post);

        self::assertSame(302, $result['status']);
        self::assertNull(
            $this->ruleByDescription('RRC weekly missing dow'),
            'a weekly rule with no day_of_week must not be created'
        );
    }

    public function testEndDateEarlierThanStartDateFailsValidation(): void
    {
        $post = $this->validMonthlyExpensePost([
            'description' => 'RRC bad end date',
            'start_date' => '2026-05-01',
            'end_date' => '2026-04-01',
        ]);

        $result = $this->store($post);

        self::assertSame(302, $result['status']);
        self::assertNull(
            $this->ruleByDescription('RRC bad end date'),
            'end_date before start_date must not be allowed to persist'
        );
    }

    public function testToggleTwicePausesThenResumesSettingCursorToToday(): void
    {
        $db = Database::instance();
        $ruleId = $db->insert('recurring_rules', [
            'branch_id' => $this->branchId,
            'type' => 'expense',
            'description' => 'RRC toggle rule',
            'amount' => '100.00',
            'account_id' => $this->bankId,
            'category_id' => $this->expenseCategoryId,
            'frequency' => 'daily',
            'start_date' => '2026-01-01',
            'is_active' => 1,
            'last_generated_date' => '2026-01-05',
            'created_by' => $this->userId,
        ]);

        $pauseResult = $this->toggle($ruleId);
        self::assertSame(302, $pauseResult['status']);

        $paused = $db->first('SELECT * FROM recurring_rules WHERE id = :id', ['id' => $ruleId]);
        self::assertNotNull($paused);
        self::assertSame(0, (int) $paused['is_active'], 'toggling an active rule must pause it');
        self::assertSame(
            '2026-01-05',
            substr((string) $paused['last_generated_date'], 0, 10),
            'pausing must not touch the generation cursor'
        );

        $resumeResult = $this->toggle($ruleId);
        self::assertSame(302, $resumeResult['status']);

        $resumed = $db->first('SELECT * FROM recurring_rules WHERE id = :id', ['id' => $ruleId]);
        self::assertNotNull($resumed);
        self::assertSame(1, (int) $resumed['is_active'], 'toggling a paused rule must resume it');
        self::assertSame(
            date('Y-m-d'),
            substr((string) $resumed['last_generated_date'], 0, 10),
            'resuming must advance the cursor to today, so the paused window is never backfilled'
        );
    }

    public function testARuleBelongingToAnotherBranchCannotBeUpdatedOrToggled(): void
    {
        $db = Database::instance();

        $otherBranchId = BranchFixture::create('RRCB');
        $otherUserId = $db->insert('users', [
            'name' => 'RRCB Other Branch Tester',
            'email' => 'rrc-other-tester@test.local',
            'password_hash' => password_hash('unused-in-this-test', PASSWORD_DEFAULT),
            'role' => 'admin',
            'branch_id' => $otherBranchId,
            'status' => 'active',
        ]);
        $otherBankId = $db->insert('accounts', [
            'branch_id' => $otherBranchId,
            'name' => 'RRCB Bank',
            'type' => 'bank',
            'opening_balance' => '0.00',
            'opening_date' => '2026-01-01',
            'is_active' => 1,
        ]);
        $otherCategoryId = $db->insert('categories', [
            'branch_id' => $otherBranchId,
            'name' => 'RRCB Expense Cat',
            'type' => 'expense',
            'is_active' => 1,
        ]);
        $otherRuleId = $db->insert('recurring_rules', [
            'branch_id' => $otherBranchId,
            'type' => 'expense',
            'description' => 'RRCB other-branch rule',
            'amount' => '50.00',
            'account_id' => $otherBankId,
            'category_id' => $otherCategoryId,
            'frequency' => 'daily',
            'start_date' => '2026-01-01',
            'is_active' => 1,
            'created_by' => $otherUserId,
        ]);

        try {
            // Both attempts run as THIS test's Branch A session, targeting
            // Branch B's rule id — App\Core\Model::find() (via
            // RecurringRuleController::update()/toggle()) must not resolve
            // it, the same guarantee BranchIsolationTest establishes for
            // every other tenant-scoped table.
            $updateResult = ControllerActionRunner::run(
                RecurringRuleController::class,
                'update',
                ['_auth_user_id' => $this->userId, '_active_branch_id' => $this->branchId],
                $this->validMonthlyExpensePost(['description' => 'attempted cross-branch update']),
                [],
                'POST',
                ['id' => (string) $otherRuleId]
            );
            self::assertSame(404, $updateResult['status'], 'updating another branch\'s rule id must 404');

            $toggleResult = $this->toggle($otherRuleId);
            self::assertSame(404, $toggleResult['status'], 'toggling another branch\'s rule id must 404');

            $stillIntact = $db->first('SELECT * FROM recurring_rules WHERE id = :id', ['id' => $otherRuleId]);
            self::assertNotNull($stillIntact);
            self::assertSame('RRCB other-branch rule', $stillIntact['description'], 'the row must be untouched');
            self::assertSame(1, (int) $stillIntact['is_active'], 'the toggle must not have applied to it');
        } finally {
            $db->delete('recurring_rules', 'id = :id', ['id' => $otherRuleId]);
            $db->delete('categories', 'id = :id', ['id' => $otherCategoryId]);
            $db->delete('accounts', 'id = :id', ['id' => $otherBankId]);
            $db->delete('users', 'id = :id', ['id' => $otherUserId]);
            $db->delete('branches', 'id = :id', ['id' => $otherBranchId]);
        }
    }

    /** @return array{status:int,body:string} */
    private function toggle(int $ruleId): array
    {
        return ControllerActionRunner::run(
            RecurringRuleController::class,
            'toggle',
            ['_auth_user_id' => $this->userId, '_active_branch_id' => $this->branchId],
            [],
            [],
            'POST',
            ['id' => (string) $ruleId]
        );
    }
}
