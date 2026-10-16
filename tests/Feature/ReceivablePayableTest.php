<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\BranchController;
use App\Controllers\ReceivablePayableController;
use App\Core\Database;
use App\Domain\ReceivablePayableKind;
use App\Models\ReceivablePayable;
use App\Services\ReceivablePayableService;
use PDOException;
use PHPUnit\Framework\TestCase;
use Tests\Support\BranchFixture;
use Tests\Support\ControllerActionRunner;

/**
 * The receivables / payables register (AR/AP-1).
 *
 * The guarantees: an entry leaves 'open' exactly once (settled in full, or
 * cancelled with a reason) and is editable only while open; every change is
 * audited; every id is confined to this branch and this kind; and nothing
 * the register does ever writes to the ledger.
 */
final class ReceivablePayableTest extends TestCase
{
    private const SUPER_ADMIN_PASSWORD = 'rpt-super-admin-password';

    private int $branchAId;
    private int $branchBId;
    private int $adminAId;
    private int $adminBId;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->cleanUp();

        $this->branchAId = BranchFixture::create('RPT-A');
        $this->branchBId = BranchFixture::create('RPT-B');
        $this->adminAId = $this->insertUser('rpt-admin-a@test.local', 'admin', $this->branchAId);
        $this->adminBId = $this->insertUser('rpt-admin-b@test.local', 'admin', $this->branchBId);
        $this->asBranchA();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        $_SESSION = [];
    }

    private function cleanUp(): void
    {
        $db = Database::instance();
        // Register rows reference users without cascading on created_by, so
        // they go first.
        $db->run(
            'DELETE r FROM receivables_payables r JOIN branches b ON b.id = r.branch_id WHERE b.name LIKE :n',
            ['n' => 'RPT-%']
        );
        $db->run(
            'DELETE a FROM audit_log a JOIN branches b ON b.id = a.branch_id WHERE b.name LIKE :n',
            ['n' => 'RPT-%']
        );
        $db->run(
            'DELETE a FROM audit_log a JOIN users u ON u.id = a.user_id WHERE u.email LIKE :e',
            ['e' => 'rpt-%@test.local']
        );
        $db->delete('users', 'email LIKE :e', ['e' => 'rpt-%@test.local']);
        $db->delete('branches', 'name LIKE :n', ['n' => 'RPT-%']);
    }

    private function insertUser(string $email, string $role, ?int $branchId, string $password = 'unused'): int
    {
        return Database::instance()->insert('users', [
            'name' => 'RPT ' . $email,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
            'branch_id' => $branchId,
            'status' => 'active',
        ]);
    }

    /** @return array<string,int> */
    private function sessionA(): array
    {
        return ['_auth_user_id' => $this->adminAId, '_active_branch_id' => $this->branchAId];
    }

    private function asBranchA(): void
    {
        $_SESSION = $this->sessionA();
    }

    private function asBranchB(): void
    {
        $_SESSION = ['_auth_user_id' => $this->adminBId, '_active_branch_id' => $this->branchBId];
    }

    private static function day(int $offset): string
    {
        return date('Y-m-d', (int) strtotime(sprintf('%+d days', $offset)));
    }

    /**
     * @param array<string,string> $overrides
     * @return array{party_name:string,reference_no:?string,description:?string,
     *               amount:string,issue_date:string,due_date:?string}
     */
    private static function fields(array $overrides = []): array
    {
        /** @var array{party_name:string,reference_no:?string,description:?string,
         *             amount:string,issue_date:string,due_date:?string} $fields */
        $fields = $overrides + [
            'party_name' => 'RPT Acme Traders',
            'reference_no' => 'INV-1001',
            'description' => 'October retainer',
            'amount' => '1500',
            'issue_date' => self::day(-20),
            'due_date' => self::day(10),
        ];

        return $fields;
    }

    /** Create an entry in the current session's branch through the service. */
    private function seed(ReceivablePayableKind $kind, array $overrides = []): int
    {
        return ReceivablePayableService::record($kind, self::fields($overrides));
    }

    /**
     * @param array<string,string> $post
     * @param array<string,string> $route
     * @param array<string,int>|null $session
     * @return array{status:int,body:string,session:array<string,mixed>}
     */
    private function act(
        string $action,
        ReceivablePayableKind $kind,
        array $post = [],
        array $route = [],
        string $method = 'POST',
        ?array $session = null
    ): array {
        return ControllerActionRunner::run(
            ReceivablePayableController::class,
            $action,
            $session ?? $this->sessionA(),
            $post,
            [],
            $method,
            ['kind' => $kind->value] + $route
        );
    }

    /**
     * A GET screen rendered in this process: it returns normally rather than
     * exiting, so there is no status code for the subprocess runner to read.
     *
     * @param array<string,string> $route
     * @param array<string,mixed>  $query
     */
    private function render(
        string $action,
        ReceivablePayableKind $kind,
        array $route = [],
        array $query = []
    ): string {
        $_GET = $query;
        ob_start();
        try {
            (new ReceivablePayableController())->{$action}(['kind' => $kind->value] + $route);
        } finally {
            $body = (string) ob_get_clean();
            $_GET = [];
        }

        return $body;
    }

    /** @return array<string,mixed> */
    private function row(int $id): array
    {
        $row = Database::instance()->first('SELECT * FROM receivables_payables WHERE id = :id', ['id' => $id]);
        self::assertNotNull($row);

        return $row;
    }

    private function auditCount(int $id, string $action): int
    {
        return (int) Database::instance()->value(
            "SELECT COUNT(*) FROM audit_log
             WHERE entity_type = 'receivables_payables' AND entity_id = :id AND action = :action",
            ['id' => $id, 'action' => $action]
        );
    }

    private function entryCount(): int
    {
        return (int) Database::instance()->value(
            'SELECT COUNT(*) FROM receivables_payables WHERE branch_id IN (:a, :b)',
            ['a' => $this->branchAId, 'b' => $this->branchBId]
        );
    }

    private static function ledgerCount(): int
    {
        return (int) Database::instance()->value('SELECT COUNT(*) FROM transactions');
    }

    // --- create --------------------------------------------------------------

    public function testStoreRecordsAnOpenEntryWithItsAuditRow(): void
    {
        $result = $this->act('store', ReceivablePayableKind::Payable, [
            'party_name' => '  RPT Paper Mill  ',
            'reference_no' => 'BILL-77',
            'description' => '',
            'amount' => '2500.5',
            'issue_date' => self::day(-3),
            'due_date' => self::day(27),
        ]);

        self::assertSame(302, $result['status']);
        $row = Database::instance()->first(
            'SELECT * FROM receivables_payables WHERE branch_id = :b',
            ['b' => $this->branchAId]
        );
        self::assertNotNull($row);
        self::assertSame('payable', $row['kind']);
        self::assertSame('open', $row['status']);
        self::assertSame('RPT Paper Mill', $row['party_name'], 'party name is trimmed');
        self::assertNull($row['description'], 'a blank optional field is stored as NULL');
        self::assertSame('2500.50', $row['amount']);
        self::assertSame($this->adminAId, (int) $row['created_by']);
        self::assertSame(1, $this->auditCount((int) $row['id'], 'payable.created'));
    }

    public function testStoreRejectsMalformedInputPerFieldAndWritesNothing(): void
    {
        $result = $this->act('store', ReceivablePayableKind::Receivable, [
            'party_name' => '',
            'amount' => '12.345',
            'issue_date' => '2026-02-30',
            'due_date' => 'next week',
        ]);

        self::assertSame(302, $result['status']);
        $errors = $result['session']['_flash']['errors'] ?? [];
        self::assertIsArray($errors);
        self::assertArrayHasKey('party_name', $errors);
        self::assertArrayHasKey('amount', $errors);
        self::assertArrayHasKey('issue_date', $errors);
        self::assertArrayHasKey('due_date', $errors);
        self::assertSame('12.345', $result['session']['_old']['amount'] ?? null, 'the submitted value is kept');
        self::assertSame(0, $this->entryCount());
    }

    public function testStoreRejectsAZeroAmountAndADueDateBeforeTheIssueDate(): void
    {
        $result = $this->act('store', ReceivablePayableKind::Receivable, [
            'party_name' => 'RPT Zero Co',
            'amount' => '0.00',
            'issue_date' => self::day(-1),
            'due_date' => self::day(-2),
        ]);

        self::assertSame(302, $result['status']);
        $errors = $result['session']['_flash']['errors'] ?? [];
        self::assertIsArray($errors);
        self::assertSame(['amount', 'due_date'], array_keys($errors));
        self::assertSame(0, $this->entryCount());
    }

    // --- edit ----------------------------------------------------------------

    public function testAnOpenEntryCanBeEditedAndOnlyTheChangeIsAudited(): void
    {
        $id = $this->seed(ReceivablePayableKind::Receivable);

        $result = $this->act('update', ReceivablePayableKind::Receivable, self::fields([
            'amount' => '1750.00',
            'due_date' => self::day(30),
        ]), ['id' => (string) $id]);

        self::assertSame(302, $result['status']);
        $row = $this->row($id);
        self::assertSame('1750.00', $row['amount']);
        self::assertSame(self::day(30), $row['due_date']);
        self::assertSame($this->adminAId, (int) $row['modified_by']);

        $meta = Database::instance()->value(
            "SELECT meta FROM audit_log WHERE entity_id = :id AND action = 'receivable.updated'",
            ['id' => $id]
        );
        $decoded = json_decode((string) $meta, true);
        self::assertIsArray($decoded);
        self::assertSame(['amount', 'due_date'], array_keys($decoded['after']), 'unchanged fields are not in the diff');
        self::assertSame('1500.00', $decoded['before']['amount']);
    }

    public function testASettledOrCancelledEntryCannotBeEdited(): void
    {
        $settled = $this->seed(ReceivablePayableKind::Receivable);
        ReceivablePayableService::settle($settled, ReceivablePayableKind::Receivable, self::day(0), null);
        $cancelled = $this->seed(ReceivablePayableKind::Receivable);
        ReceivablePayableService::cancel($cancelled, ReceivablePayableKind::Receivable, 'Raised in error');

        foreach ([$settled, $cancelled] as $id) {
            $before = $this->row($id);

            $form = $this->act('edit', ReceivablePayableKind::Receivable, [], ['id' => (string) $id], 'GET');
            self::assertSame(302, $form['status'], 'the edit form redirects back to the list');

            $result = $this->act(
                'update',
                ReceivablePayableKind::Receivable,
                self::fields(['amount' => '99']),
                ['id' => (string) $id]
            );
            self::assertSame(302, $result['status']);
            $error = (string) ($result['session']['_flash']['error'] ?? '');
            self::assertStringContainsString('Only an open entry', $error);
            self::assertSame($before, $this->row($id), 'nothing changed');
            self::assertSame(0, $this->auditCount($id, 'receivable.updated'));
        }
    }

    // --- settle --------------------------------------------------------------

    public function testSettlingMovesAnOpenEntryToSettledOnce(): void
    {
        $id = $this->seed(ReceivablePayableKind::Payable);

        $result = $this->act('settle', ReceivablePayableKind::Payable, [
            'settled_on' => self::day(-1),
            'settlement_note' => 'Bank transfer 4471',
        ], ['id' => (string) $id]);

        self::assertSame(302, $result['status']);
        $row = $this->row($id);
        self::assertSame('settled', $row['status']);
        self::assertSame(self::day(-1), $row['settled_on']);
        self::assertSame('Bank transfer 4471', $row['settlement_note']);
        self::assertSame($this->adminAId, (int) $row['settled_by']);
        self::assertNotNull($row['settled_at']);
        self::assertSame(1, $this->auditCount($id, 'payable.settled'));

        $again = $this->act('settle', ReceivablePayableKind::Payable, [
            'settled_on' => self::day(0),
        ], ['id' => (string) $id]);
        self::assertSame(302, $again['status']);
        self::assertStringContainsString('already settled', (string) ($again['session']['_flash']['error'] ?? ''));
        self::assertSame(self::day(-1), $this->row($id)['settled_on'], 'the first settlement stands');
        self::assertSame(1, $this->auditCount($id, 'payable.settled'));
    }

    public function testSettlementDateCannotBeInTheFutureOrBeforeTheIssueDate(): void
    {
        $id = $this->seed(ReceivablePayableKind::Receivable, ['issue_date' => self::day(-5)]);

        foreach ([self::day(1), self::day(-6), 'not-a-date', ''] as $date) {
            $result = $this->act('settle', ReceivablePayableKind::Receivable, [
                'settled_on' => $date,
            ], ['id' => (string) $id]);

            self::assertSame(302, $result['status']);
            $failure = $result['session']['_flash']['rowFailure'] ?? null;
            self::assertIsArray($failure, 'the failing row is reopened with its error');
            self::assertSame($id, $failure['id']);
            self::assertSame('settle', $failure['action']);
            self::assertArrayHasKey('settled_on', $failure['errors']);
            self::assertSame('open', $this->row($id)['status'], $date . ' must not settle');
        }

        self::assertSame(0, $this->auditCount($id, 'receivable.settled'));
    }

    public function testARefusedSettlementReopensThatRowWithTheErrorNextToTheField(): void
    {
        $id = $this->seed(ReceivablePayableKind::Receivable);
        $result = $this->act('settle', ReceivablePayableKind::Receivable, [
            'settled_on' => self::day(2),
            'settlement_note' => 'Cheque 12',
        ], ['id' => (string) $id]);

        $_SESSION['_flash'] = $result['session']['_flash'] ?? [];
        $body = $this->render('index', ReceivablePayableKind::Receivable);

        self::assertMatchesRegularExpression('/<details[^>]*\bopen>/', $body, 'the failed row is reopened');
        self::assertStringContainsString('id="settled-on-error-' . $id . '"', $body);
        self::assertStringContainsString('The settlement date cannot be in the future.', $body);
        self::assertStringContainsString('value="' . self::day(2) . '"', $body, 'the submitted date is kept');
        self::assertStringContainsString('value="Cheque 12"', $body);
    }

    public function testACancelledEntryCannotBeSettled(): void
    {
        $id = $this->seed(ReceivablePayableKind::Receivable);
        ReceivablePayableService::cancel($id, ReceivablePayableKind::Receivable, 'Duplicate');

        $result = $this->act('settle', ReceivablePayableKind::Receivable, [
            'settled_on' => self::day(0),
        ], ['id' => (string) $id]);

        self::assertSame(302, $result['status']);
        $row = $this->row($id);
        self::assertSame('cancelled', $row['status']);
        self::assertNull($row['settled_on']);
    }

    // --- cancel --------------------------------------------------------------

    public function testCancellingRequiresAReasonAndKeepsTheRow(): void
    {
        $id = $this->seed(ReceivablePayableKind::Payable);

        $refused = $this->act('cancel', ReceivablePayableKind::Payable, [
            'cancel_reason' => '   ',
        ], ['id' => (string) $id]);
        self::assertSame(302, $refused['status']);
        self::assertArrayHasKey('cancel_reason', $refused['session']['_flash']['rowFailure']['errors'] ?? []);
        self::assertSame('open', $this->row($id)['status']);

        $result = $this->act('cancel', ReceivablePayableKind::Payable, [
            'cancel_reason' => 'Vendor withdrew the bill',
        ], ['id' => (string) $id]);

        self::assertSame(302, $result['status']);
        $row = $this->row($id);
        self::assertSame('cancelled', $row['status']);
        self::assertSame('Vendor withdrew the bill', $row['cancel_reason']);
        self::assertSame($this->adminAId, (int) $row['cancelled_by']);
        self::assertSame(1, $this->auditCount($id, 'payable.cancelled'));
    }

    public function testASettledOrCancelledEntryCannotBeCancelled(): void
    {
        $settled = $this->seed(ReceivablePayableKind::Payable);
        ReceivablePayableService::settle($settled, ReceivablePayableKind::Payable, self::day(0), null);
        $cancelled = $this->seed(ReceivablePayableKind::Payable);
        ReceivablePayableService::cancel($cancelled, ReceivablePayableKind::Payable, 'First reason');

        foreach ([$settled, $cancelled] as $id) {
            $before = $this->row($id);
            $result = $this->act('cancel', ReceivablePayableKind::Payable, [
                'cancel_reason' => 'Second thoughts',
            ], ['id' => (string) $id]);

            self::assertSame(302, $result['status']);
            self::assertSame($before, $this->row($id));
        }
        self::assertSame(1, $this->auditCount($cancelled, 'payable.cancelled'));
    }

    public function testTheDatabaseRefusesACancellationWithoutAReason(): void
    {
        $id = $this->seed(ReceivablePayableKind::Payable);

        $this->expectException(PDOException::class);
        Database::instance()->update(
            'receivables_payables',
            ['status' => 'cancelled', 'cancel_reason' => null],
            'id = :id',
            ['id' => $id]
        );
    }

    // --- isolation -----------------------------------------------------------

    public function testAnotherBranchsEntryIsNotFoundAndNeverChanged(): void
    {
        $this->asBranchB();
        $foreign = $this->seed(ReceivablePayableKind::Receivable, ['party_name' => 'RPT Branch B Customer']);
        $before = $this->row($foreign);
        $this->asBranchA();

        $route = ['id' => (string) $foreign];
        self::assertSame(404, $this->act('edit', ReceivablePayableKind::Receivable, [], $route, 'GET')['status']);
        $update = $this->act('update', ReceivablePayableKind::Receivable, self::fields(), $route);
        self::assertSame(404, $update['status']);
        self::assertSame(404, $this->act('settle', ReceivablePayableKind::Receivable, [
            'settled_on' => self::day(0),
        ], $route)['status']);
        self::assertSame(404, $this->act('cancel', ReceivablePayableKind::Receivable, [
            'cancel_reason' => 'Not mine',
        ], $route)['status']);

        self::assertSame($before, $this->row($foreign));
        self::assertNull((new ReceivablePayable())->findOfKind($foreign, ReceivablePayableKind::Receivable));

        $index = $this->render('index', ReceivablePayableKind::Receivable);
        self::assertStringNotContainsString('RPT Branch B Customer', $index);
    }

    public function testAPayableIdUnderReceivablesIsNotFound(): void
    {
        $payable = $this->seed(ReceivablePayableKind::Payable);

        $result = $this->act('settle', ReceivablePayableKind::Receivable, [
            'settled_on' => self::day(0),
        ], ['id' => (string) $payable]);

        self::assertSame(404, $result['status']);
        self::assertSame('open', $this->row($payable)['status']);
    }

    // --- the ledger is never touched -----------------------------------------

    public function testNoActionOnTheRegisterWritesToTheLedger(): void
    {
        $ledgerBefore = self::ledgerCount();

        $this->act('store', ReceivablePayableKind::Receivable, [
            'party_name' => 'RPT Ledger Check',
            'amount' => '900',
            'issue_date' => self::day(-2),
        ]);
        $id = (int) Database::instance()->value(
            'SELECT id FROM receivables_payables WHERE branch_id = :b',
            ['b' => $this->branchAId]
        );
        $this->act('update', ReceivablePayableKind::Receivable, self::fields(['amount' => '950']), [
            'id' => (string) $id,
        ]);
        $this->act('settle', ReceivablePayableKind::Receivable, ['settled_on' => self::day(0)], [
            'id' => (string) $id,
        ]);
        $other = $this->seed(ReceivablePayableKind::Payable);
        $this->act('cancel', ReceivablePayableKind::Payable, ['cancel_reason' => 'Not needed'], [
            'id' => (string) $other,
        ]);

        self::assertSame('settled', $this->row($id)['status']);
        self::assertSame('cancelled', $this->row($other)['status']);
        self::assertSame($ledgerBefore, self::ledgerCount(), 'no transactions row was created');
    }

    // --- list ----------------------------------------------------------------

    public function testSummaryCountsOnlyOpenEntriesAndOverdueByDueDate(): void
    {
        $this->seed(ReceivablePayableKind::Receivable, ['amount' => '100', 'due_date' => self::day(-1)]);
        $this->seed(ReceivablePayableKind::Receivable, ['amount' => '200', 'due_date' => self::day(0)]);
        $this->seed(ReceivablePayableKind::Receivable, ['amount' => '400', 'due_date' => '']);
        $settled = $this->seed(ReceivablePayableKind::Receivable, ['amount' => '800', 'due_date' => self::day(-5)]);
        ReceivablePayableService::settle($settled, ReceivablePayableKind::Receivable, self::day(0), null);
        $this->seed(ReceivablePayableKind::Payable, ['amount' => '1600', 'due_date' => self::day(-5)]);

        $summary = (new ReceivablePayable())->summary(ReceivablePayableKind::Receivable, self::day(0));

        self::assertSame(700.0, (float) $summary['open_total']);
        self::assertSame(3, $summary['open_count']);
        self::assertSame(100.0, (float) $summary['overdue_total'], 'due today is not overdue; settled is excluded');
        self::assertSame(1, $summary['overdue_count']);
    }

    public function testIndexRendersOverdueAsTextAndEscapesPartyNames(): void
    {
        $this->seed(ReceivablePayableKind::Receivable, [
            'party_name' => 'RPT <script>alert(1)</script>',
            'due_date' => self::day(-1),
        ]);

        $body = $this->render('index', ReceivablePayableKind::Receivable);

        self::assertStringContainsString('RPT &lt;script&gt;alert(1)&lt;/script&gt;', $body);
        self::assertStringNotContainsString('<script>alert(1)</script>', $body);
        self::assertStringContainsString('>Overdue<', $body);
        self::assertStringContainsString('Mark received', $body);
        self::assertStringContainsString('name="cancel_reason"', $body);
        self::assertStringContainsString('href="' . url('/receivables') . '"', $body, 'linked from the sidebar');
    }

    public function testIndexShowsAnEmptyState(): void
    {
        $body = $this->render('index', ReceivablePayableKind::Payable);

        self::assertStringContainsString('Nothing open', $body);
        self::assertStringContainsString('Record a payable', $body);
    }

    public function testCreateAndEditFormsRender(): void
    {
        $id = $this->seed(ReceivablePayableKind::Payable, ['party_name' => 'RPT Form Vendor']);

        $create = $this->render('create', ReceivablePayableKind::Payable);
        $edit = $this->render('edit', ReceivablePayableKind::Payable, ['id' => (string) $id]);

        self::assertStringContainsString('Record payable', $create);
        self::assertStringContainsString('value="RPT Form Vendor"', $edit);
        self::assertStringContainsString('value="1500.00"', $edit);
    }

    // --- review follow-ups ---------------------------------------------------

    public function testAnIdenticalReSaveIsNotReportedAsAConflict(): void
    {
        $id = $this->seed(ReceivablePayableKind::Receivable);
        $fields = self::fields(['amount' => '1600']);

        // Both saves must land in the same clock second, so the second one
        // writes byte-identical values (modified_at included) and MySQL
        // reports zero changed rows: the case that used to be a false
        // "changed by someone else".
        $fraction = microtime(true) - floor(microtime(true));
        if ($fraction > 0.7) {
            usleep((int) ((1.0 - $fraction) * 1_000_000) + 20_000);
        }
        ReceivablePayableService::update($id, ReceivablePayableKind::Receivable, $fields);
        $first = $this->row($id);
        ReceivablePayableService::update($id, ReceivablePayableKind::Receivable, $fields);

        self::assertSame($first, $this->row($id), 'the re-save changed nothing and raised nothing');

        $result = $this->act('update', ReceivablePayableKind::Receivable, $fields, ['id' => (string) $id]);
        self::assertSame(302, $result['status']);
        self::assertSame('Receivable updated.', $result['session']['_flash']['success'] ?? null);
    }

    public function testADatabaseFailureIsLoggedAndNeverShownToTheUser(): void
    {
        $settleId = $this->seed(ReceivablePayableKind::Receivable);
        $cancelId = $this->seed(ReceivablePayableKind::Receivable);
        // A session whose user row is gone makes the UPDATE (settled_by /
        // cancelled_by) fail a foreign key inside the database: a stand-in
        // for any PDOException, such as a lock wait timeout, that needs no
        // extra privileges to provoke.
        $ghostUserId = (int) Database::instance()->value('SELECT COALESCE(MAX(id), 0) + 1000000 FROM users');
        $ghostSession = ['_auth_user_id' => $ghostUserId, '_active_branch_id' => $this->branchAId];
        $log = STORAGE_PATH . '/logs/app-' . date('Y-m-d') . '.log';
        clearstatcache();
        $logSizeBefore = is_file($log) ? (int) filesize($log) : 0;

        $results = [
            'settle' => $this->act('settle', ReceivablePayableKind::Receivable, [
                'settled_on' => self::day(0),
            ], ['id' => (string) $settleId], 'POST', $ghostSession),
            'cancel' => $this->act('cancel', ReceivablePayableKind::Receivable, [
                'cancel_reason' => 'Duplicate',
            ], ['id' => (string) $cancelId], 'POST', $ghostSession),
        ];

        foreach ($results as $result) {
            self::assertSame(302, $result['status']);
            $error = (string) ($result['session']['_flash']['error'] ?? '');
            self::assertStringContainsString('could not be saved', $error);
            self::assertStringNotContainsString('SQLSTATE', $error);
            self::assertStringNotContainsString('foreign key', $error);
        }
        self::assertSame('open', $this->row($settleId)['status']);
        self::assertSame('open', $this->row($cancelId)['status']);
        self::assertSame(0, $this->auditCount($settleId, 'receivable.settled'));

        clearstatcache();
        $logged = (string) file_get_contents($log, false, null, $logSizeBefore);
        self::assertStringContainsString('"id":' . $settleId . ',"action":"settle"', $logged);
        self::assertStringContainsString('"id":' . $cancelId . ',"action":"cancel"', $logged);
        self::assertSame(2, substr_count($logged, 'Receivables/payables change failed'), 'both failures logged');
        self::assertStringContainsString('SQLSTATE', $logged, 'the detail goes to the log only');
    }

    public function testAnOverLargeAmountIsRejectedNextToTheField(): void
    {
        $result = $this->act('store', ReceivablePayableKind::Receivable, [
            'party_name' => 'RPT Huge Co',
            'amount' => '12345678901234567',
            'issue_date' => self::day(0),
        ]);

        self::assertSame(302, $result['status']);
        self::assertSame(['amount' => ['The amount is too large.']], $result['session']['_flash']['errors'] ?? null);
        self::assertSame(0, $this->entryCount());
    }

    public function testArrayShapedInputIsAValidationErrorNotACrash(): void
    {
        $result = $this->act('store', ReceivablePayableKind::Receivable, [
            'party_name' => ['RPT Array'],
            'amount' => '10',
            'issue_date' => self::day(0),
        ]);
        self::assertSame(302, $result['status']);
        self::assertArrayHasKey('party_name', $result['session']['_flash']['errors'] ?? []);
        self::assertSame(0, $this->entryCount());

        // The form re-renders with the flashed array input without choking on it.
        $_SESSION['_old'] = $result['session']['_old'] ?? [];
        $_SESSION['_flash'] = $result['session']['_flash'] ?? [];
        $form = $this->render('create', ReceivablePayableKind::Receivable);
        self::assertStringContainsString('id="party_name-error"', $form);

        $list = $this->render('index', ReceivablePayableKind::Receivable, [], [
            'q' => ['x'],
            'status' => ['open'],
            'page' => ['2'],
        ]);
        self::assertStringContainsString('Open receivables', $list, 'bad filters fall back to the defaults');
    }

    public function testSearchMatchesPartyNamesWithWildcardsTakenLiterally(): void
    {
        $this->seed(ReceivablePayableKind::Receivable, ['party_name' => 'RPT 100% Traders']);
        $this->seed(ReceivablePayableKind::Receivable, ['party_name' => 'RPT 1000 Traders']);
        $this->seed(ReceivablePayableKind::Receivable, ['party_name' => 'RPT a_b Supplies']);
        $this->seed(ReceivablePayableKind::Receivable, ['party_name' => 'RPT axb Supplies']);
        $this->seed(ReceivablePayableKind::Receivable, ['party_name' => 'RPT back\\slash Ltd']);
        $this->seed(ReceivablePayableKind::Receivable, ['party_name' => 'RPT backslash Ltd']);
        $this->seed(ReceivablePayableKind::Payable, ['party_name' => 'RPT 100% Vendor']);
        $model = new ReceivablePayable();
        $names = static fn (string $search): array => array_column(
            $model->listing(ReceivablePayableKind::Receivable, 'all', $search, 1, 25),
            'party_name'
        );

        self::assertSame(['RPT 100% Traders'], $names('100%'));
        self::assertSame(['RPT a_b Supplies'], $names('a_b'));
        self::assertSame(['RPT back\\slash Ltd'], $names('back\\slash'));
        self::assertSame(1, $model->listingCount(ReceivablePayableKind::Receivable, 'all', '100%'));
        self::assertSame(6, $model->listingCount(ReceivablePayableKind::Receivable, 'all', 'rpt'), 'case-insensitive');

        $body = $this->render('index', ReceivablePayableKind::Receivable, [], ['q' => '100%']);
        self::assertStringContainsString('RPT 100% Traders', $body);
        self::assertStringNotContainsString('RPT 1000 Traders', $body);
        self::assertStringNotContainsString('RPT 100% Vendor', $body, 'payables never show under receivables');
    }

    public function testStatusFilterShowsOneStatusAndFallsBackToOpen(): void
    {
        $this->seed(ReceivablePayableKind::Payable, ['party_name' => 'RPT Open Vendor']);
        $settled = $this->seed(ReceivablePayableKind::Payable, ['party_name' => 'RPT Paid Vendor']);
        ReceivablePayableService::settle($settled, ReceivablePayableKind::Payable, self::day(0), null);
        $cancelled = $this->seed(ReceivablePayableKind::Payable, ['party_name' => 'RPT Cancelled Vendor']);
        ReceivablePayableService::cancel($cancelled, ReceivablePayableKind::Payable, 'Duplicate bill');

        $visible = function (array $query): array {
            $body = $this->render('index', ReceivablePayableKind::Payable, [], $query);

            return array_values(array_filter(
                ['RPT Open Vendor', 'RPT Paid Vendor', 'RPT Cancelled Vendor'],
                static fn (string $name): bool => str_contains($body, $name)
            ));
        };

        self::assertSame(['RPT Open Vendor'], $visible([]), 'open is the default');
        self::assertSame(['RPT Paid Vendor'], $visible(['status' => 'settled']));
        self::assertSame(['RPT Cancelled Vendor'], $visible(['status' => 'cancelled']));
        self::assertSame(
            ['RPT Open Vendor', 'RPT Paid Vendor', 'RPT Cancelled Vendor'],
            $visible(['status' => 'all'])
        );
        self::assertSame(['RPT Open Vendor'], $visible(['status' => 'void']), 'an unknown status falls back to open');
    }

    // --- branch deletion -----------------------------------------------------

    public function testDeletingABranchWithRegisterEntriesSucceeds(): void
    {
        $this->seed(ReceivablePayableKind::Receivable);
        $superAdminId = $this->insertUser('rpt-super@test.local', 'super_admin', null, self::SUPER_ADMIN_PASSWORD);
        $branchName = (string) Database::instance()->value(
            'SELECT name FROM branches WHERE id = :id',
            ['id' => $this->branchAId]
        );

        $status = ControllerActionRunner::run(
            BranchController::class,
            'destroy',
            ['_auth_user_id' => $superAdminId],
            ['confirm_name' => $branchName, 'confirm_password' => self::SUPER_ADMIN_PASSWORD],
            [],
            'POST',
            ['id' => (string) $this->branchAId]
        )['status'];

        self::assertSame(302, $status);
        self::assertSame(0, (int) Database::instance()->value(
            'SELECT COUNT(*) FROM receivables_payables WHERE branch_id = :id',
            ['id' => $this->branchAId]
        ));
        self::assertSame(0, (int) Database::instance()->value(
            'SELECT COUNT(*) FROM branches WHERE id = :id',
            ['id' => $this->branchAId]
        ));
    }
}
