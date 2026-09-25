<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Services\Audit;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for a bug found via a real production incident: a browser
 * held a session from before a full local dataset re-import, so its
 * _active_branch_id pointed at a branch id that no longer existed at all
 * (not merely locked). Auth::requireActiveBranch() correctly detected the
 * branch was gone and called Auth::logout() to end the session — which
 * itself calls Audit::record(), which trusted that same stale branch id and
 * hit audit_log's fk_audit_branch foreign key, throwing a PDOException that
 * took the whole request down instead of just logging the user out.
 *
 * The same situation arises for real without a data re-import too: a super
 * admin can delete a branch (BranchController::destroy()) while one of its
 * users still has an open session.
 */
final class AuditStaleBranchTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testRecordFallsBackToNoBranchWhenTheSessionsBranchNoLongerExists(): void
    {
        $_SESSION['_auth_user_id'] = 1;
        // An id guaranteed not to exist as a real row.
        $_SESSION['_active_branch_id'] = 999999999;

        Audit::record('auth.logout', 'users', 1, null, null);

        $row = Database::instance()->first(
            "SELECT * FROM audit_log WHERE action = 'auth.logout' AND entity_id = 1 ORDER BY id DESC LIMIT 1"
        );

        self::assertNotNull($row, 'the audit row must still be written, just without the dangling branch_id');
        self::assertNull($row['branch_id']);

        Database::instance()->delete('audit_log', 'id = :id', ['id' => $row['id']]);
    }
}
