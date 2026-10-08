<?php

/**
 * Employees — admin only, scoped to this branch.
 *
 * @var list<array<string,mixed>>          $employees
 * @var array{q:?string,status:?string}    $filters
 * @var int                                $total
 * @var int                                $page
 * @var int                                $pages
 * @var int                                $perPage
 */

declare(strict_types=1);

$statusLabels = ['active' => 'Active', 'suspended' => 'Deactivated'];

$pageUrl = static function (int $target) use ($filters): string {
    $query = array_filter(
        ['q' => $filters['q'], 'status' => $filters['status'], 'page' => $target > 1 ? $target : null],
        static fn (mixed $v): bool => $v !== null && $v !== ''
    );

    return url('/employees') . ($query === [] ? '' : '?' . http_build_query($query));
};

$filtered = $filters['q'] !== null || $filters['status'] !== null;
?>

<div class="flex flex-col gap-4">

    <div class="flex flex-col gap-3 md:flex-row md:items-end">
        <form method="get" action="<?= e(url('/employees')) ?>" class="card flex flex-1 flex-col gap-3 p-4 sm:flex-row sm:items-end" role="search">
            <div class="flex-1">
                <label for="emp-q" class="label">Search</label>
                <input id="emp-q" name="q" type="search" maxlength="100" placeholder="Name, email, designation or phone"
                       value="<?= e((string) $filters['q']) ?>" class="input">
            </div>
            <div class="sm:w-44">
                <label for="emp-status" class="label">Status</label>
                <select id="emp-status" name="status" class="input">
                    <option value="">Any status</option>
                    <?php foreach ($statusLabels as $value => $label) : ?>
                        <option value="<?= e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn-dark">Apply</button>
                <?php if ($filtered) : ?>
                    <a href="<?= e(url('/employees')) ?>" class="btn-secondary">Clear</a>
                <?php endif; ?>
            </div>
        </form>

        <a href="<?= e(url('/employees/new')) ?>" class="btn-primary">Add employee</a>
    </div>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full border-collapse">
                <caption class="sr-only">Employees in this branch</caption>
                <thead>
                    <tr>
                        <th scope="col" class="th">Name</th>
                        <th scope="col" class="th hidden md:table-cell">Designation</th>
                        <th scope="col" class="th hidden lg:table-cell">Phone</th>
                        <th scope="col" class="th">Status</th>
                        <th scope="col" class="th"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($employees === []) : ?>
                        <tr>
                            <td class="td" colspan="5">
                                <p class="py-10 text-center text-sm text-slate-600">
                                    <?php if ($filtered) : ?>
                                        No employees match these filters.
                                        <a href="<?= e(url('/employees')) ?>" class="font-medium text-ink underline">Clear filters</a>
                                    <?php else : ?>
                                        No employees yet.
                                        <a href="<?= e(url('/employees/new')) ?>" class="font-medium text-ink underline">Add the first one</a>
                                    <?php endif; ?>
                                </p>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($employees as $employee) : ?>
                        <?php
                        $id = (int) $employee['id'];
                        $isActive = (string) $employee['status'] === 'active';
                        ?>
                        <tr>
                            <td class="td">
                                <a href="<?= e(url('/employees/' . $id)) ?>" class="font-medium text-ink hover:underline">
                                    <?= e((string) $employee['name']) ?>
                                </a>
                                <p class="text-[11.5px] break-all text-slate-500"><?= e((string) $employee['email']) ?></p>
                                <p class="text-[11.5px] text-slate-500 md:hidden"><?= e((string) $employee['designation']) ?></p>
                            </td>
                            <td class="td hidden text-slate-600 md:table-cell"><?= e((string) $employee['designation']) ?></td>
                            <td class="td money hidden text-slate-600 lg:table-cell"><?= e((string) ($employee['phone'] ?? '—')) ?></td>
                            <td class="td">
                                <span class="<?= $isActive ? 'badge-ok' : 'badge-bad' ?>">
                                    <?= e($isActive ? 'Active' : 'Deactivated') ?>
                                </span>
                            </td>
                            <td class="td text-right whitespace-nowrap">
                                <a href="<?= e(url('/employees/' . $id)) ?>" class="btn-secondary"
                                   aria-label="<?= e('View ' . (string) $employee['name']) ?>">View</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php require APP_PATH . '/Views/partials/pagination.php'; ?>
    </div>
</div>
