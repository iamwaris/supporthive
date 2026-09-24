<?php

/**
 * Branch administration — super admin only.
 *
 * @var list<array<string,mixed>>  $branches
 * @var int|null                   $activeBranchId
 * @var array<string,list<string>> $errors
 * @var array<string,mixed>|null   $authUser
 */

declare(strict_types=1);

$errors = $errors ?? [];
$money = static fn (mixed $v): string => number_format((float) $v, 0);
?>

<div class="grid gap-5 xl:grid-cols-[1fr_380px]">

    <div class="card overflow-hidden">
        <div class="px-5 pt-4 pb-3">
            <h2 class="font-display text-sm font-semibold text-ink">Branches</h2>
            <p class="mt-0.5 text-[11.5px] text-slate-500">
                Each branch has its own accounts, categories, partners and ledger — nothing is shared between them.
            </p>
        </div>

        <table class="w-full border-collapse">
            <thead>
                <tr>
                    <th scope="col" class="th">Branch</th>
                    <th scope="col" class="th">Users</th>
                    <th scope="col" class="th">Status</th>
                    <th scope="col" class="th"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($branches === []) : ?>
                    <tr>
                        <td class="td" colspan="4">
                            <p class="py-6 text-center text-sm text-slate-500">
                                No branches yet. Create the first one on the right.
                            </p>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($branches as $branch) : ?>
                    <?php
                    $id = (int) $branch['id'];
                    $isActive = (int) $branch['is_active'] === 1;
                    $isCurrent = $activeBranchId === $id;
                    ?>
                    <tr class="<?= $isCurrent ? 'bg-brand-50/40' : '' ?>">
                        <td class="td">
                            <p class="font-medium text-ink"><?= e((string) $branch['name']) ?></p>
                            <?php if (!empty($branch['created_by_name'])) : ?>
                                <p class="text-[11px] text-slate-400">Created by <?= e((string) $branch['created_by_name']) ?></p>
                            <?php endif; ?>
                        </td>
                        <td class="td money text-slate-600"><?= e($money($branch['user_count'])) ?></td>
                        <td class="td">
                            <span class="<?= $isActive ? 'badge-ok' : 'badge-mute' ?>"><?= $isActive ? 'Active' : 'Closed' ?></span>
                        </td>
                        <td class="td text-right">
                            <?php if ($isCurrent) : ?>
                                <span class="badge-ok">Current</span>
                            <?php elseif ($isActive) : ?>
                                <form method="post" action="<?= e(url('/admin/branches/' . $id . '/switch')) ?>" class="inline">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn-secondary">Switch into</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card p-5">
        <h2 class="font-display text-sm font-semibold text-ink">Create a branch</h2>
        <p class="mt-0.5 text-[11.5px] text-slate-500">
            Starts with the same starter category set every new branch gets — nothing else is copied.
        </p>

        <form method="post" action="<?= e(url('/admin/branches')) ?>" class="mt-4 flex flex-col gap-3">
            <?= csrf_field() ?>
            <div>
                <label for="new-b-name" class="label">Name</label>
                <input id="new-b-name" name="name" type="text" required maxlength="120"
                       value="<?= e(old('name')) ?>"
                       class="<?= isset($errors['name']) ? 'input-error' : 'input' ?>">
                <?php if (isset($errors['name'][0])) : ?>
                    <p class="error"><?= e($errors['name'][0]) ?></p>
                <?php endif; ?>
            </div>
            <button type="submit" class="btn-dark">Create branch</button>
        </form>
    </div>
</div>
