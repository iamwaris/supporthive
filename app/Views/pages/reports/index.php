<?php

/**
 * Reports landing page, spec §16 — a card per report.
 *
 * @var array<string,mixed>|null $authUser
 */

declare(strict_types=1);

$reports = [
    ['href' => '/reports/profit-loss', 'label' => 'Profit & Loss', 'description' => 'Revenue, expenses and net profit for a period.'],
    ['href' => '/reports/income', 'label' => 'Monthly Income', 'description' => 'Posted income, plus pending income shown separately.'],
    ['href' => '/reports/expenses', 'label' => 'Monthly Expenses', 'description' => 'Expenses for a period, with the category breakdown.'],
    ['href' => '/reports/expense-by-category', 'label' => 'Expense by Category', 'description' => 'Spending mix across every expense category.'],
    ['href' => '/reports/budget-vs-actual', 'label' => 'Budget vs Actual', 'description' => 'Every budgeted category against its live spend, one month.'],
    ['href' => '/reports/cash-flow', 'label' => 'Cash Flow', 'description' => 'Money in and out across every transaction type.'],
    ['href' => '/reports/account-balances', 'label' => 'Account Balances', 'description' => 'Derived balance of every active account, as of any date.'],
    ['href' => '/reports/partner-statement', 'label' => 'Partner Statement', 'description' => 'One partner\'s contributions, withdrawals and distributions.'],
    ['href' => '/reports/partner-contributions', 'label' => 'Partner Contributions', 'description' => 'Contributions for a period, grouped by partner.'],
    ['href' => '/reports/partner-withdrawals', 'label' => 'Partner Withdrawals', 'description' => 'Withdrawals for a period, grouped by partner.'],
    ['href' => '/reports/profit-distribution', 'label' => 'Profit Distribution', 'description' => 'Every calculated, approved and distributed batch.'],
    ['href' => '/reports/daily-transactions', 'label' => 'Daily Transaction Report', 'description' => 'Every posted transaction on a single day.'],
];
?>

<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
    <?php foreach ($reports as $report) : ?>
        <a href="<?= e(url($report['href'])) ?>" class="card flex flex-col gap-1 p-5 hover:border-slate-300">
            <h2 class="font-display text-sm font-semibold text-ink"><?= e($report['label']) ?></h2>
            <p class="text-[11.5px] text-slate-500"><?= e($report['description']) ?></p>
        </a>
    <?php endforeach; ?>
</div>
