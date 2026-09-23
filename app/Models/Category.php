<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class Category extends Model
{
    protected string $table = 'categories';

    /** @var list<string> */
    protected array $fillable = ['name', 'type', 'parent_id', 'is_active', 'sort_order'];

    public const TYPES = ['expense' => 'Expense', 'income' => 'Income'];

    /**
     * Categories grouped as parents with their children, in display order.
     *
     * One query and a grouping pass rather than a query per parent: the list is
     * rendered on every transaction form, and N+1 there is felt immediately.
     *
     * @return list<array{parent:array<string,mixed>,children:list<array<string,mixed>>}>
     */
    public function tree(string $type): array
    {
        $rows = $this->db()->all(
            'SELECT * FROM categories WHERE type = :type
             ORDER BY COALESCE(parent_id, id), parent_id IS NOT NULL, sort_order, name',
            ['type' => $type]
        );

        $parents = [];
        $children = [];

        foreach ($rows as $row) {
            if ($row['parent_id'] === null) {
                $parents[(int) $row['id']] = $row;
            } else {
                $children[(int) $row['parent_id']][] = $row;
            }
        }

        $tree = [];
        foreach ($parents as $id => $parent) {
            $tree[] = ['parent' => $parent, 'children' => $children[$id] ?? []];
        }

        return $tree;
    }

    /** @return list<array<string,mixed>> */
    public function parentsFor(string $type): array
    {
        return $this->db()->all(
            'SELECT id, name FROM categories
             WHERE type = :type AND parent_id IS NULL AND is_active = 1
             ORDER BY sort_order, name',
            ['type' => $type]
        );
    }

    /**
     * A duplicate is the same name under the same parent and type — which is
     * what the unique key enforces. Checked here so the user gets a field-level
     * message instead of a database error page.
     */
    public function exists(string $name, string $type, ?int $parentId, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM categories WHERE name = :name AND type = :type AND ';
        $sql .= $parentId === null ? 'parent_id IS NULL' : 'parent_id = :parent';

        $params = ['name' => $name, 'type' => $type];
        if ($parentId !== null) {
            $params['parent'] = $parentId;
        }

        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }

        return (int) $this->db()->value($sql, $params) > 0;
    }

    /** Children follow their parent's active state, so none is left selectable under a hidden parent. */
    public function setChildrenActive(int $parentId, int $isActive): void
    {
        $this->db()->update('categories', ['is_active' => $isActive], 'parent_id = :parent', ['parent' => $parentId]);
    }

    /** A subcategory cannot itself have children: one level only. */
    public function isParent(int $id): bool
    {
        return $this->db()->value('SELECT parent_id FROM categories WHERE id = :id', ['id' => $id]) === null;
    }
}
