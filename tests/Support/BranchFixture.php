<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Database;

/**
 * Every branch-scoped table now requires branch_id NOT NULL, and every read
 * goes through Auth::branchId() (session `_active_branch_id`). Tests that
 * built fixtures directly against the database (rather than through the
 * HTTP layer, which is what normally sets that session key at login) need
 * both: a real row in `branches`, and that id put into the session
 * themselves. This is the one place that creates the row, so every test
 * fixture uses the same shape.
 */
final class BranchFixture
{
    public static function create(string $namePrefix): int
    {
        return Database::instance()->insert('branches', [
            'name' => $namePrefix . ' Branch ' . bin2hex(random_bytes(4)),
            'is_active' => 1,
        ]);
    }
}
