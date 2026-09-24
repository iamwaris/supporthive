<?php

/**
 * @var string $date
 * @var list<array<string,mixed>> $rows
 * @var string $inbound
 * @var string $outbound
 * @var array<string,mixed>|null $authUser
 */

declare(strict_types=1);

use App\Domain\TransactionType;
use App\Services\Settings;

$symbol = Settings::string('currency_symbol', 'Rs');
$money = static fn (mixed $v): string => number_format((float) $v, 2);

$typeBadge = [
    'income' => 'badge-ok',
    'expense' => 'badge-bad',
    'transfer_in' => 'badge-mute',
    'transfer_out' => 'badge-mute',
    'partner_contribution' => 'badge bg-indigo-50 text-indigo-800',
    'partner_withdrawal' => 'badge bg-fuchsia-50 text-fuchsia-800',
    'profit_distribution' => 'badge-warn',
];
?>

<div class="flex flex-col gap-5">
    <form method="get" action="<?= e(url('/reports/daily-transactions')) ?>" class="card flex flex-wrap items-end gap-3 px-5 py-4">
        <div>
            <label for="f-date" class="label">Date</label>
            <input id="f-date" name="date" type="date" value="<?= e($date) ?>" class="input" x-on:change="$el.form.submit()">
        </div>
        <noscript><button type="submit" class="btn-secondary">Go</button></noscript>
        <a href="<?= e(url('/reports/daily-transactions') . '?' . http_build_query(['date' => $date, 'format' => 'csv'])) ?>"
           class="btn-secondary ml-auto">Export CSV</a>
        <a href="<?= e(url('/reports/daily-transactions') . '?' . http_build_query(['date' => $date, 'format' => 'pdf'])) ?>"
           class="btn-secondary">Export PDF</a>
    </form>

    <div class="grid gap-3 sm:grid-cols-3">
        <div class="card px-5 py-4">
            <p class="kpi-label">Money in</p>
            <p class="money mt-1.5 font-display text-xl font-semibold text-ok-text"><?= e($symbol) ?> <?= e($money($inbound)) ?></p>
        </div>
        <div class="card px-5 py-4">
            <p class="kpi-label">Money out</p>
            <p class="money mt-1.5 font-display text-xl font-semibold text-bad-text"><?= e($symbol) ?> <?= e($money($outbound)) ?></p>
        </div>
        <div class="card px-5 py-4">
            <p class="kpi-label">Net</p>
            <p class="money mt-1.5 font-display text-xl font-semibold text-ink">
                <?= e($symbol) ?> <?= e($money((float) $inbound - (float) $outbound)) ?>
            </p>
        </div>
    </div>

    <div class="card overflow-hidden">
        <table class="w-full border-collapse">
            <thead>
                <tr>
                    <th scope="col" class="th">Description</th>
                    <th scope="col" class="th">Type</th>
                    <th scope="col" class="th">Category</th>
                    <th scope="col" class="th">Account</th>
                    <th scope="col" class="th">By</th>
                    <th scope="col" class="th text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []) : ?>
                    <tr><td class="td py-8 text-center text-sm text-slate-500" colspan="6">Nothing posted on this date.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row) : ?>
                    <?php
                    $type = TransactionType::from((string) $row['type']);
                    $signed = ((int) $row['direction'] === 1 ? '+' : '−') . $money($row['amount']);
                    ?>
                    <tr>
                        <td class="td font-medium text-ink"><?= e((string) $row['description']) ?></td>
                        <td class="td">
                            <span class="<?= $typeBadge[(string) $row['type']] ?? 'badge-mute' ?>"><?= e($type->label()) ?></span>
                        </td>
                        <td class="td text-slate-600"><?= e((string) ($row['category_name'] ?? '—')) ?></td>
                        <td class="td text-slate-600"><?= e((string) $row['account_name']) ?></td>
                        <td class="td text-slate-500"><?= e((string) $row['created_by_name']) ?></td>
                        <td class="td money text-right font-medium <?= (int) $row['direction'] === 1 ? 'text-ok-text' : 'text-ink' ?>">
                            <?= e($signed) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
