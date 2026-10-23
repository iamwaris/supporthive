<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\BranchController;
use App\Controllers\ReceivablePayableController;
use App\Controllers\TransactionController;
use App\Core\Database;
use App\Domain\ReceivablePayableKind;
use App\Domain\TransactionType;
use App\Models\ReceivablePayable;
use App\Services\DashboardService;
use App\Services\LedgerQuery;
use App\Services\ProfitDistributionService;
use App\Services\ReceivablePayableService;
use App\Services\ReportService;
use App\Services\TransactionService;
use InvalidArgumentException;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\BranchFixture;
use Tests\Support\ControllerActionRunner;

/**
 * The receivables / payables register (AR/AP-1, posting to the ledger since
 * AR/AP-3).
 *
 * The guarantees: an entry leaves 'open' exactly once (settled in full, or
 * cancelled with a reason) and is editable only while open, with amount,
 * issue date and account locked once posted; every change is audited; every
 * id and account is confined to this branch (and the id to this kind); and
 * each step moves the account balance it should — never the P&L.
 */
final class ReceivablePayableTest extends TestCase
{
    private const SUPER_ADMIN_PASSWORD = 'rpt-super-admin-password';

    private int $branchAId;
    private int $branchBId;
    private int $adminAId;
    private int $adminBId;
    private int $cashAId;
    private int $bankAId;
    private int $closedAId;
    private int $cashBId;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->cleanUp();

