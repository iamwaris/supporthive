<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\EmployeeController;
use App\Core\Auth;
use App\Core\Database;
use App\Core\RememberMe;
use App\Models\PayrollRun;
use App\Services\Payroll\PayrollImport;
use PHPUnit\Framework\TestCase;
use Tests\Support\BranchFixture;
use Tests\Support\ControllerActionRunner;
use Tests\Support\RouteDispatch;

/**
 * Disabling an employee (EMP-DISABLE): admin-only and branch-scoped, the
 * login and every remember-me token stop working, nothing is deleted, the
 * list hides disabled employees unless asked, and a disabled employee's
 * Employee ID is refused by the salary sheet import.
 */
final class EmployeeDisableTest extends TestCase
{
    private const PASSWORD = 'dis-employee-password-1';
    private const CSRF = 'dis-csrf-token-0123456789abcdef0123456789abcdef0123456789abcdef';

    private int $branchAId;
    private int $branchBId;
    private int $adminAId;
    private int $adminBId;
    private int $partnerAId;
    private int $employeeId;
    private int $colleagueId;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->cleanUp();

        $this->branchAId = BranchFixture::create('DIS-A');
        $this->branchBId = BranchFixture::create('DIS-B');
        $this->adminAId = $this->insertUser('dis-admin-a@test.local', 'admin', $this->branchAId);
        $this->adminBId = $this->insertUser('dis-admin-b@test.local', 'admin', $this->branchBId);
        $this->partnerAId = $this->insertUser('dis-partner-a@test.local', 'partner', $this->branchAId);
        $this->employeeId = $this->insertEmployee('dis-emp@test.local', 'Dis Employee', 'DIS-1');
        $this->colleagueId = $this->insertEmployee('dis-colleague@test.local', 'Dis Colleague', 'DIS-2');
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
            'DELETE r FROM remember_tokens r JOIN users u ON u.id = r.user_id WHERE u.email LIKE :e',
            ['e' => 'dis-%@test.local']
        );
        $db->run(
            'DELETE a FROM audit_log a JOIN branches b ON b.id = a.branch_id WHERE b.name LIKE :n',
            ['n' => 'DIS-%']
        );
        $db->run(
            'DELETE a FROM audit_log a JOIN users u ON u.id = a.user_id WHERE u.email LIKE :e',
            ['e' => 'dis-%@test.local']
        );
        $db->run(
            'DELETE p FROM employee_profiles p JOIN branches b ON b.id = p.branch_id WHERE b.name LIKE :n',
            ['n' => 'DIS-%']
        );
        $db->delete('users', 'email LIKE :e', ['e' => 'dis-%@test.local']);
        $db->delete('branches', 'name LIKE :n', ['n' => 'DIS-%']);
    }

    private function insertUser(string $email, string $role, int $branchId, string $name = ''): int
    {
        return Database::instance()->insert('users', [
            'name' => $name !== '' ? $name : 'Dis ' . $email,
            'email' => $email,
            'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
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

    /** @return array<string,mixed> */
    private function session(int $userId, int $branchId): array
    {
        return [
            '_auth_user_id' => $userId,
            '_active_branch_id' => $branchId,
            '_csrf_token' => self::CSRF,
            '_csrf_issued_at' => time(),
        ];
    }

    private function accountStatus(int $userId): string
    {
        return (string) Database::instance()->value('SELECT status FROM users WHERE id = :id', ['id' => $userId]);
    }

    /** @return array{status:int,body:string,session:array<string,mixed>} */
    private function disableAs(int $userId, int $branchId, string $to = 'suspended'): array
    {
        return ControllerActionRunner::run(
            EmployeeController::class,
            'toggleStatus',
            $this->session($userId, $branchId),
            ['to' => $to],
            [],
            'POST',
            ['id' => (string) $this->employeeId]
        );
    }

    /**
     * Through the real route table, so the can:administer gate itself is
     * what refuses.
     *
     * @return array{status:int,body:string,session:array<string,mixed>}
     */
    private function dispatch(string $method, string $uri, int $userId, array $post = []): array
    {
        return ControllerActionRunner::run(
            RouteDispatch::class,
            'dispatch',
            $this->session($userId, $this->branchAId),
            $post + ['_csrf' => self::CSRF],
            [],
            $method,
            ['uri' => $uri]
        );
    }

    public function testAnAdminDisablesTheEmployeeWhoThenCannotSignInByPasswordOrRememberMe(): void
    {
        RememberMe::issue($this->employeeId, 'Phone');
        RememberMe::issue($this->employeeId, 'Laptop');
        self::assertTrue(Auth::mayStillSignIn($this->employeeId));

        self::assertSame(302, $this->disableAs($this->adminAId, $this->branchAId)['status']);

        self::assertSame('suspended', $this->accountStatus($this->employeeId));
        self::assertSame(0, (int) Database::instance()->value(
            'SELECT COUNT(*) FROM remember_tokens WHERE user_id = :id',
            ['id' => $this->employeeId]
        ), 'every remember-me token is revoked');
        self::assertFalse(Auth::mayStillSignIn($this->employeeId), 'a remember-me sign-in is refused');
        self::assertFalse(Auth::attempt('dis-emp@test.local', self::PASSWORD), 'a password sign-in is refused');
        // Nothing about the employee is deleted.
        self::assertSame(1, (int) Database::instance()->value(
            'SELECT COUNT(*) FROM employee_profiles WHERE user_id = :id',
            ['id' => $this->employeeId]
        ));
    }

    public function testEnablingRestoresSignIn(): void
    {
        $this->disableAs($this->adminAId, $this->branchAId);
        self::assertSame(302, $this->disableAs($this->adminAId, $this->branchAId, 'active')['status']);

        self::assertSame('active', $this->accountStatus($this->employeeId));
        self::assertTrue(Auth::attempt('dis-emp@test.local', self::PASSWORD));
    }

    public function testAnotherBranchsAdminGetsA404AndChangesNothing(): void
    {
        self::assertSame(404, $this->disableAs($this->adminBId, $this->branchBId)['status']);
        self::assertSame('active', $this->accountStatus($this->employeeId));
    }

    public function testNonAdminsAreRefusedByTheRoute(): void
    {
        $uri = '/employees/' . $this->employeeId . '/toggle';

        foreach ([$this->partnerAId, $this->colleagueId] as $userId) {
            $result = $this->dispatch('POST', $uri, $userId, ['to' => 'suspended']);
            self::assertSame(403, $result['status'], 'user ' . $userId);
        }
        self::assertSame('active', $this->accountStatus($this->employeeId));

        self::assertSame(302, $this->dispatch('POST', $uri, $this->adminAId, ['to' => 'suspended'])['status']);
        self::assertSame('suspended', $this->accountStatus($this->employeeId));
    }

    public function testTheListShowsActiveByDefaultAndDisabledOrAllOnRequest(): void
    {
        $this->disableAs($this->adminAId, $this->branchAId);

        $default = $this->dispatch('GET', '/employees', $this->adminAId)['body'];
        self::assertStringContainsString('Dis Colleague', $default);
        self::assertStringNotContainsString('Dis Employee', $default);

        $disabled = $this->dispatch('GET', '/employees?status=suspended', $this->adminAId)['body'];
        self::assertStringContainsString('Dis Employee', $disabled);
        self::assertStringNotContainsString('Dis Colleague', $disabled);
        self::assertMatchesRegularExpression('#<span class="badge-mute">\s*Disabled\s*</span>#', $disabled);

        $all = $this->dispatch('GET', '/employees?status=all', $this->adminAId)['body'];
        self::assertStringContainsString('Dis Employee', $all);
        self::assertStringContainsString('Dis Colleague', $all);
    }

    public function testTheSalarySheetImportRefusesADisabledEmployeeAndDoesNotListThemAsMissing(): void
    {
        $this->disableAs($this->adminAId, $this->branchAId);
        $_SESSION = $this->session($this->adminAId, $this->branchAId);
        $employees = (new PayrollRun())->branchEmployees();

        $result = PayrollImport::validate([
            1 => ['EmpID', 'Salary', 'Absent', 'WorkingDays'],
            2 => ['DIS-1', '30000', '0', '26'],
        ], $employees);
        self::assertSame(
            [['row' => 2, 'employee_code' => 'DIS-1', 'message' => 'Employee DIS-1 is disabled.']],
            $result->errors
        );

        $withoutThem = PayrollImport::validate([
            1 => ['EmpID', 'Salary', 'Absent', 'WorkingDays'],
            2 => ['DIS-2', '30000', '0', '26'],
        ], $employees);
        self::assertTrue($withoutThem->passes());
        self::assertSame([], $withoutThem->missing, 'a disabled employee is not "missing from the file"');
    }
}
