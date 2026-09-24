<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Models\User;
use PHPUnit\Framework\TestCase;
use Tests\Support\BranchFixture;

/**
 * In-app user management (App\Models\User, App\Controllers\UserController).
 *
 * The security-critical guarantee is the same one BranchIsolationTest
 * establishes for every other table: App\Core\Model's branch-scoped find()
 * and updateById() mean an admin in Branch A cannot reach, by id, a user
 * that belongs to Branch B — there is no separate "is this yours" check to
 * forget, the query itself cannot return the other branch's row.
 */
final class UserManagementTest extends TestCase
{
    private int $branchAId;
    private int $branchBId;
    private int $adminAId;
    private int $adminBId;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->cleanUp();

        $db = Database::instance();

        $this->branchAId = BranchFixture::create('UM-A');
        $this->branchBId = BranchFixture::create('UM-B');

        $this->adminAId = $db->insert('users', [
            'name' => 'UM Admin A',
            'email' => 'um-admin-a@test.local',
            'password_hash' => password_hash('unused-in-this-test', PASSWORD_DEFAULT),
            'role' => 'admin',
            'branch_id' => $this->branchAId,
            'status' => 'active',
        ]);
        $this->adminBId = $db->insert('users', [
            'name' => 'UM Admin B',
            'email' => 'um-admin-b@test.local',
            'password_hash' => password_hash('unused-in-this-test', PASSWORD_DEFAULT),
            'role' => 'admin',
            'branch_id' => $this->branchBId,
            'status' => 'active',
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
        $db->delete('users', 'email LIKE :e', ['e' => 'um-%@test.local']);
        $db->delete('branches', 'name LIKE :n', ['n' => 'UM-%']);
    }

    private function asBranchA(): void
    {
        $_SESSION['_auth_user_id'] = $this->adminAId;
        $_SESSION['_active_branch_id'] = $this->branchAId;
    }

    private function asBranchB(): void
    {
        $_SESSION['_auth_user_id'] = $this->adminBId;
        $_SESSION['_active_branch_id'] = $this->branchBId;
    }

    public function testFindNeverReachesAnotherBranchsUserById(): void
    {
        $this->asBranchA();
        self::assertNotNull((new User())->find($this->adminAId), 'Branch A must find its own user');
        self::assertNull(
            (new User())->find($this->adminBId),
            'Branch A must not be able to resolve Branch B\'s user id'
        );
    }

    public function testUpdateAnotherBranchsUserIdAffectsNothing(): void
    {
        $this->asBranchA();
        $updated = (new User())->updateById($this->adminBId, ['name' => 'tampered']);
        self::assertSame(0, $updated, 'updating Branch B\'s user from a Branch A session must affect nothing');

        $stillIntact = Database::instance()->value('SELECT name FROM users WHERE id = :id', ['id' => $this->adminBId]);
        self::assertNotSame('tampered', $stillIntact);
    }

    public function testCreateStampsTheCurrentBranch(): void
    {
        $this->asBranchA();
        $id = (new User())->create([
            'name' => 'UM New User',
            'email' => 'um-new-user@test.local',
            'password_hash' => password_hash('unused', PASSWORD_DEFAULT),
            'role' => 'partner',
            'status' => 'active',
            'must_change_password' => 1,
        ]);

        $branchId = (int) Database::instance()->value('SELECT branch_id FROM users WHERE id = :id', ['id' => $id]);
        self::assertSame($this->branchAId, $branchId);
    }

    public function testAllOrderedOnlyListsUsersInTheCurrentBranch(): void
    {
        $this->asBranchA();
        $names = array_map(
            static fn (array $row): string => (string) $row['name'],
            (new User())->allOrdered()
        );

        self::assertContains('UM Admin A', $names);
        self::assertNotContains('UM Admin B', $names);
    }

    public function testEmailUniquenessIsCheckedAcrossTheWholeAppNotJustOneBranch(): void
    {
        $this->asBranchB();
        // Branch A's admin email must still read as taken from a Branch B
        // session — the schema's uq_users_email is a global constraint, and
        // the pre-save check has to match it or a create() would 500 on the
        // duplicate-key error instead of showing a field error.
        self::assertTrue((new User())->emailExists('um-admin-a@test.local'));
        self::assertFalse((new User())->emailExists('nobody-with-this-email@test.local'));
    }
}
