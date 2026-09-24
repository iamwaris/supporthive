<?php

/**
 * Audit log viewer (M7-4) — admin only, scoped to this branch.
 *
 * @var list<array<string,mixed>>  $rows
 * @var int                        $total
 * @var int                        $page
 * @var int                        $pages
 * @var int                        $perPage
 * @var array<string,mixed>        $filters
 * @var list<string>                $actions
 * @var list<string>                $entityTypes
 * @var list<array<string,mixed>>   $users
 */

declare(strict_types=1);

$actionLabel = static fn (string $action): string => ucfirst(str_replace(['.', '_'], [' ', ' '], $action));

/** @param array<string,mixed> $fields */
$formatFields = static function (array $fields): string {
    $parts = [];
    foreach ($fields as $key => $value) {
        $printable = is_scalar($value) || $value === null ? (string) $value : json_encode($value);
        $parts[] = $key . ': ' . ($printable === '' ? '—' : $printable);
    }

    return implode(', ', $parts);
};

$pageUrl = static function (int $target) use ($filters): string {
    $query = array_filter(
        [
            'entity_type' => $filters['entity_type'],
            'action' => $filters['action'],
            'user_id' => $filters['user_id'],
            'from' => $filters['from'],
            'to' => $filters['to'],
            'page' => $target > 1 ? $target : null,
        ],
        static fn (mixed $v): bool => $v !== null && $v !== ''
    );

        return url('/audit-log') . ($query === [] ? '' : '?' . http_build_query($query));
};
?>

<!-- ============ filters ============ -->
<form method="get" action="<?= e(url('/audit-log')) ?>" class="card p-4">
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <div>
            <label for="f-entity" class="label">Entity</label>
            <select id="f-entity" name="entity_type" class="input">
                <option value="">All entities</option>
                <?php foreach ($entityTypes as $type) : ?>
                    <option value="<?= e($type) ?>" <?= $filters['entity_type'] === $type ? 'selected' : '' ?>>
                        <?= e($type) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="f-action" class="label">Action</label>
            <select id="f-action" name="action" class="input">
                <option value="">All actions</option>
                <?php foreach ($actions as $action) : ?>
                    <option value="<?= e($action) ?>" <?= $filters['action'] === $action ? 'selected' : '' ?>>
                        <?= e($actionLabel($action)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="f-user" class="label">User</label>
            <select id="f-user" name="user_id" class="input">
                <option value="">Anyone</option>
                <?php foreach ($users as $user) : ?>
                    <option value="<?= e((string) $user['id']) ?>"
                        <?= (int) ($filters['user_id'] ?? 0) === (int) $user['id'] ? 'selected' : '' ?>>
                        <?= e((string) $user['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="f-from" class="label">From</label>
            <input id="f-from" name="from" type="date" value="<?= e((string) ($filters['from'] ?? '')) ?>" class="input">
        </div>
        <div>
            <label for="f-to" class="label">To</label>
            <input id="f-to" name="to" type="date" value="<?= e((string) ($filters['to'] ?? '')) ?>" class="input">
        </div>
    </div>
    <div class="mt-3 flex gap-2">
        <button type="submit" class="btn-dark">Apply</button>
        <a href="<?= e(url('/audit-log')) ?>" class="btn-secondary">Clear</a>
    </div>
</form>

<!-- ============ rows ============ -->
<div class="card mt-4 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full border-collapse">
            <thead>
                <tr>
                    <th scope="col" class="th">When</th>
                    <th scope="col" class="th">User</th>
                    <th scope="col" class="th">Action</th>
                    <th scope="col" class="th">Entity</th>
                    <th scope="col" class="th">Details</th>
                    <th scope="col" class="th">IP</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []) : ?>
                    <tr>
                        <td class="td" colspan="6">
                            <p class="py-10 text-center text-sm text-slate-600">
                                <?= $total === 0 && $filters['entity_type'] === null && $filters['action'] === null
                                    ? 'Nothing recorded yet.'
                                    : 'No entries match these filters.' ?>
                            </p>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($rows as $row) : ?>
                    <?php
                    $meta = json_decode((string) ($row['meta'] ?? '{}'), true) ?: [];
                    $before = is_array($meta['before'] ?? null) ? $meta['before'] : [];
                    $after = is_array($meta['after'] ?? null) ? $meta['after'] : [];
                    ?>
                    <tr>
                        <td class="td money whitespace-nowrap text-slate-500">
                            <?= e(date('j M Y, H:i', (int) strtotime((string) $row['created_at']))) ?>
                        </td>
                        <td class="td text-slate-600"><?= e((string) ($row['user_name'] ?? 'System')) ?></td>
                        <td class="td">
                            <span class="badge-mute"><?= e($actionLabel((string) $row['action'])) ?></span>
                        </td>
                        <td class="td text-slate-600">
                            <?= e((string) $row['entity_type']) ?>
                            <?php if ($row['entity_id'] !== null) : ?>
                                <span class="money text-slate-400">#<?= e((string) $row['entity_id']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="td max-w-xs text-[11.5px] text-slate-500">
                            <?php if ($after !== []) : ?>
                                <p class="truncate" title="<?= e($formatFields($after)) ?>"><?= e($formatFields($after)) ?></p>
                            <?php endif; ?>
                            <?php if ($before !== []) : ?>
                                <p class="truncate text-slate-400 line-through" title="<?= e($formatFields($before)) ?>">
                                    <?= e($formatFields($before)) ?>
                                </p>
                            <?php endif; ?>
                            <?php if ($before === [] && $after === []) : ?>
                                <span class="text-slate-300">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="td money text-slate-400"><?= e((string) ($row['ip'] ?? '—')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total > 0) : ?>
        <div class="flex flex-wrap items-center gap-3 border-t border-slate-200 bg-slate-50 px-4 py-3">
            <p class="flex-1 text-[11.5px] text-slate-500">
                Showing <span class="money"><?= e((string) (($page - 1) * $perPage + 1)) ?></span>&ndash;<span
                    class="money"><?= e((string) min($page * $perPage, $total)) ?></span>
                of <span class="money"><?= e(number_format($total)) ?></span>.
            </p>

            <?php if ($page > 1) : ?>
                <a href="<?= e($pageUrl($page - 1)) ?>" class="btn-secondary">Previous</a>
            <?php else : ?>
                <span class="btn-secondary pointer-events-none opacity-40">Previous</span>
            <?php endif; ?>

            <span class="money text-xs text-slate-600"><?= e((string) $page) ?> of <?= e((string) $pages) ?></span>

            <?php if ($page < $pages) : ?>
                <a href="<?= e($pageUrl($page + 1)) ?>" class="btn-secondary">Next</a>
            <?php else : ?>
                <span class="btn-secondary pointer-events-none opacity-40">Next</span>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
