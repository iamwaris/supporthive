<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Domain\TransactionType;
use App\Services\TransactionService;
use PHPUnit\Framework\TestCase;
use Tests\Support\BranchFixture;

/**
 * Migration 000019 renames the expense category "Marketing" to "Hiring Ads"
 * in place. The file itself is executed here exactly the way
 * database/migrate.php executes it (one PDO::exec of the whole file), so the
 * test exercises the SQL that ships rather than a copy of it.
 *
 * The guarantees: the category keeps its id, so its expenses and budgets
 * follow it; a branch that already has "Hiring Ads", or has two "Marketing"
 * rows, is left untouched; income categories are never touched; every rename
 * writes one audit row; and a second run changes nothing.
 */
final class RenameMarketingToHiringAdsMigrationTest extends TestCase
{
    private const MIGRATION = '2026_10_23_000019_rename_marketing_to_hiring_ads.sql';
    private const USER_EMAIL = 'mha-tester@test.local';

    private int $branchId;
    private int $collidingBranchId;
    private int $duplicateBranchId;

    private int $marketingId;
    private int $incomeMarketingId;
    private int $collidingMarketingId;
    private int $existingHiringAdsId;
    private int $duplicateMarketingA;
    private int $duplicateMarketingB;

    private int $expenseId;
    private int $budgetId;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->cleanUp();

        $db = Database::instance();

        $this->branchId = BranchFixture::create('MHA-RENAME');
        $this->collidingBranchId = BranchFixture::create('MHA-COLLIDE');
        $this->duplicateBranchId = BranchFixture::create('MHA-DUP');

        $userId = $db->insert('users', [
            'name' => 'MHA Tester',
            'email' => self::USER_EMAIL,
            'password_hash' => password_hash('unused-in-this-test', PASSWORD_DEFAULT),
            'role' => 'admin',
            'branch_id' => $this->branchId,
            'status' => 'active',
        ]);
        $_SESSION['_auth_user_id'] = $userId;
        $_SESSION['_active_branch_id'] = $this->branchId;

        // Odd case and padding on purpose: the match is trimmed and
        // case-insensitive, as real hand-typed data may be.
        $this->marketingId = $this->insertCategory($this->branchId, ' marketing ', 'expense');
        $this->incomeMarketingId = $this->insertCategory($this->branchId, 'Marketing', 'income');

        $this->collidingMarketingId = $this->insertCategory($this->collidingBranchId, 'Marketing', 'expense');
        $this->existingHiringAdsId = $this->insertCategory($this->collidingBranchId, 'Hiring Ads', 'expense');

        $this->duplicateMarketingA = $this->insertCategory($this->duplicateBranchId, 'Marketing', 'expense');
        $this->duplicateMarketingB = $this->insertCategory($this->duplicateBranchId, 'MARKETING', 'expense');

