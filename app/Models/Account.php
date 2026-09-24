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