        $this->branchAId = BranchFixture::create('RPT-A');
        $this->branchBId = BranchFixture::create('RPT-B');
        $this->adminAId = $this->insertUser('rpt-admin-a@test.local', 'admin', $this->branchAId);
        $this->adminBId = $this->insertUser('rpt-admin-b@test.local', 'admin', $this->branchBId);
        $this->bankAId = $this->insertAccount($this->branchAId, 'RPT Main Bank', 'bank');
        $this->cashAId = $this->insertAccount($this->branchAId, 'Cash', 'cash');
        $this->closedAId = $this->insertAccount($this->branchAId, 'RPT Old Till', 'cash', false);
        $this->cashBId = $this->insertAccount($this->branchBId, 'Cash', 'cash');
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
        // Register rows reference transactions, accounts and users, and
        // transactions reference accounts and users, none cascading.
        $db->run(
            'DELETE r FROM receivables_payables r JOIN branches b ON b.id = r.branch_id WHERE b.name LIKE :n',
            ['n' => 'RPT-%']
        );
        $db->run(
            'DELETE t FROM transactions t JOIN branches b ON b.id = t.branch_id WHERE b.name LIKE :n',
            ['n' => 'RPT-%']
        );
        $db->run(
            'DELETE a FROM accounts a JOIN branches b ON b.id = a.branch_id WHERE b.name LIKE :n',
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

    private function insertAccount(int $branchId, string $name, string $type, bool $active = true): int
    {
        return Database::instance()->insert('accounts', [
            'branch_id' => $branchId,
            'name' => $name,
            'type' => $type,
            'opening_balance' => '10000.00',
            'opening_date' => '2026-01-01',
            'is_active' => $active ? 1 : 0,
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
     * @param array<string,mixed> $overrides
     * @return array{party_name:string,reference_no:?string,description:?string,
     *               amount:string,issue_date:string,due_date:?string,account_id:int}
     */
    private function fields(array $overrides = []): array
    {
        /** @var array{party_name:string,reference_no:?string,description:?string,
         *             amount:string,issue_date:string,due_date:?string,account_id:int} $fields */
        $fields = $overrides + [
            'party_name' => 'RPT Acme Traders',
            'reference_no' => 'INV-1001',
            'description' => 'October retainer',
            'amount' => '1500',
            'issue_date' => self::day(-20),
            'due_date' => self::day(10),
            'account_id' => $this->cashAId,
        ];

        return $fields;
    }

    /**
     * The editable fields only, as the edit form posts them.
     *
     * @param array<string,string> $overrides
     * @return array<string,string>
     */
    private static function editPost(array $overrides = []): array
    {
        return $overrides + [
            'party_name' => 'RPT Acme Traders',
            'reference_no' => 'INV-1001',
            'description' => 'October retainer',
            'due_date' => self::day(10),
        ];
    }

    /**
     * Create an entry in the current session's branch through the service.
     *
     * @param array<string,mixed> $overrides
     */
    private function seed(ReceivablePayableKind $kind, array $overrides = []): int
    {
        if (!isset($overrides['account_id']) && ($_SESSION['_active_branch_id'] ?? null) === $this->branchBId) {
            $overrides['account_id'] = $this->cashBId;
        }

        return ReceivablePayableService::record($kind, $this->fields($overrides));
    }

    private function settle(int $id, ReceivablePayableKind $kind, ?int $accountId = null): void
    {
        ReceivablePayableService::settle($id, $kind, self::day(0), null, $accountId ?? $this->cashAId);
    }

    /**
     * @param array<string,mixed> $post
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

    /** @return array<string,mixed> */
    private function transaction(int $id): array
    {
        $row = Database::instance()->first('SELECT * FROM transactions WHERE id = :id', ['id' => $id]);
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

    private function ledgerCount(): int
    {
        return (int) Database::instance()->value(
            'SELECT COUNT(*) FROM transactions WHERE branch_id IN (:a, :b)',
            ['a' => $this->branchAId, 'b' => $this->branchBId]
        );
    }

    private static function balance(int $accountId): string
    {
        return TransactionService::accountBalance($accountId);
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
            'account_id' => (string) $this->bankAId,
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
        self::assertSame($this->bankAId, (int) $row['account_id']);
        self::assertNotNull($row['issue_transaction_id']);
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
        self::assertArrayHasKey('account_id', $errors);
        self::assertSame('12.345', $result['session']['_old']['amount'] ?? null, 'the submitted value is kept');
        self::assertSame(0, $this->entryCount());
        self::assertSame(0, $this->ledgerCount());
    }

    public function testStoreRejectsAZeroAmountAndADueDateBeforeTheIssueDate(): void
    {
        $result = $this->act('store', ReceivablePayableKind::Receivable, [
            'party_name' => 'RPT Zero Co',
            'amount' => '0.00',
            'issue_date' => self::day(-1),
            'due_date' => self::day(-2),
            'account_id' => (string) $this->cashAId,
        ]);

        self::assertSame(302, $result['status']);
        $errors = $result['session']['_flash']['errors'] ?? [];
        self::assertIsArray($errors);
        self::assertSame(['amount', 'due_date'], array_keys($errors));
        self::assertSame(0, $this->entryCount());
    }

    // --- ledger postings -----------------------------------------------------

    public function testRecordingAReceivablePostsMoneyOutOfTheChosenAccount(): void
    {
        $before = self::balance($this->cashAId);
        $id = $this->seed(ReceivablePayableKind::Receivable, ['amount' => '1500']);

        $row = $this->row($id);
        $posting = $this->transaction((int) $row['issue_transaction_id']);
        self::assertSame('receivable_issued', $posting['type']);
        self::assertSame(-1, (int) $posting['direction']);
        self::assertSame('1500.00', $posting['amount']);
        self::assertSame($this->cashAId, (int) $posting['account_id']);
        self::assertSame($row['issue_date'], $posting['transaction_date']);
        self::assertSame('posted', $posting['status']);
        self::assertNull($posting['category_id']);
        self::assertSame($this->adminAId, (int) $posting['created_by']);
        self::assertStringContainsString('register #' . $id, (string) $posting['description']);
        self::assertSame('INV-1001', $posting['reference_no']);
        self::assertSame(bcsub($before, '1500', 2), self::balance($this->cashAId), 'the balance goes down');
    }

    public function testRecordingAPayablePostsMoneyIntoTheChosenAccount(): void
    {
        $before = self::balance($this->bankAId);
        $id = $this->seed(ReceivablePayableKind::Payable, ['amount' => '800', 'account_id' => $this->bankAId]);

        $posting = $this->transaction((int) $this->row($id)['issue_transaction_id']);
        self::assertSame('payable_received', $posting['type']);
        self::assertSame(1, (int) $posting['direction']);
        self::assertSame(bcadd($before, '800', 2), self::balance($this->bankAId), 'the balance goes up');
    }

    public function testSettlingAReceivableCollectsIntoADifferentAccount(): void
    {
        $id = $this->seed(ReceivablePayableKind::Receivable, ['amount' => '1500']);
        $cashBefore = self::balance($this->cashAId);
        $bankBefore = self::balance($this->bankAId);

        $result = $this->act('settle', ReceivablePayableKind::Receivable, [
            'settled_on' => self::day(-1),
            'settlement_account_id' => (string) $this->bankAId,
        ], ['id' => (string) $id]);

        self::assertSame(302, $result['status']);
        $row = $this->row($id);
        self::assertSame($this->bankAId, (int) $row['settlement_account_id']);
        $posting = $this->transaction((int) $row['settlement_transaction_id']);
        self::assertSame('receivable_collected', $posting['type']);
        self::assertSame(1, (int) $posting['direction']);
        self::assertSame(self::day(-1), $posting['transaction_date']);
        self::assertSame($cashBefore, self::balance($this->cashAId), 'the issue account is untouched');
        self::assertSame(bcadd($bankBefore, '1500', 2), self::balance($this->bankAId), 'money comes back in');
    }

    public function testSettlingAPayableRepaysOutOfTheChosenAccount(): void
    {
        $id = $this->seed(ReceivablePayableKind::Payable, ['amount' => '700']);
        $before = self::balance($this->cashAId);

        $this->settle($id, ReceivablePayableKind::Payable);

        $posting = $this->transaction((int) $this->row($id)['settlement_transaction_id']);
        self::assertSame('payable_repaid', $posting['type']);
        self::assertSame(-1, (int) $posting['direction']);
        self::assertSame(bcsub($before, '700', 2), self::balance($this->cashAId), 'money goes back out');
    }

    public function testRegisterMovementsNeverReachProfitAndLossButDoReachCashFlow(): void
    {
        $from = self::day(-30);
        $to = self::day(0);
        $receivable = $this->seed(ReceivablePayableKind::Receivable, ['amount' => '1000']);
        $this->settle($receivable, ReceivablePayableKind::Receivable);
        $payable = $this->seed(ReceivablePayableKind::Payable, ['amount' => '400']);
        $this->settle($payable, ReceivablePayableKind::Payable);

        $profitLoss = ReportService::profitLoss($from, $to);
        self::assertSame(0.0, (float) $profitLoss['revenue']);
        self::assertSame(0.0, (float) $profitLoss['expenses']);
        self::assertSame(0.0, (float) $profitLoss['net']);
        self::assertSame(0.0, (float) DashboardService::revenueBetween($from, $to));
        self::assertSame(0.0, (float) DashboardService::expensesBetween($from, $to));
        self::assertSame(0.0, (float) DashboardService::profitBetween($from, $to));
        self::assertSame(0.0, (float) ProfitDistributionService::distributableProfit($from, $to));
        self::assertSame(0.0, (float) LedgerQuery::posted()->expensesOnly()->between($from, $to)->totalAmount());

        $cashFlow = ReportService::cashFlow($from, $to);
        self::assertSame(1400.0, (float) $cashFlow['inbound'], 'collected 1000 + borrowed 400');
        self::assertSame(1400.0, (float) $cashFlow['outbound'], 'lent 1000 + repaid 400');
        self::assertSame(4, LedgerQuery::posted()->count(), 'all four movements are in the ledger');
    }

    public function testCancellingVoidsTheIssuePostingAndRestoresTheBalance(): void
    {
        $before = self::balance($this->cashAId);
        $id = $this->seed(ReceivablePayableKind::Receivable, ['amount' => '1200']);
        self::assertSame(bcsub($before, '1200', 2), self::balance($this->cashAId));

        $result = $this->act('cancel', ReceivablePayableKind::Receivable, [
            'cancel_reason' => 'Raised in error',
        ], ['id' => (string) $id]);

        self::assertSame(302, $result['status']);
        $row = $this->row($id);
        self::assertSame('cancelled', $row['status']);
        $posting = $this->transaction((int) $row['issue_transaction_id']);
        self::assertSame('void', $posting['status']);
        self::assertSame('Raised in error', $posting['void_reason']);
        self::assertSame($this->adminAId, (int) $posting['voided_by']);
        self::assertSame($before, self::balance($this->cashAId), 'the balance is back where it was');
    }

    public function testARegisterPostingCannotBeVoidedDirectly(): void
    {
        $id = $this->seed(ReceivablePayableKind::Payable);
        $transactionId = (int) $this->row($id)['issue_transaction_id'];

        $result = ControllerActionRunner::run(
            TransactionController::class,
            'void',
            $this->sessionA(),
            ['void_reason' => 'Trying the back door'],
            [],
            'POST',
            ['id' => (string) $transactionId]
        );

        self::assertSame(302, $result['status']);
        $error = (string) ($result['session']['_flash']['error'] ?? '');
        self::assertStringContainsString('Receivables or Payables', $error);
        self::assertSame('posted', $this->transaction($transactionId)['status']);

        $this->expectException(RuntimeException::class);
        TransactionService::void($transactionId, 'Direct call');
    }

    public function testTheRegisterVoidPathRefusesAnOrdinaryTransaction(): void
    {
        $id = $this->seed(ReceivablePayableKind::Payable);
        $transferGroup = TransactionService::postTransfer(
            $this->cashAId,
            $this->bankAId,
            '10',
            self::day(0),
            'RPT move'
        );
        $legId = (int) Database::instance()->value(
            'SELECT id FROM transactions WHERE transfer_group = :g LIMIT 1',
            ['g' => $transferGroup]
        );

        try {
            TransactionService::voidRegisterPosting($legId, 'Not a register row');
            self::fail('an ordinary transaction must not be voidable through the register path');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('not a receivables / payables posting', $e->getMessage());
        }
        self::assertSame('posted', $this->transaction($legId)['status']);
        self::assertSame('open', $this->row($id)['status']);
    }

    public function testRegisterTypesCannotBePostedThroughTheGenericPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TransactionService::post([
            'type' => 'receivable_issued',
            'amount' => '50',
            'account_id' => $this->cashAId,
            'transaction_date' => self::day(0),
            'description' => 'RPT sneaky loan',
        ]);
    }

    // --- account ownership ---------------------------------------------------

    public function testAnotherBranchsOrAClosedAccountIsRejectedOnRecord(): void
    {
        foreach ([$this->cashBId, $this->closedAId, 999999999] as $accountId) {
            $result = $this->act('store', ReceivablePayableKind::Receivable, [
                'party_name' => 'RPT Foreign Account',
                'amount' => '100',
                'issue_date' => self::day(0),
                'account_id' => (string) $accountId,
            ]);

            self::assertSame(302, $result['status']);
            self::assertArrayHasKey('account_id', $result['session']['_flash']['errors'] ?? []);
        }

        self::assertSame(0, $this->entryCount());
        self::assertSame(0, $this->ledgerCount());
    }

    public function testAnotherBranchsAccountIsRejectedOnSettle(): void
    {
        $id = $this->seed(ReceivablePayableKind::Receivable);
        $ledgerBefore = $this->ledgerCount();

        $result = $this->act('settle', ReceivablePayableKind::Receivable, [
            'settled_on' => self::day(0),
            'settlement_account_id' => (string) $this->cashBId,
        ], ['id' => (string) $id]);

        self::assertSame(302, $result['status']);
        $failure = $result['session']['_flash']['rowFailure'] ?? null;
        self::assertIsArray($failure);
        self::assertArrayHasKey('settlement_account_id', $failure['errors']);
        self::assertSame('open', $this->row($id)['status']);
        self::assertSame($ledgerBefore, $this->ledgerCount());
    }

    // --- edit ----------------------------------------------------------------

    public function testAnOpenEntryCanBeEditedAndOnlyTheChangeIsAudited(): void
    {
        $id = $this->seed(ReceivablePayableKind::Receivable);
        $postingBefore = $this->transaction((int) $this->row($id)['issue_transaction_id']);

        $result = $this->act('update', ReceivablePayableKind::Receivable, self::editPost([
            'party_name' => 'RPT Acme Traders Ltd',
            'due_date' => self::day(30),
        ]), ['id' => (string) $id]);

        self::assertSame(302, $result['status']);
        $row = $this->row($id);
        self::assertSame('RPT Acme Traders Ltd', $row['party_name']);
        self::assertSame(self::day(30), $row['due_date']);
        self::assertSame($this->adminAId, (int) $row['modified_by']);
        self::assertSame($postingBefore, $this->transaction((int) $row['issue_transaction_id']), 'ledger untouched');

        $meta = Database::instance()->value(
            "SELECT meta FROM audit_log WHERE entity_id = :id AND action = 'receivable.updated'",
            ['id' => $id]
        );
        $decoded = json_decode((string) $meta, true);
        self::assertIsArray($decoded);
        // MySQL's JSON type reorders object keys on storage; MariaDB's keeps insertion order.
        self::assertEqualsCanonicalizing(
            ['party_name', 'due_date'],
            array_keys($decoded['after']),
            'only changed fields are diffed'
        );
    }

    public function testLockedFieldsCannotBeChangedOnEdit(): void
    {
        $id = $this->seed(ReceivablePayableKind::Receivable);
        $before = $this->row($id);

        $attempts = [
            'amount' => ['amount' => '1750.00'],
            'issue_date' => ['issue_date' => self::day(-19)],
            'account_id' => ['account_id' => (string) $this->bankAId],
        ];
        foreach ($attempts as $field => $change) {
            $result = $this->act(
                'update',
                ReceivablePayableKind::Receivable,
                self::editPost(['party_name' => 'RPT Renamed']) + $change,
                ['id' => (string) $id]
            );

            self::assertSame(302, $result['status']);
            $errors = $result['session']['_flash']['errors'] ?? [];
            self::assertSame([$field], array_keys($errors), $field . ' is refused next to its field');
            self::assertStringContainsString('locked', (string) $errors[$field][0]);
            self::assertSame($before, $this->row($id), 'nothing changed, the party name included');
        }

        // Submitting the stored values unchanged is not a change.
        $same = $this->act('update', ReceivablePayableKind::Receivable, self::editPost([
            'amount' => '1500',
            'issue_date' => (string) $before['issue_date'],
            'account_id' => (string) $this->cashAId,
        ]), ['id' => (string) $id]);
        self::assertSame('Receivable updated.', $same['session']['_flash']['success'] ?? null);
    }

    public function testASettledOrCancelledEntryCannotBeEdited(): void
    {
        $settled = $this->seed(ReceivablePayableKind::Receivable);
        $this->settle($settled, ReceivablePayableKind::Receivable);
        $cancelled = $this->seed(ReceivablePayableKind::Receivable);
        ReceivablePayableService::cancel($cancelled, ReceivablePayableKind::Receivable, 'Raised in error');

        foreach ([$settled, $cancelled] as $id) {
            $before = $this->row($id);

            $form = $this->act('edit', ReceivablePayableKind::Receivable, [], ['id' => (string) $id], 'GET');
            self::assertSame(302, $form['status'], 'the edit form redirects back to the list');

            $result = $this->act(
                'update',
                ReceivablePayableKind::Receivable,
                self::editPost(['party_name' => 'RPT Too Late']),
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
            'settlement_account_id' => (string) $this->cashAId,
        ], ['id' => (string) $id]);

        self::assertSame(302, $result['status']);
        $row = $this->row($id);
        self::assertSame('settled', $row['status']);
        self::assertSame(self::day(-1), $row['settled_on']);
        self::assertSame('Bank transfer 4471', $row['settlement_note']);
        self::assertSame($this->adminAId, (int) $row['settled_by']);
        self::assertNotNull($row['settled_at']);
        self::assertSame(1, $this->auditCount($id, 'payable.settled'));
        $ledgerAfterFirst = $this->ledgerCount();

        $again = $this->act('settle', ReceivablePayableKind::Payable, [
            'settled_on' => self::day(0),
            'settlement_account_id' => (string) $this->cashAId,
        ], ['id' => (string) $id]);
        self::assertSame(302, $again['status']);
        self::assertStringContainsString('already settled', (string) ($again['session']['_flash']['error'] ?? ''));
        self::assertSame(self::day(-1), $this->row($id)['settled_on'], 'the first settlement stands');
        self::assertSame(1, $this->auditCount($id, 'payable.settled'));
        self::assertSame($ledgerAfterFirst, $this->ledgerCount(), 'no second settlement posting');
    }

    public function testSettlementDateCannotBeInTheFutureOrBeforeTheIssueDate(): void
    {
        $id = $this->seed(ReceivablePayableKind::Receivable, ['issue_date' => self::day(-5)]);

        foreach ([self::day(1), self::day(-6), 'not-a-date', ''] as $date) {
            $result = $this->act('settle', ReceivablePayableKind::Receivable, [
                'settled_on' => $date,
                'settlement_account_id' => (string) $this->cashAId,
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
            'settlement_account_id' => (string) $this->bankAId,
        ], ['id' => (string) $id]);

        $_SESSION['_flash'] = $result['session']['_flash'] ?? [];
        $body = $this->render('index', ReceivablePayableKind::Receivable);

        self::assertMatchesRegularExpression('/<details[^>]*\bopen>/', $body, 'the failed row is reopened');
        self::assertStringContainsString('id="settled-on-error-' . $id . '"', $body);
        self::assertStringContainsString('The settlement date cannot be in the future.', $body);
        self::assertStringContainsString('value="' . self::day(2) . '"', $body, 'the submitted date is kept');
        self::assertStringContainsString('value="Cheque 12"', $body);
        self::assertMatchesRegularExpression(
            '/<option value="' . $this->bankAId . '" selected>/',
            $body,
            'the chosen account is kept'
        );
    }

    public function testACancelledEntryCannotBeSettled(): void
    {
        $id = $this->seed(ReceivablePayableKind::Receivable);
        ReceivablePayableService::cancel($id, ReceivablePayableKind::Receivable, 'Duplicate');

        $result = $this->act('settle', ReceivablePayableKind::Receivable, [
            'settled_on' => self::day(0),
            'settlement_account_id' => (string) $this->cashAId,
        ], ['id' => (string) $id]);

        self::assertSame(302, $result['status']);
        $row = $this->row($id);
        self::assertSame('cancelled', $row['status']);
        self::assertNull($row['settled_on']);
        self::assertNull($row['settlement_transaction_id']);
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
        $this->settle($settled, ReceivablePayableKind::Payable);
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
        self::assertSame(
            'posted',
            $this->transaction((int) $this->row($settled)['issue_transaction_id'])['status'],
            'a settled entry keeps both postings'
        );
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
        $update = $this->act('update', ReceivablePayableKind::Receivable, self::editPost(), $route);
        self::assertSame(404, $update['status']);
        self::assertSame(404, $this->act('settle', ReceivablePayableKind::Receivable, [
            'settled_on' => self::day(0),
            'settlement_account_id' => (string) $this->cashAId,
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
            'settlement_account_id' => (string) $this->cashAId,
        ], ['id' => (string) $payable]);

        self::assertSame(404, $result['status']);
        self::assertSame('open', $this->row($payable)['status']);
    }

    // --- list and forms ------------------------------------------------------

    public function testSummaryCountsOnlyOpenEntriesAndOverdueByDueDate(): void
    {
        $this->seed(ReceivablePayableKind::Receivable, ['amount' => '100', 'due_date' => self::day(-1)]);
        $this->seed(ReceivablePayableKind::Receivable, ['amount' => '200', 'due_date' => self::day(0)]);
        $this->seed(ReceivablePayableKind::Receivable, ['amount' => '400', 'due_date' => '']);
        $settled = $this->seed(ReceivablePayableKind::Receivable, ['amount' => '800', 'due_date' => self::day(-5)]);
        $this->settle($settled, ReceivablePayableKind::Receivable);
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

    public function testIndexShowsTheIssueAndSettlementAccounts(): void
    {
        $settled = $this->seed(ReceivablePayableKind::Receivable, ['party_name' => 'RPT Settled Co']);
        $this->settle($settled, ReceivablePayableKind::Receivable, $this->bankAId);

        $body = $this->render('index', ReceivablePayableKind::Receivable, [], ['status' => 'all']);

        self::assertStringContainsString('Paid out from Cash', $body);
        self::assertMatchesRegularExpression('/received into\s+RPT Main Bank/', $body);
    }

    public function testIndexShowsAnEmptyState(): void
    {
        $body = $this->render('index', ReceivablePayableKind::Payable);

        self::assertStringContainsString('Nothing open', $body);
        self::assertStringContainsString('Record a payable', $body);
    }

    public function testCreateFormPreselectsCashAndListsOnlyThisBranchsActiveAccounts(): void
    {
        $create = $this->render('create', ReceivablePayableKind::Payable);

        self::assertStringContainsString('Record payable', $create);
        self::assertMatchesRegularExpression('/<option value="' . $this->cashAId . '" selected>/', $create);
        self::assertStringContainsString('<option value="' . $this->bankAId . '" >', $create);
        self::assertStringNotContainsString('value="' . $this->closedAId . '"', $create, 'closed accounts are hidden');
        self::assertStringNotContainsString('value="' . $this->cashBId . '"', $create, 'other branches are hidden');
    }

    public function testEditFormShowsLockedFieldsReadOnly(): void
    {
        $id = $this->seed(ReceivablePayableKind::Payable, ['party_name' => 'RPT Form Vendor']);

        $edit = $this->render('edit', ReceivablePayableKind::Payable, ['id' => (string) $id]);

        self::assertStringContainsString('value="RPT Form Vendor"', $edit);
        self::assertStringContainsString('1,500.00', $edit);
        self::assertStringNotContainsString('name="amount"', $edit, 'amount is not an input once posted');
        self::assertStringNotContainsString('name="issue_date"', $edit);
        self::assertStringNotContainsString('name="account_id"', $edit);
        self::assertStringContainsString('cancel this entry', $edit, 'the help text says how to correct it');
    }

    public function testTheLedgerListOffersTheRegisterInsteadOfAVoid(): void
    {
        $id = $this->seed(ReceivablePayableKind::Receivable, ['party_name' => 'RPT Ledger Row']);
        $transactionId = (int) $this->row($id)['issue_transaction_id'];

        $_GET = [];
        ob_start();
        try {
            (new TransactionController())->index();
        } finally {
            $body = (string) ob_get_clean();
        }

        self::assertStringContainsString('Receivable issued', $body, 'the type has a readable label');
        self::assertStringContainsString('Manage in Receivables', $body);
        self::assertStringNotContainsString('/transactions/' . $transactionId . '/void', $body);
    }

    // --- review follow-ups ---------------------------------------------------

    public function testAnIdenticalReSaveIsNotReportedAsAConflict(): void
    {
        $id = $this->seed(ReceivablePayableKind::Receivable);
        $fields = ['party_name' => 'RPT Renamed', 'reference_no' => null, 'description' => null, 'due_date' => null];
        $locked = ['amount' => null, 'issue_date' => null, 'account_id' => null];

        // Both saves must land in the same clock second, so the second one
        // writes byte-identical values (modified_at included) and MySQL
        // reports zero changed rows: the case that used to be a false
        // "changed by someone else".
        $fraction = microtime(true) - floor(microtime(true));
        if ($fraction > 0.7) {
            usleep((int) ((1.0 - $fraction) * 1_000_000) + 20_000);
        }
        ReceivablePayableService::update($id, ReceivablePayableKind::Receivable, $fields, $locked);
        $first = $this->row($id);
        ReceivablePayableService::update($id, ReceivablePayableKind::Receivable, $fields, $locked);

        self::assertSame($first, $this->row($id), 'the re-save changed nothing and raised nothing');

        $result = $this->act('update', ReceivablePayableKind::Receivable, self::editPost([
            'party_name' => 'RPT Renamed',
            'reference_no' => '',
            'description' => '',
            'due_date' => '',
        ]), ['id' => (string) $id]);
        self::assertSame(302, $result['status']);
        self::assertSame('Receivable updated.', $result['session']['_flash']['success'] ?? null);
    }

    public function testADatabaseFailureIsLoggedAndNeverShownToTheUser(): void
    {
        $settleId = $this->seed(ReceivablePayableKind::Receivable);
        $cancelId = $this->seed(ReceivablePayableKind::Receivable);
        // A session whose user row is gone makes the writes (the settlement
        // posting's created_by, the void's voided_by) fail a foreign key
        // inside the database: a stand-in for any PDOException, such as a
        // lock wait timeout, that needs no extra privileges to provoke.
        $ghostUserId = (int) Database::instance()->value('SELECT COALESCE(MAX(id), 0) + 1000000 FROM users');
        $ghostSession = ['_auth_user_id' => $ghostUserId, '_active_branch_id' => $this->branchAId];
        $log = STORAGE_PATH . '/logs/app-' . date('Y-m-d') . '.log';
        clearstatcache();
        $logSizeBefore = is_file($log) ? (int) filesize($log) : 0;

        $results = [
            'settle' => $this->act('settle', ReceivablePayableKind::Receivable, [
                'settled_on' => self::day(0),
                'settlement_account_id' => (string) $this->cashAId,
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
        self::assertSame(
            'posted',
            $this->transaction((int) $this->row($cancelId)['issue_transaction_id'])['status'],
            'the rolled-back cancel left its posting in place'
        );
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
            'account_id' => (string) $this->cashAId,
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
            'account_id' => (string) $this->cashAId,
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
        $this->settle($settled, ReceivablePayableKind::Payable);
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

    // --- reopen (undo a settlement) -----------------------------------------

    public function testReopeningVoidsTheSettlementRestoresTheBalanceAndAllowsAFreshSettle(): void
    {
        $id = $this->seed(ReceivablePayableKind::Receivable, ['amount' => '900']);
        $cashBefore = self::balance($this->cashAId);
        $bankBefore = self::balance($this->bankAId);
        $this->settle($id, ReceivablePayableKind::Receivable, $this->bankAId);
        $wrongPosting = (int) $this->row($id)['settlement_transaction_id'];
        self::assertSame(bcadd($bankBefore, '900', 2), self::balance($this->bankAId));

        $result = $this->act('reopen', ReceivablePayableKind::Receivable, [
            'reopen_reason' => 'Collected in cash, not by bank',
        ], ['id' => (string) $id]);

        self::assertSame(302, $result['status']);
        $row = $this->row($id);
        self::assertSame('open', $row['status']);
        $settlementColumns = [
            'settled_on', 'settlement_account_id', 'settlement_transaction_id', 'settled_by', 'settled_at',
        ];
        foreach ($settlementColumns as $f) {
            self::assertNull($row[$f], $f . ' is cleared');
        }
        $voided = $this->transaction($wrongPosting);
        self::assertSame('void', $voided['status']);
        self::assertSame('Collected in cash, not by bank', $voided['void_reason']);
        self::assertSame($bankBefore, self::balance($this->bankAId), 'the wrong settlement is undone');
        self::assertSame('posted', $this->transaction((int) $row['issue_transaction_id'])['status'], 'issue stays');
        self::assertSame(1, $this->auditCount($id, 'receivable.reopened'));

        $this->settle($id, ReceivablePayableKind::Receivable, $this->cashAId);

        $resettled = $this->row($id);
        self::assertSame('settled', $resettled['status']);
        self::assertNotSame($wrongPosting, (int) $resettled['settlement_transaction_id'], 'a fresh posting');
        self::assertSame($this->cashAId, (int) $resettled['settlement_account_id']);
        self::assertSame(bcadd($cashBefore, '900', 2), self::balance($this->cashAId));
        self::assertSame($bankBefore, self::balance($this->bankAId));
    }

    public function testOnlyASettledEntryCanBeReopenedAndOnlyWithAReason(): void
    {
        $open = $this->seed(ReceivablePayableKind::Payable);
        $cancelled = $this->seed(ReceivablePayableKind::Payable);
        ReceivablePayableService::cancel($cancelled, ReceivablePayableKind::Payable, 'Duplicate');
        $settled = $this->seed(ReceivablePayableKind::Payable);
        $this->settle($settled, ReceivablePayableKind::Payable);
        $ledgerBefore = $this->ledgerCount();

        foreach ([$open, $cancelled] as $id) {
            $before = $this->row($id);
            $result = $this->act('reopen', ReceivablePayableKind::Payable, [
                'reopen_reason' => 'Try anyway',
            ], ['id' => (string) $id]);

            self::assertSame(302, $result['status']);
            self::assertStringContainsString(
                'Only a settled entry can be reopened',
                (string) ($result['session']['_flash']['error'] ?? '')
            );
            self::assertSame($before, $this->row($id));
        }

        $noReason = $this->act('reopen', ReceivablePayableKind::Payable, [
            'reopen_reason' => '   ',
        ], ['id' => (string) $settled]);
        self::assertSame(302, $noReason['status']);
        $failure = $noReason['session']['_flash']['rowFailure'] ?? null;
        self::assertIsArray($failure);
        self::assertSame('reopen', $failure['action']);
        self::assertArrayHasKey('reopen_reason', $failure['errors']);
        self::assertSame('settled', $this->row($settled)['status']);
        self::assertSame(0, $this->auditCount($settled, 'payable.reopened'));
        self::assertSame($ledgerBefore, $this->ledgerCount());
        self::assertSame(1, (int) Database::instance()->value(
            "SELECT COUNT(*) FROM transactions WHERE branch_id = :b AND status = 'void'",
            ['b' => $this->branchAId]
        ), 'only the cancelled entry\'s issue posting is void');
    }

    public function testAnotherBranchsSettledEntryCannotBeReopened(): void
    {
        $this->asBranchB();
        $foreign = $this->seed(ReceivablePayableKind::Receivable);
        ReceivablePayableService::settle(
            $foreign,
            ReceivablePayableKind::Receivable,
            self::day(0),
            null,
            $this->cashBId
        );
        $before = $this->row($foreign);
        $this->asBranchA();

        $result = $this->act('reopen', ReceivablePayableKind::Receivable, [
            'reopen_reason' => 'Not mine',
        ], ['id' => (string) $foreign]);

        self::assertSame(404, $result['status']);
        self::assertSame($before, $this->row($foreign));
        self::assertSame('posted', $this->transaction((int) $before['settlement_transaction_id'])['status']);
    }

    public function testASettledRowOffersAReopenPanelThatWorksWithoutJavascript(): void
    {
        $id = $this->seed(ReceivablePayableKind::Receivable, ['party_name' => 'RPT Reopen Co']);
        $this->settle($id, ReceivablePayableKind::Receivable);
        $refused = $this->act('reopen', ReceivablePayableKind::Receivable, [
            'reopen_reason' => '',
        ], ['id' => (string) $id]);

        $_SESSION['_flash'] = $refused['session']['_flash'] ?? [];
        $body = $this->render('index', ReceivablePayableKind::Receivable, [], ['status' => 'settled']);

        self::assertStringContainsString('action="' . url('/receivables/' . $id . '/reopen') . '"', $body);
        self::assertStringContainsString('name="reopen_reason"', $body);
        self::assertStringContainsString('name="_csrf"', $body);
        self::assertMatchesRegularExpression('/<details[^>]*\bopen>/', $body, 'the refused panel is reopened');
        self::assertStringContainsString('id="reopen-reason-error-' . $id . '"', $body);
    }

    // --- entries recorded before the register posted ---------------------------

    private function insertLegacyOpen(ReceivablePayableKind $kind): int
    {
        return Database::instance()->insert('receivables_payables', [
            'branch_id' => $this->branchAId,
            'kind' => $kind->value,
            'party_name' => 'RPT Legacy',
            'amount' => '250.00',
            'issue_date' => self::day(-30),
            'status' => 'open',
            'created_by' => $this->adminAId,
        ]);
    }

    public function testALegacyUnpostedEntryCancelsWithoutAVoidAndCannotBeSettled(): void
    {
        $toCancel = $this->insertLegacyOpen(ReceivablePayableKind::Receivable);
        $toSettle = $this->insertLegacyOpen(ReceivablePayableKind::Receivable);

        $cancel = $this->act('cancel', ReceivablePayableKind::Receivable, [
            'cancel_reason' => 'Never happened',
        ], ['id' => (string) $toCancel]);
        self::assertSame(302, $cancel['status']);
        self::assertSame('cancelled', $this->row($toCancel)['status']);
        self::assertSame(0, $this->ledgerCount(), 'nothing to void, nothing posted');

        $settle = $this->act('settle', ReceivablePayableKind::Receivable, [
            'settled_on' => self::day(0),
            'settlement_account_id' => (string) $this->cashAId,
        ], ['id' => (string) $toSettle]);
        self::assertSame(302, $settle['status']);
        self::assertStringContainsString('backfill', (string) ($settle['session']['_flash']['error'] ?? ''));
        self::assertSame('open', $this->row($toSettle)['status']);
        self::assertSame(0, $this->ledgerCount());
    }

    public function testEditingALegacyEntrysLockedFieldsExplainsTheBackfill(): void
    {
        $id = $this->insertLegacyOpen(ReceivablePayableKind::Payable);

        $result = $this->act('update', ReceivablePayableKind::Payable, self::editPost([
            'account_id' => (string) $this->cashAId,
        ]), ['id' => (string) $id]);

        $errors = $result['session']['_flash']['errors'] ?? [];
        self::assertSame(['account_id'], array_keys($errors));
        self::assertStringContainsString('backfill', (string) $errors['account_id'][0]);
        self::assertNull($this->row($id)['account_id']);
    }

    // --- an account closed after the issue -------------------------------------

    public function testAnAccountClosedAfterIssueStillLetsTheEntryBeCancelledOrSettledElsewhere(): void
    {
        $toCancel = $this->seed(ReceivablePayableKind::Receivable, ['account_id' => $this->bankAId, 'amount' => '300']);
        $toSettle = $this->seed(ReceivablePayableKind::Receivable, ['account_id' => $this->bankAId, 'amount' => '200']);
        Database::instance()->update('accounts', ['is_active' => 0], 'id = :id', ['id' => $this->bankAId]);

        ReceivablePayableService::cancel($toCancel, ReceivablePayableKind::Receivable, 'Bank closed');
        self::assertSame('void', $this->transaction((int) $this->row($toCancel)['issue_transaction_id'])['status']);

        $intoClosed = $this->act('settle', ReceivablePayableKind::Receivable, [
            'settled_on' => self::day(0),
            'settlement_account_id' => (string) $this->bankAId,
        ], ['id' => (string) $toSettle]);
        $failure = $intoClosed['session']['_flash']['rowFailure'] ?? null;
        self::assertIsArray($failure);
        self::assertArrayHasKey('settlement_account_id', $failure['errors'], 'refused next to the account field');
        self::assertSame('open', $this->row($toSettle)['status']);

        $cashBefore = self::balance($this->cashAId);
        $this->settle($toSettle, ReceivablePayableKind::Receivable, $this->cashAId);
        self::assertSame('settled', $this->row($toSettle)['status']);
        self::assertSame(bcadd($cashBefore, '200', 2), self::balance($this->cashAId));
    }

    // --- reconciliation ----------------------------------------------------------

    public function testEveryPostedRegisterTransactionBelongsToExactlyOneEntry(): void
    {
        $a = $this->seed(ReceivablePayableKind::Receivable);
        $this->settle($a, ReceivablePayableKind::Receivable, $this->bankAId);
        ReceivablePayableService::reopen($a, ReceivablePayableKind::Receivable, 'Wrong account');
        $this->settle($a, ReceivablePayableKind::Receivable);
        $b = $this->seed(ReceivablePayableKind::Payable);
        $this->settle($b, ReceivablePayableKind::Payable);
        $c = $this->seed(ReceivablePayableKind::Payable);
        ReceivablePayableService::cancel($c, ReceivablePayableKind::Payable, 'Duplicate');
        $this->seed(ReceivablePayableKind::Receivable);

        $rows = Database::instance()->all(
            "SELECT t.id,
                    (SELECT COUNT(*) FROM receivables_payables r
                      WHERE r.issue_transaction_id = t.id OR r.settlement_transaction_id = t.id) AS owners
             FROM transactions t
             WHERE t.branch_id = :b AND t.status = 'posted'
               AND t.type IN ('receivable_issued', 'receivable_collected', 'payable_received', 'payable_repaid')",
            ['b' => $this->branchAId]
        );

        self::assertCount(5, $rows, 'issues of a, b and the last entry; settlements of a (re-settled) and b');
        foreach ($rows as $row) {
            self::assertSame(1, (int) $row['owners'], 'transaction #' . $row['id'] . ' has exactly one entry');
        }
    }

    public function testARegisterPostingCannotBeCreatedWithoutAnEntryToLinkItTo(): void
    {
        $id = $this->seed(ReceivablePayableKind::Receivable);
        $ledgerBefore = $this->ledgerCount();

        try {
            TransactionService::postRegisterMovement(
                TransactionType::ReceivableIssued,
                $id,
                '10',
                $this->cashAId,
                self::day(0),
                'RPT second issue',
                null
            );
            self::fail('the issue slot is already taken');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('cannot take this posting', $e->getMessage());
        }
        try {
            TransactionService::postRegisterMovement(
                TransactionType::PayableReceived,
                $id,
                '10',
                $this->cashAId,
                self::day(0),
                'RPT wrong kind',
                null
            );
            self::fail('a payable movement cannot link to a receivable');
        } catch (RuntimeException) {
            self::assertSame($ledgerBefore, $this->ledgerCount(), 'both postings were rolled back');
        }
    }

    // --- branch deletion -----------------------------------------------------

    public function testDeletingABranchWithRegisterEntriesSucceeds(): void
    {
        $settled = $this->seed(ReceivablePayableKind::Receivable);
        $this->settle($settled, ReceivablePayableKind::Receivable);
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
