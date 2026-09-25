<?php

/**
 * @var string $from
 * @var string $to
 * @var list<array<string,mixed>> $rows
 * @var string $total
 * @var list<array<string,mixed>> $byCategory
 * @var array<string,mixed>|null $authUser
 */

declare(strict_types=1);

use App\Services\Settings;

$symbol = Settings::string('currency_symbol', 'Rs');
$money = static fn (mixed $v): string => number_format((float) $v, 2);
?>

<div class="flex flex-col gap-5">
    <form method="get" action="<?= e(url('/reports/expenses')) ?>" class="card flex flex-wrap items-end gap-3 px-5 py-4">
        <div>
            <label for="f-from" class="label">From</label>
            <input id="f-from" name="from" type="date" value="<?= e($from) ?>" class="input">
        </div>
        <div>
            <label for="f-to" class="label">To</label>
            <input id="f-to" name="to" type="date" value="<?= e($to) ?>" class="input">
        </div>
        <button type="submit" class="btn-dark">Apply</button>
        <a href="<?= e(url('/reports/expenses') . '?' . http_build_query(['from' => $from, 'to' => $to, 'format' => 'csv'])) ?>"
           class="btn-secondary ml-auto">Export CSV</a>
        <a href="<?= e(url('/reports/expenses') . '?' . http_build_query(['from' => $from, 'to' => $to, 'format' => 'pdf'])) ?>"
           class="btn-secondary">Export PDF</a>
    </form>

    <div class="grid gap-5 xl:grid-cols-[1fr_320px]">
        <div class="card overflow-hidden">
            <div class="flex items-center justify-between px-5 pt-4 pb-3">
                <h2 class="font-display text-sm font-semibold text-ink">Expenses</h2>
                <span class="money text-sm font-semibold text-bad-text"><?= e($symbol) ?> <?= e($money($total)) ?></span>
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
                        <tr><td class="td py-8 text-center text-sm text-slate-500" colspan="5">No expenses in this period.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row) : ?>
                        <tr>
                            <td class="td money whitespace-nowrap text-slate-500">
                                <?= e(date('j M Y', (int) strtotime((string) $row['transaction_date']))) ?>
                            </td>
                            <td class="td font-medium text-ink"><?= e((string) $row['description']) ?></td>
                            <td class="td text-slate-600"><?= e((string) ($row['category_name'] ?? '—')) ?></td>
                            <td class="td text-slate-600"><?= e((string) $row['account_name']) ?></td>
                            <td class="td money text-right font-medium text-bad-text"><?= e($money($row['amount'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>

        <div class="card p-5">
            <h2 class="font-display text-sm font-semibold text-ink">By category</h2>
            <?php if ($byCategory === []) : ?>
                <p class="mt-3 text-xs text-slate-500">No expenses to break down.</p>
            <?php else : ?>
                <div class="mt-3 flex flex-col gap-2">
                    <?php foreach ($byCategory as $cat) : ?>
                        <div class="flex items-center justify-between gap-2 text-xs">
                            <span class="text-slate-600"><?= e((string) $cat['category_name']) ?></span>
                            <span class="money font-medium text-ink"><?= e($money($cat['total'])) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
