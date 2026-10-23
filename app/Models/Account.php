<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Model;

final class Account extends Model
{
    protected string $table = 'accounts';

    /** @var list<string> */
    protected array $fillable = [
        'name', 'type', 'opening_balance', 'opening_date',
        'institution', 'reference', 'is_active', 'notes', 'created_by',
    ];

    public const TYPES = [
        'cash' => 'Cash in hand',
        'bank' => 'Bank account',
        'credit_card' => 'Credit card',
        'petty_cash' => 'Petty cash',
        'other' => 'Other',
    ];

    /** @return list<array<string,mixed>> */
    public function allOrdered(): array
    {
        return $this->db()->all(
            'SELECT * FROM accounts WHERE branch_id = :branch ORDER BY is_active DESC, name ASC',
            ['branch' => $this->requireBranchId()]
        );
    }

    /** @return list<array<string,mixed>> the accounts money can move through today, by name */
    public function activeOrdered(): array
    {
        return $this->db()->all(
            'SELECT id, name, type FROM accounts WHERE branch_id = :branch AND is_active = 1 ORDER BY name ASC',
            ['branch' => $this->requireBranchId()]
        );
    }

    /** @return array<string,mixed>|null this branch's account, only while it is open for postings */
    public function findActive(int $id): ?array
    {
        return $this->db()->first(
            'SELECT id, name, type FROM accounts WHERE id = :id AND branch_id = :branch AND is_active = 1',
            ['id' => $id, 'branch' => $this->requireBranchId()]
        );
    }

    /**
     * A branch's "Cash account": the default for register postings and the
     * one the one-time backfill posts legacy entries through.
     *
     * Exactly the active cash-type account named "Cash" (names are unique
     * per branch; the comparison follows the column's case-insensitive
     * collation). No fallback to another cash account: null means the branch
     * has none, and a caller must never invent or guess one.
     *
     * @param int|null $branchId null means the current session's branch
     */
    public function cashAccountId(?int $branchId = null): ?int
    {
        $id = $this->db()->value(
            "SELECT id FROM accounts
             WHERE branch_id = :branch AND type = 'cash' AND is_active = 1 AND name = 'Cash'
             LIMIT 1",
            ['branch' => $branchId ?? $this->requireBranchId()]
        );

        return $id === null ? null : (int) $id;
    }

    /**
     * Every cash-type account of a branch, open or closed: what an operator
     * needs to see when cashAccountId() finds no "Cash" account.
     *
     * @return list<array<string,mixed>> id, name, is_active; lowest id first
     */
    public function cashTypeAccounts(int $branchId): array
    {
        return $this->db()->all(
            "SELECT id, name, is_active FROM accounts WHERE branch_id = :branch AND type = 'cash' ORDER BY id",
            ['branch' => $branchId]
        );
    }

    /** @param array<string,mixed> $data */
    public function createFor(array $data): int
    {
        return $this->create($data + ['created_by' => Auth::id()]);
    }

    public function nameExists(string $name, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM accounts WHERE branch_id = :branch AND name = :name';
        $params = ['branch' => $this->requireBranchId(), 'name' => $name];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }

        return (int) $this->db()->value($sql, $params) > 0;
    }
}
