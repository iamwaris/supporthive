<?php

/**
 * @var string $monthValue
 * @var list<array<string,mixed>> $summary
 * @var array{budgeted:string,spent:string,remaining:string,utilisation_pct:float,count:int} $totals
 * @var array<string,mixed>|null $authUser
 */

declare(strict_types=1);

use App\Services\Settings;

$symbol = Settings::string('currency_symbol', 'Rs');
$money = static fn (mixed $v): string => number_format((float) $v, 2);

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
?>

<div class="flex flex-col gap-5">
    <form method="get" action="<?= e(url('/reports/budget-vs-actual')) ?>" class="card flex flex-wrap items-end gap-3 px-5 py-4">
        <div>
            <label for="f-month" class="label">Month</label>
            <input id="f-month" name="month" type="month" value="<?= e($monthValue) ?>" class="input" x-on:change="$el.form.submit()">
        </div>
        <noscript><button type="submit" class="btn-secondary">Go</button></noscript>
        <a href="<?= e(url('/reports/budget-vs-actual') . '?' . http_build_query(['month' => $monthValue, 'format' => 'csv'])) ?>"
           class="btn-secondary ml-auto">Export CSV</a>
        <a href="<?= e(url('/reports/budget-vs-actual') . '?' . http_build_query(['month' => $monthValue, 'format' => 'pdf'])) ?>"
           class="btn-secondary">Export PDF</a>
    </form>

    <div class="grid gap-3 sm:grid-cols-3">
        <div class="card px-5 py-4">
            <p class="kpi-label">Budgeted</p>
            <p class="money mt-1.5 font-display text-xl font-semibold text-ink"><?= e($symbol) ?> <?= e($money($totals['budgeted'])) ?></p>
        </div>
        <div class="card px-5 py-4">
            <p class="kpi-label">Spent</p>
            <p class="money mt-1.5 font-display text-xl font-semibold text-ink"><?= e($symbol) ?> <?= e($money($totals['spent'])) ?></p>
        </div>
        <div class="card px-5 py-4">
            <p class="kpi-label">Remaining</p>
            <p class="money mt-1.5 font-display text-xl font-semibold <?= (float) $totals['remaining'] < 0 ? 'text-bad-text' : 'text-ink' ?>">
                <?= e($symbol) ?> <?= e($money($totals['remaining'])) ?>
            </p>
        </div>
    </div>

    <div class="card overflow-hidden">
        <table class="w-full border-collapse">
            <thead>
                <tr>
                    <th scope="col" class="th">Category</th>
                    <th scope="col" class="th text-right">Budget</th>
                    <th scope="col" class="th text-right">Spent</th>
                    <th scope="col" class="th">Utilisation</th>
                    <th scope="col" class="th">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($summary === []) : ?>
                    <tr>
                        <td class="td" colspan="5">
                            <p class="py-8 text-center text-sm text-slate-500">
                                No budgets set for this month.
                                <a href="<?= e(url('/budgets')) ?>" class="underline">Set one</a>
                            </p>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($summary as $row) : ?>
                    <?php
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
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
