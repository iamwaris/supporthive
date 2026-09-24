<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Model;

final class Partner extends Model
{
    protected string $table = 'partners';

    /** @var list<string> */
    protected array $fillable = ['name', 'email', 'phone', 'join_date', 'status', 'notes', 'created_by'];

    /** @return list<array<string,mixed>> */
    public function allOrdered(): array
    {
        return $this->db()->all(
            "SELECT * FROM partners WHERE branch_id = :branch ORDER BY FIELD(status, 'active', 'inactive'), name ASC",
            ['branch' => $this->requireBranchId()]
        );
    }

    /** @return list<array<string,mixed>> */
    public function active(): array
    {
        return $this->db()->all(
            "SELECT * FROM partners WHERE branch_id = :branch AND status = 'active' ORDER BY name ASC",
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
        $sql = 'SELECT COUNT(*) FROM partners WHERE branch_id = :branch AND name = :name';
        $params = ['branch' => $this->requireBranchId(), 'name' => $name];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }

        return (int) $this->db()->value($sql, $params) > 0;
    }
}
