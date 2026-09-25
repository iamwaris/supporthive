<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Auth;
use App\Core\Database;
use PHPUnit\Framework\TestCase;
use Tests\Support\BranchFixture;

/**
 * Login and logout are now recorded to the audit trail (owner's request),
 * not just the security log file — so they show up in the Audit Log viewer
 * (M7-4) alongside every other action on a user. Auth::login() sets
 * branch_id into the session before Audit::record() runs, and
 * Auth::logout() records before Session::destroy() — both are asserted
 * here since either one running in the wrong order would silently produce
 * a branch_id = NULL row.
 */
final class LoginLogoutAuditTest extends TestCase
{
    private const PASSWORD = 'a-fine-password-123';

    private int $branchId;
    private int $userId;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->cleanUp();

        $db = Database::instance();
        $this->branchId = BranchFixture::create('LLA');
        $this->userId = $db->insert('users', [
            'name' => 'LLA Tester',
            'email' => 'lla-tester@test.local',
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
        $db->delete('users', 'email = :e', ['e' => 'lla-tester@test.local']);
        $db->run(
            'DELETE a FROM audit_log a JOIN branches b ON b.id = a.branch_id WHERE b.name LIKE :n',
            ['n' => 'LLA%']
        );
        $db->delete('branches', 'name LIKE :n', ['n' => 'LLA%']);
    }

    public function testLoginRecordsAnAuditRowStampedWithTheUsersBranch(): void
    {
        self::assertTrue(Auth::attempt('lla-tester@test.local', self::PASSWORD));

        $row = Database::instance()->first(
            "SELECT * FROM audit_log WHERE action = 'auth.login' AND entity_id = :id ORDER BY id DESC LIMIT 1",
            ['id' => $this->userId]
        );

        self::assertNotNull($row, 'a successful login must write an audit row');
        self::assertSame($this->branchId, (int) $row['branch_id']);
        self::assertSame($this->userId, (int) $row['user_id']);
    }

    public function testLogoutRecordsAnAuditRowBeforeTheSessionIsDestroyed(): void
    {
        Auth::attempt('lla-tester@test.local', self::PASSWORD);
        Auth::logout();

        $row = Database::instance()->first(
            "SELECT * FROM audit_log WHERE action = 'auth.logout' AND entity_id = :id ORDER BY id DESC LIMIT 1",
            ['id' => $this->userId]
        );

        self::assertNotNull($row, 'logout must write an audit row while the session is still live');
        self::assertSame(
            $this->branchId,
            (int) $row['branch_id'],
            'branch_id must be captured before Session::destroy() clears it'
        );
    }

    public function testFailedLoginWritesNoAuditRow(): void
    {
        self::assertFalse(Auth::attempt('lla-tester@test.local', 'wrong-password'));

        $count = Database::instance()->value(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'auth.login' AND entity_id = :id",
            ['id' => $this->userId]
        );

        self::assertSame(0, (int) $count, 'only a successful login is an audited event');
    }
}
