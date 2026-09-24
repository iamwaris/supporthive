<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Model;

final class Customer extends Model
{
    protected string $table = 'customers';

    /** @var list<string> */
    protected array $fillable = ['name', 'contact_name', 'email', 'phone', 'is_active', 'notes', 'created_by'];

    /** @return list<array<string,mixed>> */
    public function allOrdered(): array
    {
        return $this->db()->all(
            'SELECT * FROM customers WHERE branch_id = :branch ORDER BY is_active DESC, name ASC',
            ['branch' => $this->requireBranchId()]
        );
    }

    /** @param array<string,mixed> $data */
    public function createFor(array $data): int
    {
        return $this->create($data + ['created_by' => Auth::id()]);
    }
}
