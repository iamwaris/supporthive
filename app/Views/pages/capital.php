<?php

/**
 * @var list<array<string,mixed>> $contributions
 * @var list<array<string,mixed>> $withdrawals
 * @var string                    $contributedTotal
 * @var string                    $withdrawnTotal
 * @var list<array<string,mixed>> $partners
 * @var list<array<string,mixed>> $partnerTotals
 * @var list<array<string,mixed>> $accounts
 * @var array<string,list<string>> $errors
 * @var array<string,mixed>|null  $authUser
 */

declare(strict_types=1);

use App\Services\Access;
use App\Services\Settings;

$errors = $errors ?? [];
$canWrite = Access::canWriteTransactions((string) ($authUser['role'] ?? ''));
$symbol = Settings::string('currency_symbol', 'Rs');
$money = static fn (mixed $v): string => number_format((float) $v, 2);
$activeAccounts = array_values(array_filter($accounts, static fn (array $a): bool => (int) $a['is_active'] === 1));
?>

<div class="grid gap-5 xl:grid-cols-[1fr_360px]">

    <div class="flex flex-col gap-5">

        <!-- summary -->
        <div class="grid gap-3 sm:grid-cols-2">
            <div class="card px-5 py-4">
                <p class="kpi-label">Total contributed</p>
                <p class="money mt-1.5 font-display text-xl font-semibold text-ink"><?= e($symbol) ?> <?= e($money($contributedTotal)) ?></p>
                <p class="mt-1 text-[11px] text-slate-500">Raises the balance, never revenue</p>
            </div>
            <div class="card px-5 py-4">
                <p class="kpi-label">Total withdrawn</p>
                <p class="money mt-1.5 font-display text-xl font-semibold text-ink"><?= e($symbol) ?> <?= e($money($withdrawnTotal)) ?></p>
                <p class="mt-1 text-[11px] text-slate-500">Lowers the balance, never an expense</p>
            </div>
        </div>

        <!-- per partner -->
        <div class="card overflow-hidden">
            <div class="px-5 pt-4 pb-3">
                <h2 class="font-display text-sm font-semibold text-ink">Net capital by partner</h2>
            </div>
            <table class="w-full border-collapse">
                <thead>
                    <tr>
                        <th scope="col" class="th">Partner</th>
                        <th scope="col" class="th text-right">Contributed</th>
                        <th scope="col" class="th text-right">Withdrawn</th>
                        <th scope="col" class="th text-right">Net</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($partnerTotals === []) : ?>
                        <tr><td class="td" colspan="4"><p class="py-6 text-center text-sm text-slate-500">No partners yet.</p></td></tr>
                    <?php endif; ?>
                    <?php foreach ($partnerTotals as $row) : ?>
                        <?php $net = (float) $row['contributed'] - (float) $row['withdrawn']; ?>
                        <tr>
                            <td class="td font-medium text-ink"><?= e((string) $row['name']) ?></td>
                            <td class="td money text-right text-slate-600"><?= e($money($row['contributed'])) ?></td>
                            <td class="td money text-right text-slate-600"><?= e($money($row['withdrawn'])) ?></td>
                            <td class="td money text-right font-semibold <?= $net < 0 ? 'text-bad-text' : 'text-ink' ?>"><?= e($money($net)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- history -->
        <div class="grid gap-5 sm:grid-cols-2">
            <div class="card overflow-hidden">
                <div class="px-4 pt-3.5 pb-2.5">
                    <h3 class="font-display text-[13px] font-semibold text-ink">Contributions</h3>
                </div>
                <table class="w-full border-collapse">
                    <tbody>
                        <?php if ($contributions === []) : ?>
                            <tr><td class="td text-center text-xs text-slate-500" colspan="2">None yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($contributions as $row) : ?>
                            <tr>
                                <td class="td">
                                    <p class="text-xs font-medium text-ink"><?= e((string) $row['partner_name']) ?></p>
                                    <p class="money text-[10.5px] text-slate-400">
                                        <?= e(date('j M Y', (int) strtotime((string) $row['transaction_date']))) ?>
                                    </p>
                                </td>
                                <td class="td money text-right text-xs font-medium text-ink"><?= e($money($row['amount'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="card overflow-hidden">
                <div class="px-4 pt-3.5 pb-2.5">
                    <h3 class="font-display text-[13px] font-semibold text-ink">Withdrawals</h3>
                </div>
                <table class="w-full border-collapse">
                    <tbody>
                        <?php if ($withdrawals === []) : ?>
                            <tr><td class="td text-center text-xs text-slate-500" colspan="2">None yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($withdrawals as $row) : ?>
                            <tr>
                                <td class="td">
                                    <p class="text-xs font-medium text-ink"><?= e((string) $row['partner_name']) ?></p>
                                    <p class="money text-[10.5px] text-slate-400">
                                        <?= e(date('j M Y', (int) strtotime((string) $row['transaction_date']))) ?>
                                    </p>
                                </td>
                                <td class="td money text-right text-xs font-medium text-ink"><?= e($money($row['amount'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($canWrite) : ?>
        <div class="card p-5" x-data="{ movement: '<?= e(old('movement', 'contribution')) ?>' }">
            <h2 class="font-display text-sm font-semibold text-ink">Record a movement</h2>

            <?php if ($partners === [] || $activeAccounts === []) : ?>
                <p class="mt-4 rounded-lg bg-slate-50 px-3 py-3 text-xs text-slate-500">
                    Needs at least one active partner and one active account.
                </p>
            <?php else : ?>
                <form method="post" action="<?= e(url('/capital')) ?>" class="mt-4 flex flex-col gap-3">
                    <?= csrf_field() ?>

                    <div class="flex gap-2">
                        <label class="flex-1">
                            <input type="radio" name="movement" value="contribution" x-model="movement" class="peer sr-only">
                            <span class="flex min-h-11 cursor-pointer items-center justify-center rounded-lg border border-slate-300 px-3 text-xs font-medium text-slate-600 peer-checked:border-brand-500 peer-checked:bg-brand-50 peer-checked:text-brand-900">
                                Contribution in
                            </span>
                        </label>
                        <label class="flex-1">
                            <input type="radio" name="movement" value="withdrawal" x-model="movement" class="peer sr-only">
                            <span class="flex min-h-11 cursor-pointer items-center justify-center rounded-lg border border-slate-300 px-3 text-xs font-medium text-slate-600 peer-checked:border-fuchsia-500 peer-checked:bg-fuchsia-50 peer-checked:text-fuchsia-900">
                                Withdrawal out
                            </span>
                        </label>
                    </div>

                    <div>
                        <label for="partner_id" class="label">Partner</label>
                        <select id="partner_id" name="partner_id" required class="input">
                            <?php foreach ($partners as $partner) : ?>
                                <option value="<?= e((string) $partner['id']) ?>"
                                    <?= old('partner_id') === (string) $partner['id'] ? 'selected' : '' ?>>
                                    <?= e((string) $partner['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="account_id" class="label">Account</label>
                        <select id="account_id" name="account_id" required class="input">
                            <?php foreach ($activeAccounts as $account) : ?>
                                <option value="<?= e((string) $account['id']) ?>"
                                    <?= old('account_id') === (string) $account['id'] ? 'selected' : '' ?>>
                                    <?= e((string) $account['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="c-amount" class="label">Amount (<?= e($symbol) ?>)</label>
                        <input id="c-amount" name="amount" type="text" inputmode="decimal" required
                               placeholder="0.00" value="<?= e(old('amount')) ?>"
                               class="money <?= isset($errors['amount']) ? 'input-error' : 'input' ?>">
                        <?php if (isset($errors['amount'][0])) : ?>
                            <p class="error"><?= e($errors['amount'][0]) ?></p>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label for="c-date" class="label">Date</label>
                        <input id="c-date" name="transaction_date" type="date" required
                               value="<?= e(old('transaction_date', date('Y-m-d'))) ?>" class="input">
                    </div>

                    <div>
                        <label for="c-desc" class="label">Description</label>
                        <input id="c-desc" name="description" type="text" required maxlength="255"
                               value="<?= e(old('description')) ?>"
                               class="<?= isset($errors['description']) ? 'input-error' : 'input' ?>">
                        <?php if (isset($errors['description'][0])) : ?>
                            <p class="error"><?= e($errors['description'][0]) ?></p>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label for="c-ref" class="label">Reference <span class="font-normal text-slate-400">optional</span></label>
                        <input id="c-ref" name="reference_no" type="text" maxlength="80"
                               value="<?= e(old('reference_no')) ?>" class="input money">
                    </div>

                    <button type="submit" class="btn-primary">Record movement</button>
                </form>
            <?php endif; ?>

            <p class="mt-4 flex items-start gap-2 text-[11px] leading-relaxed text-slate-500">
                <svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="9"></circle><path d="M12 11v5"></path><path d="M12 8h.01"></path>
                </svg>
                Neither movement touches profit and loss. Ownership shares are set separately
                on the Partners page and are never used to split these.
            </p>
        </div>
    <?php endif; ?>
</div>
