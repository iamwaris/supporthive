<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Thin base model. Subclasses declare $table and $fillable; writes are filtered
 * through $fillable so a rogue form field can never reach a column like `role`
 * or `is_admin`.
 *
 * Every table this backs is branch-scoped (multi-branch retrofit, decision
 * 2026-09-25): find()/updateById()/deleteById()/count()/paginate() all AND in
 * `branch_id = :branch_id` from Auth::branchId(), and create() stamps it onto
 * every insert. This is also what CLAUDE.md rule 5 ("verify ownership") means
 * for a master-data record — find($id) alone used to let any signed-in user
 * reach any row by id; it cannot any more; a Branch B id is simply not found
 * from a Branch A session.
 *
 * A handful of tables (users, branches themselves) are not branch-scoped;
 * their models bypass this by setting $tenantScoped = false.
 */
abstract class Model
{
    protected string $table = '';
    /** @var list<string> */
    protected array $fillable = [];
    protected string $primaryKey = 'id';
    protected bool $tenantScoped = true;

    protected function db(): Database
    {
        return Database::instance();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $sql = sprintf(
            'SELECT * FROM %s WHERE %s = :id%s LIMIT 1',
            Database::identifier($this->table),
            Database::identifier($this->primaryKey),
            $this->tenantScoped ? ' AND branch_id = :branch_id' : ''
        );

        return $this->db()->first($sql, $this->withBranch(['id' => $id]));
    }

    /** @return list<array<string,mixed>> */
    public function paginate(
        int $page = 1,
        int $perPage = 20,
        string $orderBy = 'id',
        string $direction = 'DESC'
    ): array {
        $direction = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';
        $perPage = max(1, min($perPage, 100));
        $offset = max(0, ($page - 1) * $perPage);

        return $this->db()->all(
            sprintf(
                'SELECT * FROM %s%s ORDER BY %s %s LIMIT :limit OFFSET :offset',
                Database::identifier($this->table),
                $this->tenantScoped ? ' WHERE branch_id = :branch_id' : '',
                Database::identifier($orderBy),
                $direction
            ),
            $this->withBranch(['limit' => $perPage, 'offset' => $offset])
        );
    }

    public function count(string $where = '1', array $params = []): int
    {
        $where = $this->tenantScoped ? '(' . $where . ') AND branch_id = :branch_id' : $where;

        return (int) $this->db()->value(
            sprintf('SELECT COUNT(*) FROM %s WHERE %s', Database::identifier($this->table), $where),
            $this->tenantScoped ? $this->withBranch($params) : $params
        );
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        $data = $this->filter($data);
        if ($this->tenantScoped) {
            $data['branch_id'] = $this->requireBranchId();
        }

        return $this->db()->insert($this->table, $data);
    }

    /** @param array<string,mixed> $data */
    public function updateById(int $id, array $data): int
    {
        $where = Database::identifier($this->primaryKey) . ' = :id'
            . ($this->tenantScoped ? ' AND branch_id = :branch_id' : '');

        return $this->db()->update($this->table, $this->filter($data), $where, $this->withBranch(['id' => $id]));
    }

    public function deleteById(int $id): int
    {
        $where = Database::identifier($this->primaryKey) . ' = :id'
            . ($this->tenantScoped ? ' AND branch_id = :branch_id' : '');

        return $this->db()->delete($this->table, $where, $this->withBranch(['id' => $id]));
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function withBranch(array $params): array
    {
        if ($this->tenantScoped) {
            $params['branch_id'] = $this->requireBranchId();
        }

        return $params;
    }

    /** Exposed to subclasses that run their own hand-written SQL and need the same guard. */
    protected function requireBranchId(): int
    {
        $branchId = Auth::branchId();
        if ($branchId === null) {
            throw new RuntimeException('No active branch — cannot read or write a branch-scoped table.');
        }

        return $branchId;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    protected function filter(array $data): array
    {
        return $this->fillable === []
            ? $data
            : array_intersect_key($data, array_flip($this->fillable));
    }
}
