<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\BranchController;
use App\Controllers\EmployeeController;
use App\Controllers\UserController;
use App\Core\Database;
use App\Core\View;
use App\Models\EmployeeDocument;
use App\Models\EmployeeProfile;
use App\Models\EmployeeSalary;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\BranchFixture;
use Tests\Support\ControllerActionRunner;

/**
 * Employee salary (App\Models\EmployeeSalary, EmployeeController@changeSalary,
 * the optional starting salary in App\Services\EmployeeOnboarding).
 *
 * The guarantees: the history is append-only and effective-dated (current
 * is the latest row in effect today, ties broken by id; a future row is a
 * scheduled change); every read and write is confined to employees of this
 * branch; a rejected change writes nothing; and the employee's own portal
 * never shows the amount.
 */
final class EmployeeSalaryTest extends TestCase
{
    private const SUPER_ADMIN_PASSWORD = 'ems-super-admin-password';

    private int $branchAId;
    private int $branchBId;
    private int $adminAId;
    private int $adminBId;
    private int $employeeAId;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->cleanUp();

        $this->branchAId = BranchFixture::create('EMS-A');
        $this->branchBId = BranchFixture::create('EMS-B');
        $this->adminAId = $this->insertUser('ems-admin-a@test.local', 'admin', $this->branchAId);
        $this->adminBId = $this->insertUser('ems-admin-b@test.local', 'admin', $this->branchBId);

