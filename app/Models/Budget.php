<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Model;

final class Budget extends Model
{
    protected string $table = 'budgets';

    /** @var list<string> */
    protected array $fillable = ['year', 'month', 'category_id', 'amount', 'alert_threshold_pct', 'created_by'];

    /**
     * Budgets for one month, joined with the category name a listing needs.
     *
     * @return list<array<string,mixed>>
     */
    public function forPeriod(int $year, int $month): array
    {
        return $this->db()->all(
            'SELECT b.*, c.name AS category_name
             FROM budgets b
             JOIN categories c ON c.id = b.category_id
             WHERE b.branch_id = :branch AND b.year = :year AND b.month = :month
             ORDER BY c.name ASC',
            ['branch' => $this->requireBranchId(), 'year' => $year, 'month' => $month]
        );
    }

    /** @param array<string,mixed> $data */
    public function createFor(array $data): int
    {
        return $this->create($data + ['created_by' => Auth::id()]);
    }

    /** The unique key this enforces, checked early for a field-level message instead of a database error page. */
    public function existsFor(int $year, int $month, int $categoryId, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM budgets
                WHERE branch_id = :branch AND year = :year AND month = :month AND category_id = :category';
        $params = ['branch' => $this->requireBranchId(), 'year' => $year, 'month' => $month, 'category' => $categoryId];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }

        return (int) $this->db()->value($sql, $params) > 0;
    }
}
