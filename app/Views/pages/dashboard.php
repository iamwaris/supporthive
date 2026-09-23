<?php

/**
 * Dashboard shell.
 *
 * Tiles show genuine empty states rather than placeholder figures. Real
 * aggregates arrive with the ledger (M3) and the KPI queries (M6); inventing
 * numbers here would make the shell look finished and hide what is not.
 *
 * @var array<string,mixed>|null $authUser
 */

declare(strict_types=1);

use App\Services\Access;
use App\Services\Settings;

$role = (string) ($authUser['role'] ?? '');
$currency = Settings::string('currency_code', 'PKR');

$tiles = [
    ['label' => "Today's Expenses", 'meta' => 'No entries yet'],
    ['label' => 'Monthly Expenses', 'meta' => 'This month'],
    ['label' => 'Monthly Revenue', 'meta' => 'Received only'],
    ['label' => 'Net Profit', 'meta' => 'Cash basis'],
];
?>

<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <?php foreach ($tiles as $tile) : ?>
        <div class="card p-4">
            <p class="kpi-label"><?= e($tile['label']) ?></p>
            <p class="kpi-value money text-slate-300">&mdash;</p>
            <p class="mt-1.5 text-[11.5px] text-slate-500"><?= e($tile['meta']) ?></p>
        </div>
    <?php endforeach; ?>
</div>

<div class="card mt-5 flex flex-col items-center justify-center px-6 py-14 text-center">
    <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-slate-100">
        <svg class="h-6 w-6 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M4 19.5V5a2 2 0 0 1 2-2h11a2 2 0 0 1 2 2v14.5"></path>
            <path d="M4 19.5A1.5 1.5 0 0 0 5.5 21H19"></path>
            <path d="M8 13l3-3 2.5 2.5L17 8"></path>
        </svg>
    </div>

    <h2 class="mt-4 font-display text-lg font-semibold tracking-tight text-ink">Nothing recorded yet</h2>
    <p class="mt-2 max-w-md text-sm leading-relaxed text-slate-500">
        Charts, budget tracking and partner allocation appear here once there are
        transactions to read. Amounts will be shown in <?= e($currency) ?>.
    </p>

    <?php if (Access::canManageMasterData($role)) : ?>
        <p class="mt-5 max-w-sm text-xs leading-relaxed text-slate-500">
            Accounts, categories and partners come next &mdash; a transaction needs
            somewhere to sit before it can be recorded.
        </p>
        <a href="<?= e(url('/settings')) ?>" class="btn-dark mt-4">Review settings</a>
    <?php else : ?>
        <p class="mt-5 text-xs text-slate-500">
            Your administrator records transactions; this page fills in as they do.
        </p>
    <?php endif; ?>
</div>
