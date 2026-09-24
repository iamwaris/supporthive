<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/**
 * A login within the current branch.
 *
 * Deliberately excludes super_admin: that role has no branch_id, so it can
 * never be reached through this model's branch-scoped queries, and it is not
 * managed from here — Module "Branches" is its own screen. Every method here
 * only ever runs as an admin operating inside their one branch.
 */
final class User extends Model
{
    protected string $table = 'users';

    /** @var list<string> */
    protected array $fillable = ['name', 'email', 'password_hash', 'role', 'status', 'must_change_password'];

    /** @return list<array<string,mixed>> */
    public function allOrdered(): array
    {
        return $this->db()->all(
            'SELECT * FROM users WHERE branch_id = :branch ORDER BY status ASC, name ASC',
            ['branch' => $this->requireBranchId()]
        );
    }

    /** Email is unique across the whole app, not just this branch — the schema enforces it globally. */
    public function emailExists(string $email, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM users WHERE email = :email';
        $params = ['email' => $email];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }

        return (int) $this->db()->value($sql, $params) > 0;
    }
}
