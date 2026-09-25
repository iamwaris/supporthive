<?php

/**
 * Monthly income, spec §16. Posted income and, folded in per D-2/M6-9, the
 * pending income that has not been received yet — shown separately and
 * never added into the posted total.
 *
 * @var string $from
 * @var string $to
 * @var list<array<string,mixed>> $rows
 * @var string $total
 * @var list<array<string,mixed>> $pendingRows
 * @var string $pendingTotal
 * @var array<string,mixed>|null $authUser
 */

declare(strict_types=1);

use App\Services\Settings;

$symbol = Settings::string('currency_symbol', 'Rs');
$money = static fn (mixed $v): string => number_format((float) $v, 2);

$row = static function (array $r) use ($money): void {
    ?>
    <tr>
        <td class="td money whitespace-nowrap text-slate-500">
            <?= e(date('j M Y', (int) strtotime((string) $r['transaction_date']))) ?>
        </td>
        <td class="td font-medium text-ink"><?= e((string) $r['description']) ?></td>
        <td class="td text-slate-600"><?= e((string) ($r['category_name'] ?? '—')) ?></td>
        <td class="td text-slate-600"><?= e((string) $r['account_name']) ?></td>
        <td class="td money text-right font-medium text-ok-text"><?= e($money($r['amount'])) ?></td>
    </tr>
    <?php
};
?>

<div class="flex flex-col gap-5">
    <form method="get" action="<?= e(url('/reports/income')) ?>" class="card flex flex-wrap items-end gap-3 px-5 py-4">
        <div>
            <label for="f-from" class="label">From</label>
            <input id="f-from" name="from" type="date" value="<?= e($from) ?>" class="input">
        </div>
        <div>
            <label for="f-to" class="label">To</label>
            <input id="f-to" name="to" type="date" value="<?= e($to) ?>" class="input">
        </div>
        <button type="submit" class="btn-dark">Apply</button>
        <a href="<?= e(url('/reports/income') . '?' . http_build_query(['from' => $from, 'to' => $to, 'format' => 'csv'])) ?>"
           class="btn-secondary ml-auto">Export CSV</a>
        <a href="<?= e(url('/reports/income') . '?' . http_build_query(['from' => $from, 'to' => $to, 'format' => 'pdf'])) ?>"
           class="btn-secondary">Export PDF</a>
    </form>

    <div class="card px-5 py-4">
        <p class="kpi-label">Received income</p>
        <p class="money mt-1.5 font-display text-xl font-semibold text-ok-text"><?= e($symbol) ?> <?= e($money($total)) ?></p>
    </div>

    <div class="card overflow-hidden">
        <div class="px-5 pt-4 pb-3">
            <h2 class="font-display text-sm font-semibold text-ink">Received</h2>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full border-collapse">
            <thead>
                <tr>
                    <th scope="col" class="th">Date</th>
                    <th scope="col" class="th">Description</th>
                    <th scope="col" class="th">Category</th>
                    <th scope="col" class="th">Account</th>
                    <th scope="col" class="th text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []) : ?>
                    <tr><td class="td py-8 text-center text-sm text-slate-500" colspan="5">No income received in this period.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r) :
                    $row($r);
                endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

    <div class="card overflow-hidden">
        <div class="flex items-center justify-between px-5 pt-4 pb-3">
            <div>
                <h2 class="font-display text-sm font-semibold text-ink">Expected income</h2>
                <p class="text-[11.5px] text-slate-500">Invoiced, not yet received &mdash; excluded from revenue (cash basis)</p>
            </div>
            <span class="money text-sm font-semibold text-slate-600"><?= e($symbol) ?> <?= e($money($pendingTotal)) ?></span>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full border-collapse">
            <thead>
                <tr>
                    <th scope="col" class="th">Date</th>
                    <th scope="col" class="th">Description</th>
                    <th scope="col" class="th">Category</th>
                    <th scope="col" class="th">Account</th>
                    <th scope="col" class="th text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($pendingRows === []) : ?>
                    <tr><td class="td py-8 text-center text-sm text-slate-500" colspan="5">Nothing pending in this period.</td></tr>
                <?php endif; ?>
                <?php foreach ($pendingRows as $r) :
                    $row($r);
                endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
