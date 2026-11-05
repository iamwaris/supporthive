<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Model;

/**
 * A branch's one shared "new joiner" link (/join/{token}).
 *
 * Only sha256(token) is stored. The token itself exists in exactly one place
 * after generation: the URL the admin is shown once and copies. Anyone who
 * reads the database (a backup, an SQL injection elsewhere) gets nothing
 * that opens the form. Regenerating overwrites the hash, so the old URL
 * stops working at once; disabling keeps the hash but turns it off.
 */
final class EmployeeJoinLink extends Model
{
    /** 32 random bytes, hex: 64 characters, which the router's [A-Za-z0-9_-]+ placeholder accepts. */
    private const TOKEN_BYTES = 32;

    protected string $table = 'employee_join_links';

    /** @var list<string> */
    protected array $fillable = ['token_hash', 'is_active', 'blocked_count', 'generated_by', 'generated_at'];

    /** @return array<string,mixed>|null this branch's link with who generated it, or null if none yet */
    public function current(): ?array
    {
        return $this->db()->first(
            'SELECT l.id, l.is_active, l.blocked_count, l.generated_at, l.updated_at, u.name AS generated_by_name
             FROM employee_join_links l
             LEFT JOIN users u ON u.id = l.generated_by
             WHERE l.branch_id = :branch
             LIMIT 1',
            ['branch' => $this->requireBranchId()]
        );
    }

    /**
     * Issue a fresh token for this branch — the first one, or a replacement
     * that kills the previous URL — and switch the link on. Returns the
     * token, which the caller shows once and never stores.
     */
    public function regenerate(): string
    {
        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $columns = [
            'token_hash' => self::hash($token),
            'is_active' => 1,
            // The count is per link: a new token starts again from zero.
            'blocked_count' => 0,
            'generated_by' => Auth::id(),
            'generated_at' => date('Y-m-d H:i:s'),
        ];

        Database::instance()->transaction(function () use ($columns): void {
            $existing = $this->db()->value(
                'SELECT id FROM employee_join_links WHERE branch_id = :branch FOR UPDATE',
                ['branch' => $this->requireBranchId()]
            );

            if ($existing === null) {
                $this->create($columns);
            } else {
                $this->updateById((int) $existing, $columns);
            }
        });

        return $token;
    }

    /** @return bool whether a link existed to switch */
    public function setActive(bool $active): bool
    {
        if ($this->current() === null) {
            return false;
        }

        $this->db()->update(
            'employee_join_links',
            ['is_active' => $active ? 1 : 0],
            'branch_id = :branch',
            ['branch' => $this->requireBranchId()]
        );

        return true;
    }

    /**
     * The branch an active link belongs to — for the public form, where
     * there is no session branch. A disabled link, a replaced token, an
     * unknown or malformed token and a locked branch all return null, and
     * the caller shows one neutral page for every one of them.
     */
    public static function activeBranchFor(string $token): ?int
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        $branchId = Database::instance()->value(
            'SELECT l.branch_id
             FROM employee_join_links l
             JOIN branches b ON b.id = l.branch_id AND b.is_active = 1
             WHERE l.token_hash = :hash AND l.is_active = 1
             LIMIT 1',
            ['hash' => self::hash($token)]
        );

        return $branchId === null ? null : (int) $branchId;
    }

    /**
     * One more submission turned away as automated through this token. A
     * bare counter — nothing about the request is stored — so a person
     * caught by mistake shows up as a number on the admin's link panel.
     */
    public static function recordBlocked(string $token): void
    {
        Database::instance()->run(
            'UPDATE employee_join_links SET blocked_count = blocked_count + 1 WHERE token_hash = :hash',
            ['hash' => self::hash($token)]
        );
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
