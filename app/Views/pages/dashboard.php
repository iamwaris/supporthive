<?php

/**
 * Dashboard, spec §14. 12 widgets:
 *   Today's Expenses, Monthly Expenses, Monthly Revenue, Net Profit,
 *   Budget Used, Budget Remaining, Account Balance, Revenue vs Expenses,
 *   Expense by Category, Budget vs Actual, Recent Transactions, Alerts.
 *
 * Every number arrived as a bounded SQL aggregate via DashboardService /
 * BudgetService — nothing here loops over the transaction table.
 *
 * @var string                     $monthValue
 * @var string                     $todayExpenses
 * @var string                     $monthExpenses
 * @var string                     $monthRevenue
 * @var string                     $monthProfit
 * @var array<string,mixed>        $budgetTotals
 * @var array{total:string,accounts:list<array<string,mixed>>} $accountBalances
 * @var list<array<string,mixed>>  $recentTransactions
 * @var list<array<string,mixed>>  $alerts
 * @var array<string,mixed>|null   $authUser
 */

declare(strict_types=1);

use App\Domain\TransactionType;
use App\Services\Settings;

$symbol = Settings::string('currency_symbol', 'Rs');
$money = static fn (mixed $v): string => number_format((float) $v, 2);
$monthLabel = date('F Y', (int) strtotime($monthValue . '-01'));

$typeBadge = [
    'income' => 'badge-ok',
    'expense' => 'badge-bad',
    'transfer_in' => 'badge-mute',
    'transfer_out' => 'badge-mute',
    'partner_contribution' => 'badge bg-indigo-50 text-indigo-800',
    'partner_withdrawal' => 'badge bg-fuchsia-50 text-fuchsia-800',
    'profit_distribution' => 'badge-warn',
];

$alertBadge = static fn (string $state): string => $state === 'exceeded' ? 'badge-bad' : 'badge-warn';
?>

<form method="get" action="<?= e(url('/dashboard')) ?>" class="card mb-5 flex flex-wrap items-end gap-3 px-5 py-4">
    <div>
        <label for="dash-month" class="label">Month</label>
        <input id="dash-month" name="month" type="month" value="<?= e($monthValue) ?>" class="input"
               x-on:change="$el.form.submit()">
    </div>
    <noscript><button type="submit" class="btn-secondary">Go</button></noscript>
    <p class="ml-auto text-[11px] text-slate-500">Today's Expenses and Account Balance are always as of right now.</p>
</form>

<!-- KPI tiles -->
<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <div class="card p-4">
        <p class="kpi-label">Today's Expenses</p>
        <p class="kpi-value money text-ink"><?= e($symbol) ?> <?= e($money($todayExpenses)) ?></p>
        <p class="mt-1.5 text-[11.5px] text-slate-500"><?= e(date('j M Y')) ?></p>
    </div>
    <div class="card p-4">
        <p class="kpi-label">Monthly Expenses</p>
        <p class="kpi-value money text-ink"><?= e($symbol) ?> <?= e($money($monthExpenses)) ?></p>
        <p class="mt-1.5 text-[11.5px] text-slate-500"><?= e($monthLabel) ?></p>
    </div>
    <div class="card p-4">
        <p class="kpi-label">Monthly Revenue</p>
        <p class="kpi-value money text-ok-text"><?= e($symbol) ?> <?= e($money($monthRevenue)) ?></p>
        <p class="mt-1.5 text-[11.5px] text-slate-500">Received only, cash basis</p>
    </div>
    <div class="card p-4">
        <p class="kpi-label">Net Profit</p>
        <p class="kpi-value money <?= (float) $monthProfit < 0 ? 'text-bad-text' : 'text-ink' ?>">
            <?= e($symbol) ?> <?= e($money($monthProfit)) ?>
        </p>
        <p class="mt-1.5 text-[11.5px] text-slate-500">Revenue minus expenses</p>
    </div>

    <div class="card p-4">
        <p class="kpi-label">Budget Used</p>
        <?php if ((int) $budgetTotals['count'] === 0) : ?>
            <p class="kpi-value money text-slate-300">&mdash;</p>
            <p class="mt-1.5 text-[11.5px] text-slate-500">
                <a href="<?= e(url('/budgets')) ?>" class="underline">No budgets set</a>
            </p>
        <?php else : ?>
            <p class="kpi-value money text-ink"><?= e((string) $budgetTotals['utilisation_pct']) ?>%</p>
            <p class="mt-1.5 text-[11.5px] text-slate-500">
                <?= e($symbol) ?> <?= e($money($budgetTotals['spent'])) ?> of <?= e($money($budgetTotals['budgeted'])) ?>
            </p>
        <?php endif; ?>
    </div>
    <div class="card p-4">
        <p class="kpi-label">Budget Remaining</p>
        <?php if ((int) $budgetTotals['count'] === 0) : ?>
            <p class="kpi-value money text-slate-300">&mdash;</p>
            <p class="mt-1.5 text-[11.5px] text-slate-500">Across <?= (int) $budgetTotals['count'] ?> budgets</p>
        <?php else : ?>
            <p class="kpi-value money <?= (float) $budgetTotals['remaining'] < 0 ? 'text-bad-text' : 'text-ink' ?>">
                <?= e($symbol) ?> <?= e($money($budgetTotals['remaining'])) ?>
            </p>
            <p class="mt-1.5 text-[11.5px] text-slate-500">Across <?= (int) $budgetTotals['count'] ?> budgets</p>
        <?php endif; ?>
    </div>
    <div class="card p-4 sm:col-span-2">
        <p class="kpi-label">Account Balance</p>
        <p class="kpi-value money text-ink"><?= e($symbol) ?> <?= e($money($accountBalances['total'])) ?></p>
        <?php if ($accountBalances['accounts'] === []) : ?>
            <p class="mt-1.5 text-[11.5px] text-slate-500">
                <a href="<?= e(url('/accounts')) ?>" class="underline">No active accounts</a>
            </p>
        <?php else : ?>
            <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1">
                <?php foreach ($accountBalances['accounts'] as $account) : ?>
                    <p class="money text-[11px] text-slate-500">
                        <?= e((string) $account['name']) ?>:
                        <span class="font-medium text-slate-700"><?= e($money($account['balance'])) ?></span>
                    </p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Charts -->
