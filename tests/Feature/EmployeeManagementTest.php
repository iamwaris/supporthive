<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\EmployeeController;
use App\Controllers\UserController;
use App\Core\Auth;
use App\Core\Database;
use App\Models\EmployeeProfile;
use App\Models\User;
use PHPUnit\Framework\TestCase;
use Tests\Support\BranchFixture;
use Tests\Support\ControllerActionRunner;

/**
 * Employee management (App\Models\EmployeeProfile, EmployeeController).
 *
 * The security-critical guarantee: findWithUser() is the one ownership gate.
 * From Branch A it resolves Branch A's employees and nothing else — not
 * Branch B's employees, and not Branch A's own admin or partner logins — so
 * every controller action that takes an id 404s on all of those.
 */
final class EmployeeManagementTest extends TestCase
{
    private int $branchAId;
    private int $branchBId;
    private int $adminAId;
    private int $adminBId;
    private int $partnerAId;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->cleanUp();

        $this->branchAId = BranchFixture::create('EMT-A');
        $this->branchBId = BranchFixture::create('EMT-B');
        $this->adminAId = $this->insertUser('emt-admin-a@test.local', 'admin', $this->branchAId);
        $this->adminBId = $this->insertUser('emt-admin-b@test.local', 'admin', $this->branchBId);
        $this->partnerAId = $this->insertUser('emt-partner-a@test.local', 'partner', $this->branchAId);
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
            'DELETE a FROM audit_log a JOIN users u ON u.id = a.entity_id
             WHERE a.entity_type = :t AND u.email LIKE :e',
            ['t' => 'users', 'e' => 'emt-%@test.local']
        );
        $db->run(
            'DELETE a FROM audit_log a JOIN branches b ON b.id = a.branch_id WHERE b.name LIKE :n',
            ['n' => 'EMT-%']
        );
        $db->delete('users', 'email LIKE :e', ['e' => 'emt-%@test.local']);
        $db->delete('branches', 'name LIKE :n', ['n' => 'EMT-%']);
    }

    private function insertUser(string $email, string $role, int $branchId, string $status = 'active'): int
    {
        return Database::instance()->insert('users', [
            'name' => 'EMT ' . $email,
            'email' => $email,
            'password_hash' => password_hash('unused-in-this-test', PASSWORD_DEFAULT),
            'role' => $role,
            'branch_id' => $branchId,
            'status' => $status,
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

    private function createEmployee(string $email, string $designation = 'Support Agent'): int
    {
        return (new EmployeeProfile())->createWithLogin([
            'name' => 'EMT Employee ' . $email,
            'email' => $email,
            'phone' => '+92 300 1234567',
            'designation' => $designation,
            'joining_date' => '2026-01-15',
        ], password_hash('unused-in-this-test', PASSWORD_DEFAULT));
    }

    /** @param array<string,string> $post */
    private function act(
        string $method,
        array $post = [],
        ?int $id = null,
        string $class = EmployeeController::class
    ): int
    {
        $result = ControllerActionRunner::run(
            $class,
            $method,
            $this->sessionA(),
            $post,
            [],
            $post === [] && in_array($method, ['show', 'edit'], true) ? 'GET' : 'POST',
            $id === null ? [] : ['id' => (string) $id]
        );

        return $result['status'];
    }

    /** @return array<string,string> */
    private function validPost(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'EMT New Hire',
            'email' => 'emt-new-hire@test.local',
            'phone' => '0300-1234567',
            'designation' => 'Field Technician',
            'joining_date' => '2026-10-01',
        ];
    }

    public function testStoreCreatesAnEmployeeLoginAndProfileInTheAdminsBranch(): void
    {
        self::assertSame(302, $this->act('store', $this->validPost(['email' => ' EMT-New-Hire@test.local '])));

        $user = Database::instance()->first(
            'SELECT u.*, p.branch_id AS profile_branch_id, p.designation, p.phone, p.joining_date
             FROM users u JOIN employee_profiles p ON p.user_id = u.id WHERE u.email = :e',
            ['e' => 'emt-new-hire@test.local']
        );

        self::assertNotNull($user, 'store must create the login and its profile, with the email normalised');
        self::assertSame('employee', $user['role']);
        self::assertSame('active', $user['status']);
        self::assertSame(1, (int) $user['must_change_password'], 'a new employee must change the temporary password');
        self::assertSame($this->branchAId, (int) $user['branch_id']);
        self::assertSame($this->branchAId, (int) $user['profile_branch_id']);
        self::assertSame('Field Technician', $user['designation']);
        self::assertSame('2026-10-01', $user['joining_date']);

        $audited = (int) Database::instance()->value(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'employee.created' AND entity_id = :id",
            ['id' => $user['id']]
        );
        self::assertSame(1, $audited);
    }

    public function testInvalidStoreInsertsNothing(): void
    {
        $invalid = [
            ['joining_date' => '2026-02-30'],
            ['phone' => 'call me maybe'],
            ['designation' => ''],
            ['email' => 'not-an-email'],
        ];

        foreach ($invalid as $override) {
            self::assertSame(302, $this->act('store', $this->validPost($override)));
        }

        self::assertSame(0, (int) Database::instance()->value(
            'SELECT COUNT(*) FROM users WHERE email = :e',
            ['e' => 'emt-new-hire@test.local']
        ));
    }

    public function testADuplicateEmailAnywhereInTheAppIsRejectedWithoutWriting(): void
    {
        // Branch B's admin: email uniqueness is global, not per branch.
        self::assertSame(302, $this->act('store', $this->validPost(['email' => 'emt-admin-b@test.local'])));

        self::assertSame(0, (int) Database::instance()->value(
            "SELECT COUNT(*) FROM users WHERE role = 'employee' AND branch_id = :b",
            ['b' => $this->branchAId]
        ));
    }

    public function testFindWithUserOnlyResolvesThisBranchsEmployees(): void
    {
        $this->asBranchB();
        $employeeB = $this->createEmployee('emt-emp-b@test.local');

        $this->asBranchA();
        $employeeA = $this->createEmployee('emt-emp-a@test.local');
        $profiles = new EmployeeProfile();

        $found = $profiles->findWithUser($employeeA);
        self::assertNotNull($found);
        self::assertStringStartsWith('EMT-A', (string) $found['branch_name']);

        self::assertNull($profiles->findWithUser($employeeB), 'another branch\'s employee must not resolve');
        self::assertNull($profiles->findWithUser($this->adminAId), 'an admin is not an employee');
        self::assertNull($profiles->findWithUser($this->partnerAId), 'a partner is not an employee');
    }

    public function testEveryIdActionReturns404ForAnAdminOrAnotherBranchsEmployee(): void
    {
        $this->asBranchB();
        $employeeB = $this->createEmployee('emt-emp-b@test.local');

        foreach ([$this->adminAId, $this->partnerAId, $employeeB] as $id) {
            self::assertSame(404, $this->act('show', [], $id), "show {$id}");
            self::assertSame(404, $this->act('edit', [], $id), "edit {$id}");
            self::assertSame(404, $this->act('update', $this->validPost(['name' => 'tampered']), $id), "update {$id}");
            self::assertSame(404, $this->act('toggleStatus', ['x' => '1'], $id), "toggle {$id}");
            self::assertSame(404, $this->act('resetPassword', ['x' => '1'], $id), "reset {$id}");
        }

        $db = Database::instance();
        self::assertSame('active', $db->value('SELECT status FROM users WHERE id = :id', ['id' => $employeeB]));
        self::assertNotSame('tampered', $db->value('SELECT name FROM users WHERE id = :id', ['id' => $this->adminAId]));
    }

    public function testUpdateChangesTheLoginAndTheProfileTogether(): void
    {
        $this->asBranchA();
        $id = $this->createEmployee('emt-emp-a@test.local');

        self::assertSame(302, $this->act('update', $this->validPost([
            'name' => 'EMT Renamed',
            'email' => 'emt-renamed@test.local',
            'phone' => '',
            'designation' => 'Team Lead',
            'joining_date' => '',
        ]), $id));

        $this->asBranchA();
        $row = (new EmployeeProfile())->findWithUser($id);
        self::assertNotNull($row);
        self::assertSame('EMT Renamed', $row['name']);
        self::assertSame('emt-renamed@test.local', $row['email']);
        self::assertNull($row['phone']);
        self::assertSame('Team Lead', $row['designation']);
        self::assertNull($row['joining_date']);
    }

    public function testToggleDeactivatesThenReactivatesAndResetForcesAPasswordChange(): void
    {
        $this->asBranchA();
        $id = $this->createEmployee('emt-emp-a@test.local');
        Database::instance()->update('users', ['must_change_password' => 0], 'id = :id', ['id' => $id]);
        $status = static fn (): string => (string) Database::instance()->value(
            'SELECT status FROM users WHERE id = :id',
            ['id' => $id]
        );

        self::assertSame(302, $this->act('toggleStatus', ['x' => '1'], $id));
        self::assertSame('suspended', $status());
        self::assertSame(302, $this->act('toggleStatus', ['x' => '1'], $id));
        self::assertSame('active', $status());

        self::assertSame(302, $this->act('resetPassword', ['x' => '1'], $id));
        self::assertSame(1, (int) Database::instance()->value(
            'SELECT must_change_password FROM users WHERE id = :id',
            ['id' => $id]
        ));
    }

    /** The Users screen must not be a side door for renaming, suspending or resetting an employee. */
    public function testUserControllerTreatsAnEmployeeAsNotFound(): void
    {
        $this->asBranchA();
        $id = $this->createEmployee('emt-emp-a@test.local');

        $tamper = ['name' => 'tampered', 'role' => 'admin'];
        self::assertSame(404, $this->act('update', $tamper, $id, UserController::class));
        self::assertSame(404, $this->act('toggleStatus', ['x' => '1'], $id, UserController::class));
        self::assertSame(404, $this->act('resetPassword', ['x' => '1'], $id, UserController::class));

        $row = Database::instance()->first('SELECT name, role, status FROM users WHERE id = :id', ['id' => $id]);
        self::assertSame('employee', $row['role'] ?? null);
        self::assertSame('active', $row['status'] ?? null);

        $this->asBranchA();
        $listed = array_column((new User())->nonEmployeesOrdered(), 'id');
        self::assertNotContains($id, array_map('intval', $listed));
        self::assertContains($this->adminAId, array_map('intval', $listed));
    }

    public function testSearchMatchesEachFieldFiltersByStatusAndTreatsWildcardsLiterally(): void
    {
        $this->asBranchA();
        $engineer = $this->createEmployee('emt-engineer@test.local', 'Network Engineer');
        $cashier = $this->createEmployee('emt-cashier@test.local', 'Cashier 100%');
        Database::instance()->update('users', ['status' => 'suspended'], 'id = :id', ['id' => $cashier]);
        $this->asBranchB();
        $this->createEmployee('emt-other-branch@test.local', 'Network Engineer');

        $this->asBranchA();
        $profiles = new EmployeeProfile();
        $ids = static fn (array $rows): array => array_map(static fn (array $r): int => (int) $r['id'], $rows);

        self::assertSame([$engineer], $ids($profiles->search('network', null, 1, 20)), 'designation, this branch only');
        self::assertSame([$cashier], $ids($profiles->search('emt-cashier@', null, 1, 20)), 'email');
        self::assertSame([$cashier], $ids($profiles->search('100%', null, 1, 20)), 'a literal %');
        self::assertSame([], $ids($profiles->search('_', null, 1, 20)), '_ is not a wildcard');
        self::assertSame([$engineer, $cashier], $ids($profiles->search('300 123', null, 1, 20)), 'phone; active first');
        self::assertSame([$cashier], $ids($profiles->search(null, 'suspended', 1, 20)));
        self::assertSame(2, $profiles->searchCount(null, null));
        self::assertSame(1, $profiles->searchCount('EMT', 'active'));
    }

    /**
     * Deactivating must end a session that is already open (Auth::requireLogin()
     * re-reads status every request). The evidence is the auth.logout audit
     * row the forced sign-out writes; an active account writes none.
     */
    public function testRequireLoginEndsTheSessionOfADeactivatedAccount(): void
    {
        $this->asBranchA();
        $id = $this->createEmployee('emt-emp-a@test.local');
        Database::instance()->update(
            'users',
            ['status' => 'suspended', 'must_change_password' => 0],
            'id = :id',
            ['id' => $id]
        );

        $result = ControllerActionRunner::run(
            Auth::class,
            'requireLogin',
            ['_auth_user_id' => $id, '_active_branch_id' => $this->branchAId],
            [],
            [],
            'GET'
        );

        self::assertSame(302, $result['status']);
        self::assertSame(1, (int) Database::instance()->value(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'auth.logout' AND user_id = :id",
            ['id' => $id]
        ), 'a deactivated account must be signed out on its next request');
    }
}
