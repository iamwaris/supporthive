<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Auth;
use App\Core\Database;
use PHPUnit\Framework\TestCase;
use Tests\Support\BranchFixture;

/**
 * A super admin locking a branch (branches.is_active = 0) must stop that
 * branch's users signing in — Auth::attempt() checks it — and must end any
 * session already open on the very next request, not just block future
 * logins (Auth::requireActiveBranch(), exercised via manual verification
 * since it calls Http::redirect()/exit and cannot run inside PHPUnit).
 */
final class BranchLockTest extends TestCase
{
    private int $branchId;
    private int $userId;
    private const PASSWORD = 'a-real-password-000111';

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->cleanUp();

        $this->branchId = BranchFixture::create('BL');
        $this->userId = Database::instance()->insert('users', [
            'name' => 'Lock Tester',
            'email' => 'lock-tester@test.local',
            'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
            'role' => 'admin',
            'branch_id' => $this->branchId,
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
        $db->delete('users', 'email = :e', ['e' => 'lock-tester@test.local']);
        $db->delete('branches', 'name LIKE :n', ['n' => 'BL %']);
    }

    public function testCorrectCredentialsSucceedWhileTheBranchIsActive(): void
    {
        self::assertTrue(Auth::attempt('lock-tester@test.local', self::PASSWORD));
    }

    public function testCorrectCredentialsAreRejectedOnceTheBranchIsLocked(): void
    {
        Database::instance()->update('branches', ['is_active' => 0], 'id = :id', ['id' => $this->branchId]);

        self::assertFalse(
            Auth::attempt('lock-tester@test.local', self::PASSWORD),
            'a correct password must still fail to sign in once the branch is locked'
        );
    }

    public function testUnlockingRestoresTheAbilityToSignIn(): void
    {
        $db = Database::instance();
        $db->update('branches', ['is_active' => 0], 'id = :id', ['id' => $this->branchId]);
        self::assertFalse(Auth::attempt('lock-tester@test.local', self::PASSWORD));

        $db->update('branches', ['is_active' => 1], 'id = :id', ['id' => $this->branchId]);
        self::assertTrue(Auth::attempt('lock-tester@test.local', self::PASSWORD));
    }

    /**
     * A super admin (branch_id NULL) must never be affected by any branch's
     * lock state — there is no branch to check.
     */
    public function testASuperAdminWithNoBranchIsUnaffectedByAnyLock(): void
    {
        $db = Database::instance();
        $superId = $db->insert('users', [
            'name' => 'Lock Super',
            'email' => 'lock-super@test.local',
            'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
            'role' => 'super_admin',
            'branch_id' => null,
            'status' => 'active',
        ]);

        $db->update('branches', ['is_active' => 0], 'id = :id', ['id' => $this->branchId]);

        self::assertTrue(Auth::attempt('lock-super@test.local', self::PASSWORD));

        $db->delete('users', 'id = :id', ['id' => $superId]);
    }
}
