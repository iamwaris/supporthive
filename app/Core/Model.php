<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Thin base model. Subclasses declare $table and $fillable; writes are filtered
 * through $fillable so a rogue form field can never reach a column like `role`
 * or `is_admin`.
 */
abstract class Model
{
    protected string $table = '';
    /** @var list<string> */
    protected array $fillable = [];
    protected string $primaryKey = 'id';

    protected function db(): Database
    {
        return Database::instance();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $sql = sprintf(
            'SELECT * FROM %s WHERE %s = :id LIMIT 1',
            Database::identifier($this->table),
            Database::identifier($this->primaryKey)
        );

        return $this->db()->first($sql, ['id' => $id]);
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
                'SELECT * FROM %s ORDER BY %s %s LIMIT :limit OFFSET :offset',
                Database::identifier($this->table),
                Database::identifier($orderBy),
                $direction
            ),
            ['limit' => $perPage, 'offset' => $offset]
        );
    }

    public function count(string $where = '1', array $params = []): int
    {
        return (int) $this->db()->value(
            sprintf('SELECT COUNT(*) FROM %s WHERE %s', Database::identifier($this->table), $where),
            $params
        );
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->db()->insert($this->table, $this->filter($data));
    }

    /** @param array<string,mixed> $data */
    public function updateById(int $id, array $data): int
    {
        return $this->db()->update(
            $this->table,
            $this->filter($data),
            Database::identifier($this->primaryKey) . ' = :id',
            ['id' => $id]
        );
    }

    public function deleteById(int $id): int
    {
        return $this->db()->delete($this->table, Database::identifier($this->primaryKey) . ' = :id', ['id' => $id]);
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
