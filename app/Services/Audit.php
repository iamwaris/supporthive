<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Http;

/**
 * Append-only change history.
 *
 * The spec is explicit that financial records are never silently deleted and
 * that edits record who, when and what changed. This is where that lands.
 *
 * Writes here are deliberately best-effort at the call site: an audit row is
 * always written inside the same database transaction as the change it
 * describes, so either both exist or neither does.
 */
final class Audit
{
    /**
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     */
    public static function record(
        string $action,
        string $entityType,
        ?int $entityId,
        ?array $before = null,
        ?array $after = null
    ): void {
        Database::instance()->insert('audit_log', [
            'user_id' => Auth::id(),
            // NULL for a super admin action (no active branch) — see the
            // branches_and_tenant_scope migration's note on this column.
            // Every branch-scoped action must carry it, or an eventual
            // per-branch audit viewer has no column to scope its query on.
            'branch_id' => Auth::branchId(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'ip' => Http::clientIp(),
            'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'cli'), 0, 255),
            'meta' => json_encode(
                array_filter(
                    ['before' => $before, 'after' => $after],
                    static fn (mixed $v): bool => $v !== null
                ),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ),
        ]);
    }

    /**
     * Only the fields that actually changed, so a diff is readable rather than
     * a wall of unchanged values.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @return array{before:array<string,mixed>,after:array<string,mixed>}
     */
    public static function diff(array $before, array $after): array
    {
        $changedBefore = [];
        $changedAfter = [];

        foreach ($after as $field => $value) {
            $previous = $before[$field] ?? null;
            if ((string) $previous !== (string) $value) {
                $changedBefore[$field] = $previous;
                $changedAfter[$field] = $value;
            }
        }

        return ['before' => $changedBefore, 'after' => $changedAfter];
    }

    /**
     * Scoped to the current branch — without that filter, a guessed
     * entity_id from another branch would leak its audit trail, the same
     * class of gap App\Core\Model::find() closed for every other table.
     * A super admin (no active branch) sees only branch-less rows, which is
     * the only kind its own actions ever produce.
     *
     * @return list<array<string,mixed>>
     */
    public static function forEntity(string $entityType, int $entityId): array
    {
        $branchId = Auth::branchId();

        return Database::instance()->all(
            'SELECT a.*, u.name AS user_name
             FROM audit_log a
             LEFT JOIN users u ON u.id = a.user_id
             WHERE a.entity_type = :type AND a.entity_id = :id
               AND a.branch_id ' . ($branchId === null ? 'IS NULL' : '= :branch') . '
             ORDER BY a.created_at DESC, a.id DESC',
            $branchId === null
                ? ['type' => $entityType, 'id' => $entityId]
                : ['type' => $entityType, 'id' => $entityId, 'branch' => $branchId]
        );
    }

    /**
     * The general audit log viewer (M7-4): every action in the current
     * branch, newest first, with optional filters. Always branch-scoped the
     * same way forEntity() is.
     *
     * @param array{entity_type?:?string, action?:?string, user_id?:?int, from?:?string, to?:?string} $filters
     * @return list<array<string,mixed>>
     */
    public static function search(array $filters, int $page = 1, int $perPage = 25): array
    {
        [$where, $params] = self::searchConditions($filters);
        $perPage = max(1, min($perPage, 100));
        $offset = max(0, ($page - 1) * $perPage);

        $params['limit'] = $perPage;
        $params['offset'] = $offset;

        return Database::instance()->all(
            'SELECT a.*, u.name AS user_name
             FROM audit_log a
             LEFT JOIN users u ON u.id = a.user_id
             WHERE ' . $where . '
             ORDER BY a.created_at DESC, a.id DESC
             LIMIT :limit OFFSET :offset',
            $params
        );
    }

    /** @param array{entity_type?:?string, action?:?string, user_id?:?int, from?:?string, to?:?string} $filters */
    public static function searchCount(array $filters): int
    {
        [$where, $params] = self::searchConditions($filters);

        return (int) Database::instance()->value(
            'SELECT COUNT(*) FROM audit_log a WHERE ' . $where,
            $params
        );
    }

    /** Every distinct action recorded in this branch, for the filter dropdown. @return list<string> */
    public static function distinctActions(): array
    {
        return self::distinctColumn('action');
    }

    /** Every distinct entity type recorded in this branch, for the filter dropdown. @return list<string> */
    public static function distinctEntityTypes(): array
    {
        return self::distinctColumn('entity_type');
    }

    /** @return list<string> */
    private static function distinctColumn(string $column): array
    {
        // $column is only ever one of the two literal names above — never
        // request input — but a column can't be bound as a parameter, so it
        // still goes through the same allow-listing every other identifier does.
        $identifier = Database::identifier($column);
        $branchId = Auth::branchId();

        $rows = Database::instance()->all(
            'SELECT DISTINCT ' . $identifier . ' AS value FROM audit_log
             WHERE branch_id ' . ($branchId === null ? 'IS NULL' : '= :branch') . '
               AND ' . $identifier . ' IS NOT NULL
             ORDER BY value ASC',
            $branchId === null ? [] : ['branch' => $branchId]
        );

        return array_map(static fn (array $row): string => (string) $row['value'], $rows);
    }

    /**
     * @param array{entity_type?:?string, action?:?string, user_id?:?int, from?:?string, to?:?string} $filters
     * @return array{0:string,1:array<string,mixed>}
     */
    private static function searchConditions(array $filters): array
    {
        $branchId = Auth::branchId();
        $conditions = ['a.branch_id ' . ($branchId === null ? 'IS NULL' : '= :branch')];
        $params = $branchId === null ? [] : ['branch' => $branchId];

        if (($filters['entity_type'] ?? null) !== null && $filters['entity_type'] !== '') {
            $conditions[] = 'a.entity_type = :entity_type';
            $params['entity_type'] = $filters['entity_type'];
        }
        if (($filters['action'] ?? null) !== null && $filters['action'] !== '') {
            $conditions[] = 'a.action = :action';
            $params['action'] = $filters['action'];
        }
        if (($filters['user_id'] ?? null) !== null) {
            $conditions[] = 'a.user_id = :user_id';
            $params['user_id'] = $filters['user_id'];
        }
        if (($filters['from'] ?? null) !== null && $filters['from'] !== '') {
            $conditions[] = 'a.created_at >= :from';
            $params['from'] = $filters['from'] . ' 00:00:00';
        }
        if (($filters['to'] ?? null) !== null && $filters['to'] !== '') {
            $conditions[] = 'a.created_at <= :to';
            $params['to'] = $filters['to'] . ' 23:59:59';
        }

        return [implode(' AND ', $conditions), $params];
    }
}