        $this->asBranchA();
        $this->employeeAId = $this->createEmployee('ems-employee-a@test.local');
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        $_SESSION = [];
    }

    private function cleanUp(): void
    {
        $db = Database::instance();
        // Salary rows reference users without cascading on created_by, so
        // they go first.
        $db->run(
            'DELETE s FROM employee_salaries s JOIN branches b ON b.id = s.branch_id WHERE b.name LIKE :n',
            ['n' => 'EMS-%']
        );
        $db->run(
            'DELETE a FROM audit_log a JOIN branches b ON b.id = a.branch_id WHERE b.name LIKE :n',
            ['n' => 'EMS-%']
        );
        $db->run(
            'DELETE a FROM audit_log a JOIN users u ON u.id = a.user_id WHERE u.email LIKE :e',
            ['e' => 'ems-%@test.local']
        );
        $db->delete('users', 'email LIKE :e', ['e' => 'ems-%@test.local']);
        $db->delete('branches', 'name LIKE :n', ['n' => 'EMS-%']);
    }

    private function insertUser(string $email, string $role, ?int $branchId, string $password = 'unused'): int
    {
        return Database::instance()->insert('users', [
            'name' => 'EMS ' . $email,
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

    private function createEmployee(string $email): int
    {
        return (new EmployeeProfile())->createWithLogin([
            'name' => 'EMS Employee ' . $email,
            'email' => $email,
            'phone' => null,
            'designation' => 'Technician',
            'joining_date' => '2026-01-15',
        ], password_hash('unused', PASSWORD_DEFAULT));
    }

    private static function day(int $offset): string
    {
        return date('Y-m-d', (int) strtotime(sprintf('%+d days', $offset)));
    }

    private function salaryCount(?int $userId = null): int
    {
        return (int) Database::instance()->value(
            'SELECT COUNT(*) FROM employee_salaries WHERE user_id = :id',
            ['id' => $userId ?? $this->employeeAId]
        );
    }

    /**
     * @param array<string,string> $post
     * @return array{status:int,body:string,session:array<string,mixed>}
     */
    private function changeSalary(int $id, array $post): array
    {
        return ControllerActionRunner::run(
            EmployeeController::class,
            'changeSalary',
            $this->sessionA(),
            $post,
            [],
            'POST',
            ['id' => (string) $id]
        );
    }

    public function testCurrentIgnoresFutureRowsAndScheduledChangeIsExposed(): void
    {
        $salaries = new EmployeeSalary();
        $salaries->record($this->employeeAId, '40000', self::day(-60), 'Hired');
        $salaries->record($this->employeeAId, '45000.5', self::day(-10), 'Raise');
        $salaries->record($this->employeeAId, '50000', self::day(30), 'Next raise');
        $salaries->record($this->employeeAId, '60000', self::day(90), null);

        $current = $salaries->current($this->employeeAId);
        self::assertNotNull($current);
        self::assertSame('45000.50', $current['amount'], 'a future-dated row is not current yet');
        self::assertSame(self::day(-10), $current['effective_from']);

        $upcoming = $salaries->upcoming($this->employeeAId);
        self::assertNotNull($upcoming);
        self::assertSame('50000.00', $upcoming['amount'], 'the nearest future change is the scheduled one');
        self::assertSame(self::day(30), $upcoming['effective_from']);
    }

    public function testRowsWithTheSameEffectiveDateAreTieBrokenByTheLaterRecord(): void
    {
        $salaries = new EmployeeSalary();
        $salaries->record($this->employeeAId, '30000', self::day(0), null);
        $corrected = $salaries->record($this->employeeAId, '32000', self::day(0), 'Corrected');

        $current = $salaries->current($this->employeeAId);
        self::assertNotNull($current);
        self::assertSame($corrected, (int) $current['id']);
        self::assertSame('32000.00', $current['amount']);
        self::assertSame(2, $this->salaryCount(), 'a correction is a new row; nothing is overwritten');
    }

    public function testHistoryIsNewestFirstWithChangeAndStatus(): void
    {
        $salaries = new EmployeeSalary();
        $salaries->record($this->employeeAId, '40000', self::day(-60), null);
        $salaries->record($this->employeeAId, '38000', self::day(-5), 'Reduced hours');
        $salaries->record($this->employeeAId, '45000', self::day(20), null);

        $history = $salaries->history($this->employeeAId);

        self::assertSame(['45000.00', '38000.00', '40000.00'], array_column($history, 'amount'));
        self::assertSame(['7000.00', '-2000.00', null], array_column($history, 'change'));
        self::assertSame(['scheduled', 'current', 'past'], array_column($history, 'status'));
        self::assertSame('EMS ems-admin-a@test.local', $history[0]['created_by_name']);
        self::assertSame('Reduced hours', $history[1]['note']);
    }

    public function testRecordAuditsTheChangeFromTheCurrentSalary(): void
    {
        $salaries = new EmployeeSalary();
        $salaries->record($this->employeeAId, '40000', self::day(-30), null);
        $salaries->record($this->employeeAId, '42000', self::day(0), 'Raise');

        $rows = Database::instance()->all(
            "SELECT meta FROM audit_log WHERE action = 'employee.salary_changed' AND entity_id = :id ORDER BY id",
            ['id' => $this->employeeAId]
        );
        self::assertCount(2, $rows);

        $first = json_decode((string) $rows[0]['meta'], true);
        $second = json_decode((string) $rows[1]['meta'], true);
        self::assertArrayNotHasKey('before', $first, 'the first salary has nothing before it');
        self::assertSame(['amount' => '40000.00', 'effective_from' => self::day(-30)], $second['before']);
        self::assertSame('42000.00', $second['after']['amount']);
        self::assertSame('Raise', $second['after']['note']);
    }

    public function testReadsAndWritesAreConfinedToThisBranchsEmployees(): void
    {
        $this->asBranchB();
        $employeeB = $this->createEmployee('ems-employee-b@test.local');
        (new EmployeeSalary())->record($employeeB, '99000', self::day(-1), null);

        $this->asBranchA();
        $salaries = new EmployeeSalary();
        self::assertNull($salaries->current($employeeB));
        self::assertSame([], $salaries->history($employeeB));
        self::assertSame([], $salaries->currentForUsers([$employeeB]));

        foreach ([$employeeB, $this->adminAId] as $notAnEmployeeHere) {
            try {
                $salaries->record($notAnEmployeeHere, '1', self::day(0), null);
                self::fail('record() must refuse a user who is not an employee of this branch');
            } catch (RuntimeException) {
                // expected
            }
        }
        self::assertSame(1, $this->salaryCount($employeeB));
        self::assertSame(0, $this->salaryCount($this->adminAId));
    }

    public function testCurrentForUsersReturnsEachEmployeesSalaryInEffectToday(): void
    {
        $other = $this->createEmployee('ems-employee-a2@test.local');
        $salaries = new EmployeeSalary();
        $salaries->record($this->employeeAId, '40000', self::day(-30), null);
        $salaries->record($this->employeeAId, '41000', self::day(-30), null);
        $salaries->record($this->employeeAId, '90000', self::day(30), null);
        $salaries->record($other, '25000', self::day(10), null);

        self::assertSame(
            [$this->employeeAId => '41000.00'],
            $salaries->currentForUsers([$this->employeeAId, $other]),
            'tie broken by id; a salary that only starts in the future is not shown yet'
        );
        self::assertSame([], $salaries->currentForUsers([]));
    }

    public function testChangeSalaryRecordsARowAndRedirectsWithAFlash(): void
    {
        $result = $this->changeSalary($this->employeeAId, [
            'amount' => '52000.75',
            'effective_from' => self::day(0),
            'note' => '  Promotion  ',
        ]);

        self::assertSame(302, $result['status']);
        self::assertSame('Salary updated.', $result['session']['_flash']['success'] ?? null);

        $row = Database::instance()->first(
            'SELECT * FROM employee_salaries WHERE user_id = :id',
            ['id' => $this->employeeAId]
        );
        self::assertNotNull($row);
        self::assertSame('52000.75', $row['amount']);
        self::assertSame('Promotion', $row['note']);
        self::assertSame($this->branchAId, (int) $row['branch_id']);
        self::assertSame($this->adminAId, (int) $row['created_by']);
    }

    public function testChangeSalaryIs404ForAnotherBranchsEmployeeOrAnAdmin(): void
    {
        $this->asBranchB();
        $employeeB = $this->createEmployee('ems-employee-b@test.local');
        $valid = ['amount' => '1000', 'effective_from' => self::day(0), 'note' => ''];

        self::assertSame(404, $this->changeSalary($employeeB, $valid)['status'], 'another branch\'s employee');
        self::assertSame(404, $this->changeSalary($this->adminAId, $valid)['status'], 'an admin is not an employee');
        self::assertSame(404, $this->changeSalary($this->adminBId, $valid)['status']);

        self::assertSame(0, $this->salaryCount($employeeB));
        self::assertSame(0, $this->salaryCount($this->adminAId));
    }

    public function testInvalidChangeInsertsNothingAndFlagsTheField(): void
    {
        $cases = [
            ['amount', ['amount' => '-5']],
            ['amount', ['amount' => '12.345']],
            ['amount', ['amount' => '1,000']],
            ['amount', ['amount' => 'abc']],
            ['amount', ['amount' => '10000000000']],
            ['amount', ['amount' => '']],
            ['effective_from', ['effective_from' => '2026-02-30']],
            ['effective_from', ['effective_from' => '']],
            ['note', ['note' => str_repeat('x', 256)]],
        ];

        foreach ($cases as [$field, $override]) {
            $result = $this->changeSalary(
                $this->employeeAId,
                $override + ['amount' => '1000', 'effective_from' => self::day(0), 'note' => '']
            );
            self::assertSame(302, $result['status']);
            $errors = $result['session']['_flash']['errors'] ?? [];
            self::assertArrayHasKey($field, $errors, (string) json_encode($override));
            self::assertSame($override[$field], $result['session']['_old'][$field] ?? null, 'input kept for old()');
        }

        self::assertSame(0, $this->salaryCount());
    }

    public function testTheAmountCapIsInclusive(): void
    {
        $result = $this->changeSalary(
            $this->employeeAId,
            ['amount' => '9999999999.99', 'effective_from' => self::day(0), 'note' => '']
        );

        self::assertSame(302, $result['status']);
        self::assertSame([], $result['session']['_flash']['errors'] ?? []);
        self::assertSame(1, $this->salaryCount());
    }

    /** @return array<string,string> */
    private function newEmployeePost(string $email, array $overrides = []): array
    {
        return $overrides + [
            'name' => 'EMS New Hire',
            'email' => $email,
            'phone' => '',
            'designation' => 'Field Technician',
            'joining_date' => '2026-09-01',
            'salary' => '',
        ];
    }

    private function idByEmail(string $email): ?int
    {
        $id = Database::instance()->value('SELECT id FROM users WHERE email = :e', ['e' => $email]);

        return $id === null ? null : (int) $id;
    }

    public function testBothCreationPathsWriteTheStartingSalaryWithTheEmployee(): void
    {
        $paths = [
            'employees' => [EmployeeController::class, []],
            'users' => [UserController::class, ['role' => 'employee']],
        ];

        foreach ($paths as $label => [$controller, $extra]) {
            $email = 'ems-start-' . $label . '@test.local';
            $result = ControllerActionRunner::run(
                $controller,
                'store',
                $this->sessionA(),
                $this->newEmployeePost($email, $extra + ['salary' => '35000.5'])
            );
            self::assertSame(302, $result['status'], $label);

            $id = $this->idByEmail($email);
            self::assertNotNull($id, $label);
            $row = Database::instance()->first('SELECT * FROM employee_salaries WHERE user_id = :id', ['id' => $id]);
            self::assertNotNull($row, "{$label}: the starting salary is recorded");
            self::assertSame('35000.50', $row['amount']);
            self::assertSame('2026-09-01', $row['effective_from'], "{$label}: effective from the joining date");
            self::assertSame($this->branchAId, (int) $row['branch_id']);
            self::assertSame(1, $this->salaryCount($id));
        }
    }

    public function testStartingSalaryWithoutAJoiningDateTakesEffectToday(): void
    {
        $email = 'ems-start-today@test.local';
        ControllerActionRunner::run(
            EmployeeController::class,
            'store',
            $this->sessionA(),
            $this->newEmployeePost($email, ['joining_date' => '', 'salary' => '20000'])
        );

        $id = $this->idByEmail($email);
        self::assertNotNull($id);
        self::assertSame(self::day(0), Database::instance()->value(
            'SELECT effective_from FROM employee_salaries WHERE user_id = :id',
            ['id' => $id]
        ));
    }

    public function testBothCreationPathsWithoutASalaryWriteNoSalaryRow(): void
    {
        foreach ([[EmployeeController::class, []], [UserController::class, ['role' => 'employee']]] as $i => $path) {
            [$controller, $extra] = $path;
            $email = 'ems-nosalary-' . $i . '@test.local';
            self::assertSame(302, ControllerActionRunner::run(
                $controller,
                'store',
                $this->sessionA(),
                $this->newEmployeePost($email, $extra)
            )['status']);

            $id = $this->idByEmail($email);
            self::assertNotNull($id, 'the employee is still created');
            self::assertSame(0, $this->salaryCount($id));
        }
    }

    public function testAnInvalidStartingSalaryCreatesNeitherTheEmployeeNorASalary(): void
    {
        foreach ([[EmployeeController::class, []], [UserController::class, ['role' => 'employee']]] as $i => $path) {
            [$controller, $extra] = $path;
            $email = 'ems-badsalary-' . $i . '@test.local';
            $result = ControllerActionRunner::run(
                $controller,
                'store',
                $this->sessionA(),
                $this->newEmployeePost($email, $extra + ['salary' => '12.345'])
            );

            self::assertSame(302, $result['status']);
            self::assertArrayHasKey('salary', $result['session']['_flash']['errors'] ?? []);
            self::assertNull($this->idByEmail($email));
        }
    }

    /** @param array<string,mixed> $data */
    private function render(string $template, array $data): string
    {
        $user = Database::instance()->first('SELECT * FROM users WHERE id = :id', ['id' => $_SESSION['_auth_user_id']]);

        return View::capture($template, $data + [
            'authUser' => $user,
            'flash' => ['success' => null, 'error' => null],
            'errors' => [],
            'title' => 'Test',
            'nav' => '',
        ]);
    }

    /**
     * The shared profile partial is rendered on the employee's own portal:
     * the salary card lives only on the admin page, so the amount never
     * reaches the employee even when one is recorded.
     */
    public function testThePortalNeverShowsTheSalaryButTheAdminPageDoes(): void
    {
        $salaries = new EmployeeSalary();
        $salaries->record($this->employeeAId, '87654.32', self::day(-1), 'EMS secret note');
        $salaries->record($this->employeeAId, '91234.56', self::day(15), null);

        $_SESSION = ['_auth_user_id' => $this->employeeAId, '_active_branch_id' => $this->branchAId];
        $portal = $this->render('pages/portal/home', [
            'nav' => 'portal',
            'profile' => (new EmployeeProfile())->findWithUser($this->employeeAId),
            'documents' => (new EmployeeDocument())->recent(5),
        ]);

        self::assertStringContainsString('Technician', $portal, 'the profile itself is rendered');
        foreach (['87,654.32', '87654.32', '91,234.56', '91234.56', 'EMS secret note', 'Salary'] as $secret) {
            self::assertStringNotContainsString($secret, $portal);
        }

        $this->asBranchA();
        $employee = (new EmployeeProfile())->findWithUser($this->employeeAId);
        self::assertNotNull($employee);
        self::assertArrayNotHasKey('amount', $employee, 'findWithUser() never carries salary');
        $admin = $this->render('pages/employees/show', [
            'employee' => $employee,
            'currentSalary' => $salaries->current($this->employeeAId),
            'upcomingSalary' => $salaries->upcoming($this->employeeAId),
            'salaryHistory' => $salaries->history($this->employeeAId),
        ]);

        self::assertStringContainsString('87,654.32', $admin);
        self::assertStringContainsString('91,234.56', $admin);
        self::assertStringContainsString('EMS secret note', $admin);
        $action = url('/employees/' . $this->employeeAId . '/salary');
        self::assertStringContainsString('action="' . $action . '"', $admin);
        self::assertStringContainsString('<label for="salary-amount"', $admin);
        self::assertStringContainsString('value="' . self::day(0) . '"', $admin, 'effective from defaults to today');
    }

    public function testAdminPageShowsEmptyStatesAndKeepsFailedInput(): void
    {
        $_SESSION['_old'] = ['amount' => '<12.345>', 'effective_from' => '2026-02-30', 'note' => 'Kept'];
        $employee = (new EmployeeProfile())->findWithUser($this->employeeAId);
        self::assertNotNull($employee);

        $html = $this->render('pages/employees/show', [
            'employee' => $employee,
            'currentSalary' => null,
            'upcomingSalary' => null,
            'salaryHistory' => [],
            'errors' => ['amount' => ['The amount must be an amount in digits.']],
        ]);

        self::assertStringContainsString('No salary recorded', $html);
        self::assertStringContainsString('None scheduled', $html);
        self::assertStringContainsString('No salary recorded yet.', $html);
        self::assertStringContainsString('id="salary-amount-error" class="error"', $html);
        self::assertStringContainsString('value="&lt;12.345&gt;"', $html, 'old() input is kept and escaped');
        self::assertStringContainsString('value="2026-02-30"', $html);
    }

    public function testDeletingABranchWithSalaryRowsSucceeds(): void
    {
        $salaries = new EmployeeSalary();
        $salaries->record($this->employeeAId, '40000', self::day(-10), null);
        $salaries->record($this->employeeAId, '45000', self::day(10), null);
        $superAdminId = $this->insertUser('ems-super@test.local', 'super_admin', null, self::SUPER_ADMIN_PASSWORD);
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

        $db = Database::instance();
        self::assertSame(302, $status);
        self::assertSame(0, (int) $db->value(
            'SELECT COUNT(*) FROM branches WHERE id = :id',
            ['id' => $this->branchAId]
        ));
        self::assertSame(0, (int) $db->value(
            'SELECT COUNT(*) FROM employee_salaries WHERE branch_id = :id',
            ['id' => $this->branchAId]
        ));
        self::assertSame(0, (int) $db->value('SELECT COUNT(*) FROM users WHERE id = :id', ['id' => $this->adminAId]));
    }
}
