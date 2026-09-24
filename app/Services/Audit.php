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

    /** @return list<array<string,mixed>> */
    public static function forEntity(string $entityType, int $entityId): array
    {
        return Database::instance()->all(
            'SELECT a.*, u.name AS user_name
             FROM audit_log a
             LEFT JOIN users u ON u.id = a.user_id
             WHERE a.entity_type = :type AND a.entity_id = :id
             ORDER BY a.created_at DESC, a.id DESC',
            ['type' => $entityType, 'id' => $entityId]
        );
    }
}