<div id="dashboard-charts"
     data-trend-url="<?= e(url('/dashboard/charts/trend')) ?>"
     data-category-url="<?= e(url('/dashboard/charts/category') . '?month=' . urlencode($monthValue)) ?>"
     data-budget-url="<?= e(url('/dashboard/charts/budget') . '?month=' . urlencode($monthValue)) ?>"
     class="mt-5 grid gap-4 xl:grid-cols-[1fr_360px]">
    <div class="card p-4">
        <h2 class="font-display text-sm font-semibold text-ink">Revenue vs Expenses</h2>
        <p class="text-[11.5px] text-slate-500">Last 6 months</p>
        <div id="chart-trend" class="mt-2"></div>
    </div>
    <div class="card p-4">
        <h2 class="font-display text-sm font-semibold text-ink">Expense by Category</h2>
        <p class="text-[11.5px] text-slate-500"><?= e($monthLabel) ?></p>
        <div id="chart-category" class="mt-2"></div>
    </div>
</div>

<div class="card mt-4 p-4">
    <h2 class="font-display text-sm font-semibold text-ink">Budget vs Actual</h2>
    <p class="text-[11.5px] text-slate-500"><?= e($monthLabel) ?></p>
    <div id="chart-budget" class="mt-2"></div>
</div>

<!-- Recent Transactions + Alerts -->
<div class="mt-5 grid gap-4 xl:grid-cols-[1fr_360px]">
    <div class="card overflow-hidden">
        <div class="flex items-center justify-between px-5 pt-4 pb-3">
            <h2 class="font-display text-sm font-semibold text-ink">Recent Transactions</h2>
            <a href="<?= e(url('/transactions')) ?>" class="text-[11.5px] font-medium text-brand-700 hover:underline">View all</a>
        </div>
        <table class="w-full border-collapse">
            <tbody>
                <?php if ($recentTransactions === []) : ?>
                    <tr><td class="td py-8 text-center text-sm text-slate-500" colspan="3">Nothing recorded yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($recentTransactions as $row) : ?>
                    <?php
                    $type = TransactionType::tryFrom((string) $row['type']);
                    $signed = ((int) $row['direction'] === 1 ? '+' : '−') . $money($row['amount']);
                    ?>
                    <tr>
                        <td class="td">
                            <p class="text-xs font-medium text-ink"><?= e((string) $row['description']) ?></p>
                            <p class="mt-0.5 flex items-center gap-1.5 text-[10.5px] text-slate-400">
                                <?= e(date('j M', (int) strtotime((string) $row['transaction_date']))) ?>
                                &middot; <?= e((string) $row['account_name']) ?>
                            </p>
                        </td>
                        <td class="td">
                            <?php if ($type !== null) : ?>
                                <span class="<?= $typeBadge[$type->value] ?? 'badge-mute' ?>"><?= e($type->label()) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="td money text-right text-xs font-semibold <?= (int) $row['direction'] === 1 ? 'text-ok-text' : 'text-ink' ?>">
                            <?= e($signed) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card p-5">
        <h2 class="font-display text-sm font-semibold text-ink">Alerts</h2>
        <p class="text-[11.5px] text-slate-500">Budgets needing attention</p>

        <?php if ($alerts === []) : ?>
            <p class="mt-4 rounded-lg bg-slate-50 px-3 py-3 text-xs text-slate-500">
                Nothing needs attention this month.
            </p>
        <?php else : ?>
            <div class="mt-3 flex flex-col gap-2.5">
                <?php foreach ($alerts as $alert) : ?>
                    <a href="<?= e(url('/budgets?year=' . $alert['year'] . '&month=' . $alert['month'])) ?>"
                       class="flex items-center justify-between gap-2 rounded-lg border border-slate-100 px-3 py-2.5 hover:border-slate-200 hover:bg-slate-50">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-xs font-medium text-ink"><?= e((string) $alert['category_name']) ?></span>
                            <span class="money text-[10.5px] text-slate-500"><?= e((string) $alert['utilisation_pct']) ?>% of budget</span>
                        </span>
                        <span class="<?= $alertBadge((string) $alert['state']) ?>">
                            <?= $alert['state'] === 'exceeded' ? 'Over budget' : 'Nearing limit' ?>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
