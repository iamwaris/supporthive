<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Auth;
use App\Core\Database;
use PHPUnit\Framework\TestCase;
use Tests\Support\BranchFixture;

/**
 * Any account created (or reset) with must_change_password=1 — every path
 * through scripts/create-user.php — must be forced through /account/password
 * before it can reach anything else (Auth::requireLogin(), which this
 * doesn't exercise directly since it calls Http::redirect()/exit — that
 * path is covered by manual verification instead). What's asserted here is
 * the part that is: the flag defaults to off for existing rows, and
 * Auth::updatePassword() actually clears it and rotates the credential.
 */
final class ForcedPasswordChangeTest extends TestCase
{
    private int $branchId;
    private int $userId;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->cleanUp();

        $this->branchId = BranchFixture::create('PW');
        $this->userId = Database::instance()->insert('users', [
            'name' => 'Password Tester',
            'email' => 'password-tester@test.local',
            'password_hash' => password_hash('temporary-password-123', PASSWORD_DEFAULT),
            'role' => 'admin',
            'branch_id' => $this->branchId,
            'status' => 'active',
            'must_change_password' => 1,
        ]);
        $_SESSION['_auth_user_id'] = $this->userId;
        $_SESSION['_active_branch_id'] = $this->branchId;
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        $_SESSION = [];
    }

    private function cleanUp(): void
    {
        $db = Database::instance();
        $db->delete('users', 'email = :e', ['e' => 'password-tester@test.local']);
        $db->delete('branches', 'name LIKE :n', ['n' => 'PW %']);
    }

    public function testNewColumnDefaultsToOffForExistingRows(): void
    {
        // A row inserted with no must_change_password key at all — the
        // shape every pre-existing account has after the migration — must
        // not be silently forced into the flow.
        $db = Database::instance();
        $id = $db->insert('users', [
            'name' => 'Default Flag Tester',
            'email' => 'default-flag-tester@test.local',
            'password_hash' => password_hash('irrelevant', PASSWORD_DEFAULT),
            'role' => 'admin',
            'branch_id' => $this->branchId,
            'status' => 'active',
        ]);

        $flag = $db->value('SELECT must_change_password FROM users WHERE id = :id', ['id' => $id]);
        self::assertSame(0, (int) $flag, 'a row with no explicit flag must default to not-forced');

        $db->delete('users', 'id = :id', ['id' => $id]);
    }

    public function testUpdatePasswordClearsTheFlagAndChangesTheHash(): void
    {
        $before = Database::instance()->first(
            'SELECT password_hash, must_change_password FROM users WHERE id = :id',
            ['id' => $this->userId]
        );
        self::assertSame(1, (int) $before['must_change_password']);

        Auth::updatePassword('a-brand-new-strong-password-456');

        $after = Database::instance()->first(
            'SELECT password_hash, must_change_password FROM users WHERE id = :id',
            ['id' => $this->userId]
        );

        self::assertSame(0, (int) $after['must_change_password'], 'the flag must be cleared after a change');
        self::assertNotSame((string) $before['password_hash'], (string) $after['password_hash']);
        self::assertTrue(
            password_verify('a-brand-new-strong-password-456', (string) $after['password_hash']),
            'the new password must actually verify against the stored hash'
        );
        self::assertFalse(
            password_verify('temporary-password-123', (string) $after['password_hash']),
            'the old password must no longer work'
        );
    }
}