        $bankId = $db->insert('accounts', [
            'branch_id' => $this->branchId,
            'name' => 'MHA Bank',
            'type' => 'bank',
            'opening_balance' => '0.00',
            'opening_date' => '2026-01-01',
            'is_active' => 1,
        ]);
        $this->expenseId = TransactionService::post([
            'type' => TransactionType::Expense,
            'amount' => '1250.00',
            'account_id' => $bankId,
            'category_id' => $this->marketingId,
            'transaction_date' => '2026-10-01',
            'description' => 'MHA job board listing',
        ]);
        $this->budgetId = $db->insert('budgets', [
            'branch_id' => $this->branchId,
            'year' => 2026,
            'month' => 10,
            'category_id' => $this->marketingId,
            'amount' => '5000.00',
            'alert_threshold_pct' => 80,
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
        foreach (['audit_log', 'budgets', 'transactions', 'accounts'] as $table) {
            $db->run(
                'DELETE x FROM ' . Database::identifier($table) . ' x
                 JOIN branches b ON b.id = x.branch_id WHERE b.name LIKE :n',
                ['n' => 'MHA-%']
            );
        }
        $db->run(
            'DELETE c FROM categories c JOIN branches b ON b.id = c.branch_id WHERE b.name LIKE :n',
            ['n' => 'MHA-%']
        );
        $db->run(
            'DELETE a FROM audit_log a JOIN users u ON u.id = a.user_id WHERE u.email = :e',
            ['e' => self::USER_EMAIL]
        );
        $db->delete('users', 'email = :e', ['e' => self::USER_EMAIL]);
        $db->delete('branches', 'name LIKE :n', ['n' => 'MHA-%']);
    }

    private function insertCategory(int $branchId, string $name, string $type): int
    {
        return Database::instance()->insert('categories', [
            'branch_id' => $branchId,
            'name' => $name,
            'type' => $type,
            'is_active' => 1,
        ]);
    }

    private function runMigration(): void
    {
        $path = dirname(__DIR__, 2) . '/database/migrations/' . self::MIGRATION;
        Database::instance()->pdo()->exec((string) file_get_contents($path));
    }

    private function categoryName(int $id): string
    {
        return (string) Database::instance()->value('SELECT name FROM categories WHERE id = :id', ['id' => $id]);
    }

    /** @return list<array<string,mixed>> */
    private function renameAuditRows(): array
    {
        return Database::instance()->all(
            'SELECT a.* FROM audit_log a JOIN branches b ON b.id = a.branch_id
             WHERE b.name LIKE :n AND a.action = :action ORDER BY a.id',
            ['n' => 'MHA-%', 'action' => 'category.renamed']
        );
    }

    public function testRenamesMarketingInPlaceSoExpensesAndBudgetsFollowIt(): void
    {
        $this->runMigration();

        self::assertSame('Hiring Ads', $this->categoryName($this->marketingId));
        self::assertSame(
            $this->marketingId,
            (int) Database::instance()->value(
                'SELECT category_id FROM transactions WHERE id = :id',
                ['id' => $this->expenseId]
            )
        );
        self::assertSame(
            $this->marketingId,
            (int) Database::instance()->value(
                'SELECT category_id FROM budgets WHERE id = :id',
                ['id' => $this->budgetId]
            )
        );
        self::assertSame(
            0,
            (int) Database::instance()->value(
                'SELECT COUNT(*) FROM categories WHERE branch_id = :b AND type = :t AND name = :n',
                ['b' => $this->branchId, 't' => 'expense', 'n' => 'Marketing']
            ),
            'no new Marketing category is created'
        );
    }

    public function testIncomeCategoryNamedMarketingIsNotTouched(): void
    {
        $this->runMigration();

        self::assertSame('Marketing', $this->categoryName($this->incomeMarketingId));
    }

    public function testBranchThatAlreadyHasHiringAdsIsLeftUntouched(): void
    {
        $this->runMigration();

        self::assertSame('Marketing', $this->categoryName($this->collidingMarketingId));
        self::assertSame('Hiring Ads', $this->categoryName($this->existingHiringAdsId));
    }

    public function testBranchWithTwoMarketingRowsIsLeftUntouched(): void
    {
        $this->runMigration();

        self::assertSame('Marketing', $this->categoryName($this->duplicateMarketingA));
        self::assertSame('MARKETING', $this->categoryName($this->duplicateMarketingB));
    }

    public function testEachRenameWritesOneSystemAuditRow(): void
    {
        $this->runMigration();

        $rows = $this->renameAuditRows();

        self::assertCount(1, $rows, 'only the renamed branch is audited');
        self::assertSame($this->branchId, (int) $rows[0]['branch_id']);
        self::assertSame('categories', $rows[0]['entity_type']);
        self::assertSame($this->marketingId, (int) $rows[0]['entity_id']);
        self::assertNull($rows[0]['user_id']);

        $meta = json_decode((string) $rows[0]['meta'], true);
        self::assertSame(
            ['before' => ['name' => ' marketing '], 'after' => ['name' => 'Hiring Ads']],
            ['before' => $meta['before'], 'after' => $meta['after']]
        );
    }

    public function testSecondRunChangesNothing(): void
    {
        $this->runMigration();

        $snapshot = static fn (): array => Database::instance()->all(
            'SELECT c.id, c.name, c.updated_at FROM categories c
             JOIN branches b ON b.id = c.branch_id WHERE b.name LIKE :n ORDER BY c.id',
            ['n' => 'MHA-%']
        );
        $before = $snapshot();

        $this->runMigration();

        self::assertSame($before, $snapshot());
        self::assertCount(1, $this->renameAuditRows(), 'a second run audits nothing');
    }
}
