<?php

/**
 * @var string $from
 * @var string $to
 * @var list<array<string,mixed>> $rows
 * @var array<string,mixed>|null $authUser
 */

declare(strict_types=1);

use App\Services\Settings;

$symbol = Settings::string('currency_symbol', 'Rs');
$money = static fn (mixed $v): string => number_format((float) $v, 2);
$total = array_reduce($rows, static fn (float $sum, array $r): float => $sum + (float) $r['total'], 0.0);
?>

<div class="flex flex-col gap-5">
    <form method="get" action="<?= e(url('/reports/partner-withdrawals')) ?>" class="card flex flex-wrap items-end gap-3 px-5 py-4">
        <div>
            <label for="f-from" class="label">From</label>
            <input id="f-from" name="from" type="date" value="<?= e($from) ?>" class="input">
        </div>
        <div>
            <label for="f-to" class="label">To</label>
            <input id="f-to" name="to" type="date" value="<?= e($to) ?>" class="input">
        </div>
        <button type="submit" class="btn-dark">Apply</button>
        <a href="<?= e(url('/reports/partner-withdrawals') . '?' . http_build_query(['from' => $from, 'to' => $to, 'format' => 'csv'])) ?>"
           class="btn-secondary ml-auto">Export CSV</a>
        <a href="<?= e(url('/reports/partner-withdrawals') . '?' . http_build_query(['from' => $from, 'to' => $to, 'format' => 'pdf'])) ?>"
           class="btn-secondary">Export PDF</a>
    </form>

    <div class="card overflow-hidden">
        <div class="flex items-center justify-between px-5 pt-4 pb-3">
            <h2 class="font-display text-sm font-semibold text-ink">Withdrawals by partner</h2>
            <span class="money text-sm font-semibold text-ink"><?= e($symbol) ?> <?= e($money($total)) ?></span>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full border-collapse">
            <thead>
                <tr>
                    <th scope="col" class="th">Partner</th>
                    <th scope="col" class="th text-right">Amount</th>
                    <th scope="col" class="th text-right">Entries</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []) : ?>
                    <tr><td class="td py-8 text-center text-sm text-slate-500" colspan="3">No withdrawals in this period.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row) : ?>
                    <tr>
                        <td class="td font-medium text-ink"><?= e((string) $row['partner_name']) ?></td>
                        <td class="td money text-right text-slate-600"><?= e($money($row['total'])) ?></td>
                        <td class="td money text-right text-slate-500"><?= e((string) $row['entries']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
