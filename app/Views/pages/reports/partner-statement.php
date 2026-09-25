<?php

/**
 * @var string $from
 * @var string $to
 * @var list<array<string,mixed>> $partners
 * @var int $partnerId
 * @var array{opening:string,rows:list<array<string,mixed>>,closing:string}|null $statement
 * @var array<string,mixed>|null $authUser
 */

declare(strict_types=1);

use App\Domain\TransactionType;
use App\Services\Settings;

$symbol = Settings::string('currency_symbol', 'Rs');
$money = static fn (mixed $v): string => number_format((float) $v, 2);

$typeBadge = [
    'partner_contribution' => 'badge bg-indigo-50 text-indigo-800',
    'partner_withdrawal' => 'badge bg-fuchsia-50 text-fuchsia-800',
    'profit_distribution' => 'badge-warn',
];
?>

<div class="flex flex-col gap-5">
    <form method="get" action="<?= e(url('/reports/partner-statement')) ?>" class="card flex flex-wrap items-end gap-3 px-5 py-4">
        <div>
            <label for="f-partner" class="label">Partner</label>
            <select id="f-partner" name="partner_id" class="input">
                <?php foreach ($partners as $partner) : ?>
                    <option value="<?= e((string) $partner['id']) ?>" <?= $partnerId === (int) $partner['id'] ? 'selected' : '' ?>>
                        <?= e((string) $partner['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="f-from" class="label">From</label>
            <input id="f-from" name="from" type="date" value="<?= e($from) ?>" class="input">
        </div>
        <div>
            <label for="f-to" class="label">To</label>
            <input id="f-to" name="to" type="date" value="<?= e($to) ?>" class="input">
        </div>
        <button type="submit" class="btn-dark">Apply</button>
        <?php if ($statement !== null) : ?>
            <a href="<?= e(url('/reports/partner-statement') . '?' . http_build_query([
                'partner_id' => $partnerId, 'from' => $from, 'to' => $to, 'format' => 'csv',
            ])) ?>" class="btn-secondary ml-auto">Export CSV</a>
            <a href="<?= e(url('/reports/partner-statement') . '?' . http_build_query([
                'partner_id' => $partnerId, 'from' => $from, 'to' => $to, 'format' => 'pdf',
            ])) ?>" class="btn-secondary">Export PDF</a>
        <?php endif; ?>
    </form>

    <?php if ($partners === []) : ?>
        <div class="card px-5 py-8 text-center text-sm text-slate-500">No partners yet.</div>
    <?php elseif ($statement !== null) : ?>
        <div class="grid gap-3 sm:grid-cols-2">
            <div class="card px-5 py-4">
                <p class="kpi-label">Opening balance</p>
                <p class="money mt-1.5 font-display text-xl font-semibold text-ink"><?= e($symbol) ?> <?= e($money($statement['opening'])) ?></p>
            </div>
            <div class="card px-5 py-4">
                <p class="kpi-label">Closing balance</p>
                <p class="money mt-1.5 font-display text-xl font-semibold text-ink"><?= e($symbol) ?> <?= e($money($statement['closing'])) ?></p>
            </div>
        </div>

        <div class="card overflow-hidden">
            <div class="overflow-x-auto">
            <table class="w-full border-collapse">
                <thead>
                    <tr>
                        <th scope="col" class="th">Date</th>
                        <th scope="col" class="th">Description</th>
                        <th scope="col" class="th">Type</th>
                        <th scope="col" class="th text-right">Amount</th>
                        <th scope="col" class="th text-right">Running balance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($statement['rows'] === []) : ?>
                        <tr><td class="td py-8 text-center text-sm text-slate-500" colspan="5">No movements in this period.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($statement['rows'] as $row) : ?>
                        <?php
                        $type = TransactionType::from((string) $row['type']);
                        $signed = ((int) $row['direction'] === 1 ? '+' : '−') . $money($row['amount']);
                        ?>
                        <tr>
                            <td class="td money whitespace-nowrap text-slate-500">
                                <?= e(date('j M Y', (int) strtotime((string) $row['transaction_date']))) ?>
                            </td>
                            <td class="td font-medium text-ink"><?= e((string) $row['description']) ?></td>
                            <td class="td">
                                <span class="<?= $typeBadge[(string) $row['type']] ?? 'badge-mute' ?>"><?= e($type->label()) ?></span>
                            </td>
                            <td class="td money text-right font-medium <?= (int) $row['direction'] === 1 ? 'text-ok-text' : 'text-ink' ?>">
                                <?= e($signed) ?>
                            </td>
                            <td class="td money text-right text-slate-600"><?= e($money($row['running_balance'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    <?php endif; ?>
</div>
