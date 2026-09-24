<?php

/**
 * @var string $from
 * @var string $to
 * @var array{inbound:string,outbound:string,net:string,pendingIncome:string} $report
 * @var array<string,mixed>|null $authUser
 */

declare(strict_types=1);

use App\Services\Settings;

$symbol = Settings::string('currency_symbol', 'Rs');
$money = static fn (mixed $v): string => number_format((float) $v, 2);
?>

<div class="flex flex-col gap-5">
    <form method="get" action="<?= e(url('/reports/cash-flow')) ?>" class="card flex flex-wrap items-end gap-3 px-5 py-4">
        <div>
            <label for="f-from" class="label">From</label>
            <input id="f-from" name="from" type="date" value="<?= e($from) ?>" class="input">
        </div>
        <div>
            <label for="f-to" class="label">To</label>
            <input id="f-to" name="to" type="date" value="<?= e($to) ?>" class="input">
        </div>
        <button type="submit" class="btn-dark">Apply</button>
        <a href="<?= e(url('/reports/cash-flow') . '?' . http_build_query(['from' => $from, 'to' => $to, 'format' => 'csv'])) ?>"
           class="btn-secondary ml-auto">Export CSV</a>
        <a href="<?= e(url('/reports/cash-flow') . '?' . http_build_query(['from' => $from, 'to' => $to, 'format' => 'pdf'])) ?>"
           class="btn-secondary">Export PDF</a>
    </form>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="card px-5 py-4">
            <p class="kpi-label">Money in</p>
            <p class="money mt-1.5 font-display text-xl font-semibold text-ok-text"><?= e($symbol) ?> <?= e($money($report['inbound'])) ?></p>
            <p class="mt-1 text-[11px] text-slate-500">Income, transfers in, contributions</p>
        </div>
        <div class="card px-5 py-4">
            <p class="kpi-label">Money out</p>
            <p class="money mt-1.5 font-display text-xl font-semibold text-bad-text"><?= e($symbol) ?> <?= e($money($report['outbound'])) ?></p>
            <p class="mt-1 text-[11px] text-slate-500">Expenses, transfers out, withdrawals, distributions</p>
        </div>
        <div class="card px-5 py-4">
            <p class="kpi-label">Net cash flow</p>
            <p class="money mt-1.5 font-display text-xl font-semibold <?= (float) $report['net'] < 0 ? 'text-bad-text' : 'text-ink' ?>">
                <?= e($symbol) ?> <?= e($money($report['net'])) ?>
            </p>
        </div>
        <div class="rounded-card border border-slate-200 bg-slate-100 px-5 py-4">
            <p class="kpi-label">Expected, not counted</p>
            <p class="money mt-1.5 font-display text-xl font-semibold text-slate-500"><?= e($symbol) ?> <?= e($money($report['pendingIncome'])) ?></p>
            <p class="mt-1 text-[11px] text-slate-500">Invoiced but not yet received</p>
        </div>
    </div>
</div>
