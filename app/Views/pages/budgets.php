<?php

/**
 * @var int                         $year
 * @var int                         $month
 * @var list<array<string,mixed>>   $budgets
 * @var list<array<string,mixed>>   $availableCategories
 * @var array<string,list<string>>  $errors
 * @var array<string,mixed>|null    $authUser
 */

declare(strict_types=1);

use App\Services\Access;
use App\Services\Settings;

$errors = $errors ?? [];
$canEdit = Access::canManageMasterData((string) ($authUser['role'] ?? ''));
$symbol = Settings::string('currency_symbol', 'Rs');
$money = static fn (mixed $v): string => number_format((float) $v, 2);

$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
];

$badgeFor = static fn (string $state): string => match ($state) {
    'exceeded' => 'badge-bad',
    'warning' => 'badge-warn',
    default => 'badge-ok',
};
$labelFor = static fn (string $state): string => match ($state) {
    'exceeded' => 'Over budget',
    'warning' => 'Nearing limit',
    default => 'On track',
};

$totalBudget = array_sum(array_map(static fn (array $r): float => (float) $r['amount'], $budgets));
$totalSpent = array_sum(array_map(static fn (array $r): float => (float) $r['spent'], $budgets));
?>

<div class="flex flex-col gap-5">

    <form method="get" action="<?= e(url('/budgets')) ?>" class="card flex flex-wrap items-end gap-3 px-5 py-4">
        <div>
            <label for="f-month" class="label">Month</label>
            <select id="f-month" name="month" class="input" onchange="this.form.submit()">
                <?php foreach ($months as $num => $label) : ?>
                    <option value="<?= $num ?>" <?= $num === $month ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="f-year" class="label">Year</label>
            <input id="f-year" name="year" type="number" min="2020" max="2100" value="<?= e((string) $year) ?>"
                   class="input w-24" onchange="this.form.submit()">
        </div>
        <noscript><button type="submit" class="btn-secondary">Go</button></noscript>
    </form>

    <div class="grid gap-5 xl:grid-cols-[1fr_360px]">

        <div class="flex flex-col gap-5">
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="card px-5 py-4">
                    <p class="kpi-label">Total budgeted — <?= e($months[$month]) ?> <?= e((string) $year) ?></p>
                    <p class="money mt-1.5 font-display text-xl font-semibold text-ink"><?= e($symbol) ?> <?= e($money($totalBudget)) ?></p>
                </div>
                <div class="card px-5 py-4">
                    <p class="kpi-label">Total spent</p>
                    <p class="money mt-1.5 font-display text-xl font-semibold text-ink"><?= e($symbol) ?> <?= e($money($totalSpent)) ?></p>
                </div>
            </div>

            <div class="card overflow-hidden">
                <div class="px-5 pt-4 pb-3">
                    <h2 class="font-display text-sm font-semibold text-ink">Budget vs actual</h2>
                </div>

                <table class="w-full border-collapse">
                    <thead>
                        <tr>
                            <th scope="col" class="th">Category</th>
                            <th scope="col" class="th text-right">Budget</th>
                            <th scope="col" class="th text-right">Spent</th>
                            <th scope="col" class="th">Utilisation</th>
                            <th scope="col" class="th">Status</th>
                            <?php if ($canEdit) : ?>
                                <th scope="col" class="th"><span class="sr-only">Actions</span></th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody x-data="{ openId: null }">
                        <?php if ($budgets === []) : ?>
                            <tr>
                                <td class="td" colspan="6">
                                    <p class="py-6 text-center text-sm text-slate-500">
                                        No budgets set for this month.<?= $canEdit ? ' Add one on the right.' : '' ?>
                                    </p>
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($budgets as $row) : ?>
                            <?php
                            $id = (int) $row['id'];
                            $state = (string) $row['state'];
                            $pct = min(100.0, (float) $row['utilisation_pct']);
                            $barColor = match ($state) {
                                'exceeded' => 'bg-bad-text',
                                'warning' => 'bg-brand-500',
                                default => 'bg-ok-text',
                            };
    ?>
                            <tr>
                                <td class="td font-medium text-ink"><?= e((string) $row['category_name']) ?></td>
                                <td class="td money text-right text-slate-600"><?= e($money($row['amount'])) ?></td>
                                <td class="td money text-right text-slate-600"><?= e($money($row['spent'])) ?></td>
                                <td class="td">
                                    <div class="flex items-center gap-2">
                                        <div class="h-1.5 w-24 overflow-hidden rounded-full bg-slate-100">
                                            <div class="h-full <?= $barColor ?>" style="width: <?= e((string) $pct) ?>%"></div>
                                        </div>
                                        <span class="money text-[11px] text-slate-500"><?= e((string) $row['utilisation_pct']) ?>%</span>
                                    </div>
                                </td>
                                <td class="td"><span class="<?= $badgeFor($state) ?>"><?= e($labelFor($state)) ?></span></td>
                                <?php if ($canEdit) : ?>
                                    <td class="td text-right">
                                        <button type="button" x-on:click="openId = openId === <?= $id ?> ? null : <?= $id ?>"
                                                class="rounded-md px-2 py-1 text-[11.5px] font-medium text-brand-700 hover:bg-brand-50">Edit</button>
                                    </td>
                                <?php endif; ?>
                            </tr>

                            <?php if ($canEdit) : ?>
                                <tr x-show="openId === <?= $id ?>" x-cloak>
                                    <td class="border-b border-slate-100 bg-slate-50 px-3.5 py-4" colspan="6">
                                        <form method="post" action="<?= e(url('/budgets/' . $id)) ?>" class="flex flex-wrap items-end gap-3">
                                            <?= csrf_field() ?>
                                            <div>
                                                <label for="b-amount-<?= $id ?>" class="label">Budget (<?= e($symbol) ?>)</label>
                                                <input id="b-amount-<?= $id ?>" name="amount" type="text" inputmode="decimal" required
                                                       value="<?= e((string) $row['amount']) ?>" class="input money w-40">
                                            </div>
                                            <div>
                                                <label for="b-threshold-<?= $id ?>" class="label">Alert at %</label>
                                                <input id="b-threshold-<?= $id ?>" name="alert_threshold_pct" type="number" min="1" max="100"
                                                       value="<?= e((string) $row['alert_threshold_pct']) ?>" class="input w-24">
                                            </div>
                                            <button type="submit" class="btn-primary">Save</button>
                                            <button type="button" x-on:click="openId = null" class="btn-secondary">Cancel</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($canEdit) : ?>
            <div class="card p-5">
                <h2 class="font-display text-sm font-semibold text-ink">Set a budget</h2>
                <p class="mt-0.5 text-[11.5px] text-slate-500"><?= e($months[$month]) ?> <?= e((string) $year) ?>, one expense category at a time</p>

                <?php if ($availableCategories === []) : ?>
                    <p class="mt-4 rounded-lg bg-slate-50 px-3 py-3 text-xs text-slate-500">
                        Every expense category already has a budget for this month.
                    </p>
                <?php else : ?>
                    <form method="post" action="<?= e(url('/budgets')) ?>" class="mt-4 flex flex-col gap-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="year" value="<?= e((string) $year) ?>">
                        <input type="hidden" name="month" value="<?= e((string) $month) ?>">

                        <div>
                            <label for="new-b-category" class="label">Category</label>
                            <select id="new-b-category" name="category_id" required
                                    class="<?= isset($errors['category_id']) ? 'input-error' : 'input' ?>">
                                <?php foreach ($availableCategories as $category) : ?>
                                    <option value="<?= e((string) $category['id']) ?>"
                                        <?= old('category_id') === (string) $category['id'] ? 'selected' : '' ?>>
                                        <?= e((string) $category['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($errors['category_id'][0])) : ?>
                                <p class="error"><?= e($errors['category_id'][0]) ?></p>
                            <?php endif; ?>
                        </div>

                        <div>
                            <label for="new-b-amount" class="label">Budget (<?= e($symbol) ?>)</label>
                            <input id="new-b-amount" name="amount" type="text" inputmode="decimal" required
                                   placeholder="0.00" value="<?= e(old('amount')) ?>"
                                   class="money <?= isset($errors['amount']) ? 'input-error' : 'input' ?>">
                            <?php if (isset($errors['amount'][0])) : ?>
                                <p class="error"><?= e($errors['amount'][0]) ?></p>
                            <?php endif; ?>
                        </div>

                        <div>
                            <label for="new-b-threshold" class="label">Alert threshold</label>
                            <input id="new-b-threshold" name="alert_threshold_pct" type="number" min="1" max="100"
                                   value="<?= e(old('alert_threshold_pct', '80')) ?>" class="input">
                            <p class="help">Flagged "nearing limit" once spend reaches this % of budget.</p>
                        </div>

                        <button type="submit" class="btn-primary">Set budget</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
