<?php

/**
 * @var list<array<string,mixed>> $batches   each + 'rows' => list<array<string,mixed>>
 * @var list<array<string,mixed>> $accounts
 * @var bool                      $splitIsValid
 * @var array<int,int>            $currentSplit
 * @var array<string,list<string>> $errors
 * @var array<string,mixed>|null  $authUser
 */

declare(strict_types=1);

use App\Services\Access;
use App\Services\Settings;
use App\Services\ShareService;

$errors = $errors ?? [];
$canDistribute = Access::canDistributeProfit((string) ($authUser['role'] ?? ''));
$symbol = Settings::string('currency_symbol', 'Rs');
$money = static fn (mixed $v): string => number_format((float) $v, 2);
$activeAccounts = array_values(array_filter($accounts, static fn (array $a): bool => (int) $a['is_active'] === 1));

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

<div class="grid gap-5 xl:grid-cols-[1fr_360px]">

    <div class="flex min-w-0 flex-col gap-5">
        <?php if ($batches === []) : ?>
            <div class="card px-5 py-8 text-center text-sm text-slate-500">
                No distributions calculated yet.
            </div>
        <?php endif; ?>

        <?php foreach ($batches as $batch) : ?>
            <?php
            $status = (string) $batch['status'];
            $periodLabel = date('j M Y', (int) strtotime((string) $batch['period_start']))
                . ' – ' . date('j M Y', (int) strtotime((string) $batch['period_end']));
            ?>
            <div class="card overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-3 px-5 pt-4 pb-3">
                    <div>
                        <h2 class="font-display text-sm font-semibold text-ink"><?= e($periodLabel) ?></h2>
                        <p class="money mt-0.5 text-[11px] text-slate-500">
                            <?= e($symbol) ?> <?= e($money($batch['total_amount'])) ?> across <?= (int) $batch['partner_count'] ?> partner(s)
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="<?= $statusBadge($status) ?>"><?= e($statusLabel($status)) ?></span>

                        <?php if ($canDistribute && $status === 'calculated') : ?>
                            <form method="post" action="<?= e(url('/profit-distributions/' . $batch['batch_id'] . '/approve')) ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn-secondary">Approve</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

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
                        <?php foreach ($batch['rows'] as $row) : ?>
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

                <?php if ($canDistribute && $status === 'approved') : ?>
                    <?php if ($activeAccounts === []) : ?>
                        <p class="px-5 pb-4 text-xs text-slate-500">Needs at least one active account to pay out from.</p>
                    <?php else : ?>
                        <form method="post" action="<?= e(url('/profit-distributions/' . $batch['batch_id'] . '/distribute')) ?>"
                              class="flex flex-wrap items-end gap-3 border-t border-slate-100 px-5 py-4">
                            <?= csrf_field() ?>
                            <div>
                                <label for="acct-<?= e((string) $batch['batch_id']) ?>" class="label">Pay out from</label>
                                <select id="acct-<?= e((string) $batch['batch_id']) ?>" name="account_id" required class="input">
                                    <?php foreach ($activeAccounts as $account) : ?>
                                        <option value="<?= e((string) $account['id']) ?>"><?= e((string) $account['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn-primary">Distribute now</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($canDistribute) : ?>
        <div class="card p-5">
            <h2 class="font-display text-sm font-semibold text-ink">Calculate a new distribution</h2>

            <?php if (!$splitIsValid) : ?>
                <p class="mt-4 flex items-start gap-2 rounded-lg bg-bad-bg px-3 py-3 text-xs text-bad-text">
                    Ownership shares do not total 100% today. Set a valid split on the Partners page before
                    calculating a distribution that ends today or later.
                </p>
            <?php endif; ?>

            <form method="post" action="<?= e(url('/profit-distributions')) ?>" class="mt-4 flex flex-col gap-3">
                <?= csrf_field() ?>
                <div>
                    <label for="pd-start" class="label">Period start</label>
                    <input id="pd-start" name="period_start" type="date" required
                           value="<?= e(old('period_start')) ?>"
                           class="<?= isset($errors['period_start']) ? 'input-error' : 'input' ?>">
                    <?php if (isset($errors['period_start'][0])) : ?>
                        <p class="error"><?= e($errors['period_start'][0]) ?></p>
                    <?php endif; ?>
                </div>
                <div>
                    <label for="pd-end" class="label">Period end</label>
                    <input id="pd-end" name="period_end" type="date" required
                           value="<?= e(old('period_end')) ?>"
                           class="<?= isset($errors['period_end']) ? 'input-error' : 'input' ?>">
                    <?php if (isset($errors['period_end'][0])) : ?>
                        <p class="error"><?= e($errors['period_end'][0]) ?></p>
                    <?php endif; ?>
                </div>
                <button type="submit" class="btn-primary">Calculate</button>
            </form>

            <p class="mt-4 flex items-start gap-2 text-[11px] leading-relaxed text-slate-500">
                <svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="9"></circle><path d="M12 11v5"></path><path d="M12 8h.01"></path>
                </svg>
                Uses the ownership split in force on the period's last day. Calculating only creates a draft —
                nothing is paid until it is approved and then distributed.
            </p>
        </div>
    <?php endif; ?>
</div>
