<?php

/**
 * @var list<array<string,mixed>> $batches
 * @var string|null $batchId
 * @var list<array<string,mixed>> $batchRows
 * @var array<string,mixed>|null $authUser
 */

declare(strict_types=1);

use App\Services\Settings;
use App\Services\ShareService;

$symbol = Settings::string('currency_symbol', 'Rs');
$money = static fn (mixed $v): string => number_format((float) $v, 2);

$statusBadge = static fn (string $status): string => match ($status) {
    'distributed' => 'badge-ok',
    'approved' => 'badge-warn',
    default => 'badge-mute',
};
$statusLabel = static fn (string $status): string => match ($status) {
    'distributed' => 'Distributed',
    'approved' => 'Approved',
    default => 'Calculated',
};
?>

<div class="flex flex-col gap-5">
    <div class="card overflow-hidden">
        <div class="flex items-center justify-between px-5 pt-4 pb-3">
            <h2 class="font-display text-sm font-semibold text-ink">Every batch</h2>
            <div class="flex gap-2">
                <a href="<?= e(url('/reports/profit-distribution?format=csv')) ?>" class="btn-secondary">Export CSV</a>
                <a href="<?= e(url('/reports/profit-distribution?format=pdf')) ?>" class="btn-secondary">Export PDF</a>
            </div>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full border-collapse">
            <thead>
                <tr>
                    <th scope="col" class="th">Period</th>
                    <th scope="col" class="th">Status</th>
                    <th scope="col" class="th text-right">Partners</th>
                    <th scope="col" class="th text-right">Amount</th>
                    <th scope="col" class="th"><span class="sr-only">Details</span></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($batches === []) : ?>
                    <tr><td class="td py-8 text-center text-sm text-slate-500" colspan="5">No distributions calculated yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($batches as $batch) : ?>
                    <?php
                    $periodLabel = date('j M Y', (int) strtotime((string) $batch['period_start']))
                        . ' – ' . date('j M Y', (int) strtotime((string) $batch['period_end']));
                    ?>
                    <tr class="<?= $batchId === $batch['batch_id'] ? 'bg-slate-50' : '' ?>">
                        <td class="td font-medium text-ink"><?= e($periodLabel) ?></td>
                        <td class="td"><span class="<?= $statusBadge((string) $batch['status']) ?>"><?= e($statusLabel((string) $batch['status'])) ?></span></td>
                        <td class="td money text-right text-slate-500"><?= (int) $batch['partner_count'] ?></td>
                        <td class="td money text-right text-slate-600"><?= e($money($batch['total_amount'])) ?></td>
                        <td class="td text-right">
                            <a href="<?= e(url('/reports/profit-distribution?batch=' . urlencode((string) $batch['batch_id']))) ?>"
                               class="text-[11.5px] font-medium text-brand-700 hover:underline">
                                View
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

    <?php if ($batchId !== null) : ?>
        <div class="card overflow-hidden">
            <div class="px-5 pt-4 pb-3">
                <h2 class="font-display text-sm font-semibold text-ink">Batch detail</h2>
            </div>
            <div class="overflow-x-auto">
            <table class="w-full border-collapse">
                <thead>
                    <tr>
                        <th scope="col" class="th">Partner</th>
                        <th scope="col" class="th text-right">Share</th>
                        <th scope="col" class="th text-right">Calculated</th>
                        <th scope="col" class="th text-right">Distributed</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($batchRows === []) : ?>
                        <tr><td class="td py-8 text-center text-sm text-slate-500" colspan="4">That batch was not found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($batchRows as $row) : ?>
                        <tr>
                            <td class="td font-medium text-ink"><?= e((string) $row['partner_name']) ?></td>
                            <td class="td money text-right text-slate-600"><?= e(ShareService::toPercent((int) $row['share_bp'])) ?>%</td>
                            <td class="td money text-right text-slate-600"><?= e($money($row['calculated_amount'])) ?></td>
                            <td class="td money text-right text-slate-600">
                                <?= $row['distributed_amount'] === null ? '—' : e($money($row['distributed_amount'])) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    <?php endif; ?>
</div>
