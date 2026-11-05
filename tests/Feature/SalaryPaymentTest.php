<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\PayrollController;
use App\Controllers\TransactionController;
use App\Core\Database;
use App\Models\PayrollRun;
use App\Models\PayrollRunLine;
use App\Services\DashboardService;
use App\Services\Payroll\PaidLinesBlockChange;
use App\Services\Payroll\PayrollImport;
use App\Services\Payroll\PayrollSheetUpdate;
use App\Services\Payroll\SalaryPayment;
use App\Services\Payroll\SalaryPaymentRefused;
use App\Services\ReportService;
use App\Services\TransactionService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\BranchFixture;
use Tests\Support\ControllerActionRunner;
use Tests\Support\JsonColumnAssertions;
use Tests\Support\RouteDispatch;

/**
 * "Mark as paid" on salary notice lines (PAY-5) against the test database:
 * only a confirmed, unpaid line can be paid; paying posts one linked
 * expense (Salaries category) from a chosen active account of the branch
 * on a date not in the future; undo voids it; a paid line locks the
 * month-wide changes; the expense cannot be voided from /transactions.
 */
final class SalaryPaymentTest extends TestCase
{
    use JsonColumnAssertions;

    private const MONTH = '2026-10-01';
    private const ANN_SALARY = '35000';
    private const ANN_DESCRIPTION = 'Salary October 2026 — PSP Ann (PSP-1)';

    private int $branchAId;
    private int $branchBId;
    private int $adminAId;
    private int $adminBId;
    private int $annId;
    private int $bobId;
    private int $cashAId;
    private int $bankAId;
    private int $closedAId;
    private int $cashBId;
    private int $runId;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->cleanUp();

        $this->branchAId = BranchFixture::create('PSP-A');
        $this->branchBId = BranchFixture::create('PSP-B');
        $this->adminAId = $this->insertUser('psp-admin-a@test.local', 'admin', $this->branchAId);
        $this->adminBId = $this->insertUser('psp-admin-b@test.local', 'admin', $this->branchBId);
        $this->annId = $this->insertEmployee('psp-ann@test.local', 'PSP Ann', 'PSP-1');
        $this->bobId = $this->insertEmployee('psp-bob@test.local', 'PSP Bob', 'PSP-2');
        $this->bankAId = $this->insertAccount($this->branchAId, 'PSP Bank', 'bank');
        $this->cashAId = $this->insertAccount($this->branchAId, 'Cash', 'cash');
        $this->closedAId = $this->insertAccount($this->branchAId, 'PSP Old Till', 'cash', false);
        $this->cashBId = $this->insertAccount($this->branchBId, 'Cash', 'cash');

        $_SESSION = $this->sessionA();
        $runs = new PayrollRun();
        $result = PayrollImport::validate([
            1 => ['EmpID', 'Salary', 'Absent', 'WorkingDays'],
            2 => ['PSP-1', self::ANN_SALARY, '0', '26'],
            3 => ['PSP-2', '30000', '0', '26'],
        ], $runs->branchEmployees());
        self::assertTrue($result->passes());
        $this->runId = $runs->import(self::MONTH, null, $result->lines);
        $_SESSION = [];

        // Ann has confirmed her notice; Bob's was never sent.
        Database::instance()->update(
            'payroll_run_lines',
            ['status' => 'confirmed', 'signed_name' => 'PSP Ann', 'responded_at' => date('Y-m-d H:i:s')],
            'id = :id',
            ['id' => $this->lineIdFor($this->annId)]
        );
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        $_SESSION = [];
    }

    private function cleanUp(): void
    {
        $db = Database::instance();
        // Lines reference transactions and accounts; transactions reference
        // accounts and categories; none of them cascade.
        foreach (
            [
                'payroll_run_lines',
                'payroll_runs',
                'transactions',
                'accounts',
                'categories',
                'audit_log',
                'employee_profiles',
            ] as $table
        ) {
            $db->run(
                'DELETE t FROM ' . $table . ' t JOIN branches b ON b.id = t.branch_id WHERE b.name LIKE :n',
                ['n' => 'PSP-%']
            );
        }
        $db->run(
            'DELETE a FROM audit_log a JOIN users u ON u.id = a.user_id WHERE u.email LIKE :e',
            ['e' => 'psp-%@test.local']
        );
        $db->delete('users', 'email LIKE :e', ['e' => 'psp-%@test.local']);
        $db->delete('branches', 'name LIKE :n', ['n' => 'PSP-%']);
    }

    private function insertUser(string $email, string $role, int $branchId, string $name = ''): int
    {
        return Database::instance()->insert('users', [
            'name' => $name !== '' ? $name : 'PSP ' . $email,
            'email' => $email,
            'password_hash' => password_hash('unused', PASSWORD_DEFAULT),
            'role' => $role,
            'branch_id' => $branchId,
            'status' => 'active',
        ]);
    }

    private function insertEmployee(string $email, string $name, string $code): int
    {
        $id = $this->insertUser($email, 'employee', $this->branchAId, $name);
        Database::instance()->insert('employee_profiles', [
            'user_id' => $id,
            'branch_id' => $this->branchAId,
            'employee_code' => $code,
            'designation' => 'Fronter',
        ]);

        return $id;
    }

    private function insertAccount(int $branchId, string $name, string $type, bool $active = true): int
    {
        return Database::instance()->insert('accounts', [
            'branch_id' => $branchId,
            'name' => $name,
            'type' => $type,
            'opening_balance' => '100000.00',
            'opening_date' => '2026-01-01',
            'is_active' => $active ? 1 : 0,
        ]);
    }

    /** @return array<string,int> */
    private function sessionA(): array
    {
        return ['_auth_user_id' => $this->adminAId, '_active_branch_id' => $this->branchAId];
    }

    /** @return array<string,int> */
    private function sessionB(): array
    {
        return ['_auth_user_id' => $this->adminBId, '_active_branch_id' => $this->branchBId];
    }

    private static function day(int $offset): string
    {
        return date('Y-m-d', (int) strtotime(sprintf('%+d days', $offset)));
    }

    private function lineIdFor(int $userId): int
    {
        return (int) Database::instance()->value(
            'SELECT id FROM payroll_run_lines WHERE run_id = :run AND user_id = :user',
            ['run' => $this->runId, 'user' => $userId]
        );
    }

    /** @return array<string,mixed> */
    private function line(int $userId): array
    {
        return (array) Database::instance()->first(
            'SELECT * FROM payroll_run_lines WHERE run_id = :run AND user_id = :user',
            ['run' => $this->runId, 'user' => $userId]
        );
    }

    /** @return array<string,mixed> */
    private function transaction(int $id): array
    {
        return (array) Database::instance()->first('SELECT * FROM transactions WHERE id = :id', ['id' => $id]);
    }

    private function transactionCount(): int
    {
        return (int) Database::instance()->value(
            'SELECT COUNT(*) FROM transactions WHERE branch_id = :b',
            ['b' => $this->branchAId]
        );
    }

    /** Pay Ann in branch A's session through the service. */
    private function payAnn(?int $accountId = null, ?string $paidOn = null): int
    {
        $_SESSION = $this->sessionA();
        try {
            return SalaryPayment::pay(
                $this->runId,
                $this->lineIdFor($this->annId),
                $accountId ?? $this->bankAId,
                $paidOn ?? self::day(-1)
            );
        } finally {
            $_SESSION = [];
        }
    }

    /**
     * @param array<string,string> $post
     * @param array<string,mixed>|null $session
     * @return array{status:int,body:string,session:array<string,mixed>}
     */
    private function act(string $action, array $post = [], ?array $session = null, ?int $userId = null): array
    {
        return ControllerActionRunner::run(
            PayrollController::class,
            $action,
            $session ?? $this->sessionA(),
            $post,
            [],
            $action === 'show' ? 'GET' : 'POST',
            ['id' => (string) $this->runId] + ($userId === null ? [] : ['line' => (string) $this->lineIdFor($userId)])
        );
    }

    /** @return array<string,mixed> the latest such audit row's meta, decoded */
    private function auditMeta(string $action, int $entityId): array
    {
        return (array) json_decode((string) Database::instance()->value(
            'SELECT meta FROM audit_log WHERE action = :action AND entity_id = :id ORDER BY id DESC LIMIT 1',
            ['action' => $action, 'id' => $entityId]
        ), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testPayingAConfirmedLinePostsOneLinkedSalaryExpense(): void
    {
        $paidOn = self::day(-1);
        $transactionId = $this->payAnn($this->bankAId, $paidOn);

        $expense = $this->transaction($transactionId);
        self::assertSame('expense', $expense['type']);
        self::assertSame('posted', $expense['status']);
        self::assertSame('35000.00', (string) $expense['amount']);
        self::assertSame($this->bankAId, (int) $expense['account_id']);
        self::assertSame($paidOn, $expense['transaction_date']);
        self::assertSame(self::ANN_DESCRIPTION, $expense['description']);
        self::assertSame($this->branchAId, (int) $expense['branch_id']);
        self::assertSame($this->adminAId, (int) $expense['created_by']);
        $category = Database::instance()->first(
            'SELECT name, type, branch_id FROM categories WHERE id = :id',
            ['id' => (int) $expense['category_id']]
        );
        self::assertSame(
            ['name' => 'Salaries', 'type' => 'expense', 'branch_id' => $this->branchAId],
            ['name' => $category['name'] ?? null, 'type' => $category['type'] ?? null,
                'branch_id' => (int) ($category['branch_id'] ?? 0)],
            'the branch had no salary category, so one was created'
        );

        $ann = $this->line($this->annId);
        self::assertSame($transactionId, (int) $ann['paid_transaction_id']);
        self::assertSame($this->bankAId, (int) $ann['paid_account_id']);
        self::assertSame($paidOn, $ann['paid_at']);
        self::assertSame($this->adminAId, (int) $ann['paid_by']);
        self::assertSame('confirmed', $ann['status']);

        $this->assertJsonColumnEquals(
            [
                'transaction_id' => $transactionId,
                'amount' => '35000.00',
                'account_id' => $this->bankAId,
                'category_id' => (int) $expense['category_id'],
                'paid_at' => $paidOn,
            ],
            $this->auditMeta('payroll_line.paid', $this->lineIdFor($this->annId))['after'] ?? null
        );

        // The salary is a real expense: P&L and the dashboard count it.
        $_SESSION = $this->sessionA();
        $from = self::day(-30);
        $to = self::day(0);
        self::assertSame(35000.0, (float) ReportService::profitLoss($from, $to)['expenses']);
        self::assertSame(-35000.0, (float) ReportService::profitLoss($from, $to)['net']);
        self::assertSame(35000.0, (float) DashboardService::expensesBetween($from, $to));
        self::assertSame('65000.00', TransactionService::accountBalance($this->bankAId));
        self::assertSame(1, (int) ((new PayrollRun())->listWithCounts(1, 10)[0]['paid_count'] ?? 0));
        $_SESSION = [];
    }

    public function testTheBranchsExistingSalariesCategoryIsUsed(): void
    {
        $existing = Database::instance()->insert('categories', [
            'branch_id' => $this->branchAId,
            'name' => 'Salaries',
            'type' => 'expense',
            'sort_order' => 10,
        ]);

        $transactionId = $this->payAnn();

        self::assertSame($existing, (int) $this->transaction($transactionId)['category_id']);
        self::assertSame(1, (int) Database::instance()->value(
            'SELECT COUNT(*) FROM categories WHERE branch_id = :b',
            ['b' => $this->branchAId]
        ));
    }

    public function testOnlyAConfirmedLineCanBePaid(): void
    {
        $result = $this->act(
            'markPaid',
            ['account_id' => (string) $this->cashAId, 'paid_at' => self::day(0)],
            null,
            $this->bobId
        );

        self::assertSame(302, $result['status']);
        self::assertStringContainsString('confirmed', (string) ($result['session']['_flash']['error'] ?? ''));
        self::assertSame(0, $this->transactionCount());
        self::assertNull($this->line($this->bobId)['paid_transaction_id']);
    }

    public function testAnotherBranchsOrAClosedAccountIsRefusedAsAFieldError(): void
    {
        foreach ([$this->cashBId, $this->closedAId, 999999999] as $accountId) {
            $result = $this->act(
                'markPaid',
                ['account_id' => (string) $accountId, 'paid_at' => self::day(0)],
                null,
                $this->annId
            );
            self::assertSame(302, $result['status'], 'account ' . $accountId);
            $errors = $result['session']['_flash']['errors'] ?? [];
            self::assertArrayHasKey('account_id', $errors, 'account ' . $accountId);
        }

        self::assertSame(0, $this->transactionCount());
        self::assertSame(0, (int) Database::instance()->value(
            'SELECT COUNT(*) FROM transactions WHERE branch_id = :b',
            ['b' => $this->branchBId]
        ));
        self::assertNull($this->line($this->annId)['paid_transaction_id']);
    }

    public function testAFutureOrMalformedDateIsAFieldError(): void
    {
        foreach ([self::day(1), '2026-02-30', 'yesterday', ''] as $paidOn) {
            $result = $this->act(
                'markPaid',
                ['account_id' => (string) $this->cashAId, 'paid_at' => $paidOn],
                null,
                $this->annId
            );
            self::assertSame(302, $result['status'], 'date ' . $paidOn);
            self::assertArrayHasKey('paid_at', $result['session']['_flash']['errors'] ?? [], 'date ' . $paidOn);
        }

        self::assertSame(0, $this->transactionCount());
    }

    public function testAMissingAccountIsAFieldErrorNotA500(): void
    {
        $result = $this->act('markPaid', ['paid_at' => self::day(0)], null, $this->annId);

        self::assertSame(302, $result['status']);
        self::assertSame(
            ['Choose the account the salary was paid from.'],
            $result['session']['_flash']['errors']['account_id'] ?? null
        );
        self::assertSame($this->lineIdFor($this->annId), $result['session']['_flash']['payroll_pay_line'] ?? null);
        self::assertSame(0, $this->transactionCount());
    }

    public function testPayingTwiceRecordsOnlyOneExpense(): void
    {
        $post = ['account_id' => (string) $this->cashAId, 'paid_at' => self::day(0)];
        $first = $this->act('markPaid', $post, null, $this->annId);
        $second = $this->act('markPaid', $post, null, $this->annId);

        self::assertSame(302, $first['status']);
        self::assertStringContainsString(
            'Recorded Rs 35,000.00 salary for PSP Ann from Cash on',
            (string) ($first['session']['_flash']['success'] ?? '')
        );
        self::assertSame(302, $second['status']);
        self::assertSame('This salary is already marked as paid.', $second['session']['_flash']['error'] ?? null);
        self::assertSame(1, $this->transactionCount());

        $this->expectException(SalaryPaymentRefused::class);
        $this->payAnn();
    }

    public function testUndoVoidsTheExpenseAndTheLineCanBePaidAgain(): void
    {
        $transactionId = $this->payAnn($this->cashAId);

        $result = $this->act('markUnpaid', [], null, $this->annId);

        self::assertSame(302, $result['status']);
        $success = (string) ($result['session']['_flash']['success'] ?? '');
        self::assertStringContainsString('Payment undone for PSP Ann', $success);
        $voided = $this->transaction($transactionId);
        self::assertSame('void', $voided['status']);
        self::assertSame($this->adminAId, (int) $voided['voided_by']);
        $ann = $this->line($this->annId);
        foreach (['paid_at', 'paid_by', 'paid_transaction_id', 'paid_account_id'] as $column) {
            self::assertNull($ann[$column], $column);
        }
        self::assertSame('confirmed', $ann['status']);
        $this->assertJsonColumnEquals(
            ['voided_transaction_id' => $transactionId],
            $this->auditMeta('payroll_line.unpaid', $this->lineIdFor($this->annId))['after'] ?? null
        );

        $_SESSION = $this->sessionA();
        self::assertSame(0.0, (float) ReportService::profitLoss(self::day(-30), self::day(0))['expenses']);
        self::assertSame('100000.00', TransactionService::accountBalance($this->cashAId));
        $_SESSION = [];

        $again = $this->act('markUnpaid', [], null, $this->annId);
        self::assertSame('This salary is not marked as paid.', $again['session']['_flash']['error'] ?? null);

        $repaid = $this->payAnn($this->bankAId);
        self::assertNotSame($transactionId, $repaid);
        self::assertSame('posted', $this->transaction($repaid)['status']);
    }

    public function testAPaidLineBlocksEditsWithdrawReplaceAndSheetUpdates(): void
    {
        $this->payAnn();
        $annLineId = $this->lineIdFor($this->annId);
        $before = $this->line($this->annId);

        $_SESSION = $this->sessionA();
        self::assertFalse((new PayrollRunLine())->updateFigures($this->runId, $annLineId, [
            'salary' => '1.00',
            'absent_days' => '0',
            'working_days' => '26',
        ]));

        $refusals = [
            'withdraw' => fn () => (new PayrollRun())->withdraw($this->runId, 'Wrong sheet'),
            'replace' => fn () => (new PayrollRun())->import(self::MONTH, null, []),
            'sheet update' => function (): void {
                $sheet = [1 => ['EmpID', 'Salary', 'Absent', 'WorkingDays'], 2 => ['PSP-2', '31000', '0', '26']];
                $plan = PayrollSheetUpdate::plan(
                    $sheet,
                    (new PayrollRun())->branchEmployees(),
                    (new PayrollRunLine())->forRun($this->runId)
                );
                (new PayrollRun())->updateFromSheet($this->runId, $sheet, $plan->state);
            },
        ];
        foreach ($refusals as $what => $attempt) {
            try {
                $attempt();
                self::fail($what . ' must be refused while a line is paid');
            } catch (PaidLinesBlockChange) {
                // Expected.
            }
        }
        $_SESSION = [];

        $withdraw = $this->act('withdraw', ['withdraw_reason' => 'Wrong sheet']);
        self::assertSame(
            'Nothing was withdrawn. ' . PaidLinesBlockChange::MESSAGE,
            $withdraw['session']['_flash']['error'] ?? null
        );
        $edit = $this->act(
            'updateLine',
            ['salary' => '1', 'absent_days' => '0', 'working_days' => '26'],
            null,
            $this->annId
        );
        self::assertStringContainsString('marked as paid', (string) ($edit['session']['_flash']['error'] ?? ''));
        $upload = $this->act('uploadSheet');
        self::assertSame(
            [PaidLinesBlockChange::MESSAGE],
            $upload['session']['_flash']['errors']['sheet'] ?? null
        );

        self::assertSame($before, $this->line($this->annId));
        self::assertSame('30000.00', (string) $this->line($this->bobId)['salary']);
        self::assertSame(1, $this->transactionCount());
    }

    public function testASalaryExpenseIsVoidedOnlyFromItsSalaryNotice(): void
    {
        // Today, so the ledger's default date range always includes it.
        $transactionId = $this->payAnn($this->bankAId, self::day(0));

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
        self::assertStringContainsString('Salary notices', (string) ($result['session']['_flash']['error'] ?? ''));
        self::assertSame('posted', $this->transaction($transactionId)['status']);

        $ledger = ControllerActionRunner::run(TransactionController::class, 'index', $this->sessionA(), [], [], 'GET');
        self::assertSame(200, $ledger['status']);
        self::assertStringContainsString('Manage in Salary notices', $ledger['body']);
        self::assertStringContainsString(
            '/salary-notices/' . $this->runId . '#line-' . $this->lineIdFor($this->annId),
            $ledger['body']
        );
        self::assertStringNotContainsString('/transactions/' . $transactionId . '/void', $ledger['body']);

        $_SESSION = $this->sessionA();
        try {
            TransactionService::void($transactionId, 'Direct call');
            self::fail('a salary payment must not be voidable through the ledger path');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('salary payment', $e->getMessage());
        }

        $ordinary = TransactionService::post([
            'type' => 'expense',
            'amount' => '10',
            'account_id' => $this->cashAId,
            'category_id' => (int) $this->transaction($transactionId)['category_id'],
            'transaction_date' => self::day(0),
            'description' => 'PSP ordinary expense',
        ]);
        try {
            TransactionService::voidSalaryPayment($ordinary, 'Not a salary');
            self::fail('an ordinary transaction must not be voidable through the salary path');
        } catch (RuntimeException $e) {
            self::assertSame('That transaction is not a salary payment.', $e->getMessage());
        } finally {
            $_SESSION = [];
        }
        self::assertSame('posted', $this->transaction($ordinary)['status']);
    }

    public function testAnotherBranchGets404AndNonAdminsGet403(): void
    {
        $paid = $this->payAnn();
        $partnerId = $this->insertUser('psp-partner@test.local', 'partner', $this->branchAId);
        $post = ['account_id' => (string) $this->cashBId, 'paid_at' => self::day(0)];

        foreach (['markPaid', 'markUnpaid'] as $action) {
            self::assertSame(404, $this->act($action, $post, $this->sessionB(), $this->annId)['status'], $action);
        }

        $lineBase = '/salary-notices/' . $this->runId . '/lines/' . $this->lineIdFor($this->annId);
        foreach ([$this->annId, $partnerId] as $userId) {
            foreach (['/pay', '/unpay'] as $suffix) {
                $result = ControllerActionRunner::run(
                    RouteDispatch::class,
                    'dispatch',
                    [
                        '_auth_user_id' => $userId,
                        '_active_branch_id' => $this->branchAId,
                        '_csrf_token' => 'psp-csrf',
                        '_csrf_issued_at' => time(),
                    ],
                    ['_csrf' => 'psp-csrf', 'account_id' => (string) $this->cashAId, 'paid_at' => self::day(0)],
                    [],
                    'POST',
                    ['uri' => $lineBase . $suffix]
                );
                self::assertSame(403, $result['status'], $suffix . ' as user ' . $userId);
            }
        }

        self::assertSame('posted', $this->transaction($paid)['status']);
        self::assertSame($paid, (int) $this->line($this->annId)['paid_transaction_id']);
        self::assertSame(1, $this->transactionCount());
    }

    public function testTheRunPageOffersPaymentThenShowsItAsText(): void
    {
        $page = $this->act('show');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Mark as paid', $page['body']);
        self::assertStringContainsString('/lines/' . $this->lineIdFor($this->annId) . '/pay', $page['body']);
        self::assertStringNotContainsString('/lines/' . $this->lineIdFor($this->bobId) . '/pay', $page['body']);
        self::assertMatchesRegularExpression(
            '#<option value="' . $this->cashAId . '" selected>Cash</option>#',
            $page['body'],
            'Cash is the default account'
        );
        self::assertStringNotContainsString('PSP Old Till', $page['body'], 'closed accounts are not offered');
        self::assertStringContainsString('max="' . self::day(0) . '"', $page['body']);

        $this->payAnn($this->bankAId, self::day(-1));
        $paid = $this->act('show');
        self::assertStringContainsString(
            'Paid ' . date('j M Y', (int) strtotime(self::day(-1))) . ' from PSP Bank',
            $paid['body']
        );
        self::assertStringContainsString('Mark as unpaid', $paid['body']);
        self::assertStringContainsString('view expense', $paid['body']);
        self::assertStringContainsString('Cannot withdraw:', $paid['body']);
        self::assertStringNotContainsString('name="withdraw_reason"', $paid['body']);
    }
}
