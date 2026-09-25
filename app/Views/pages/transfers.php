<?php

/**
 * @var list<array<string,mixed>>  $accounts
 * @var array<int,string>          $balances
 * @var list<array<string,mixed>>  $rows
 * @var array<string,list<string>> $errors
 * @var array<string,mixed>|null   $authUser
 */

declare(strict_types=1);

use App\Services\Access;
use App\Services\Settings;

$errors = $errors ?? [];
$canWrite = Access::canWriteTransactions((string) ($authUser['role'] ?? ''));
$symbol = Settings::string('currency_symbol', 'Rs');
$active = array_values(array_filter($accounts, static fn (array $a): bool => (int) $a['is_active'] === 1));
$money = static fn (mixed $v): string => number_format((float) $v, 2);
?>

<div class="grid gap-5 xl:grid-cols-[1fr_380px]">

    <div class="flex min-w-0 flex-col gap-5">

        <!-- balances, derived from the ledger -->
        <div class="card overflow-hidden">
            <div class="px-5 pt-4 pb-3">
                <h2 class="font-display text-sm font-semibold text-ink">Account balances</h2>
                <p class="mt-0.5 text-[11.5px] text-slate-500">
                    Opening balance plus every posted movement &mdash; calculated, never stored
                </p>
            </div>

            <table class="w-full border-collapse">
                <thead>
                    <tr>
                        <th scope="col" class="th">Account</th>
                        <th scope="col" class="th text-right">Opening</th>
                        <th scope="col" class="th text-right">Balance now</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($accounts === []) : ?>
                        <tr>
                            <td class="td" colspan="3">
                                <div class="py-8 text-center">
                                    <p class="text-sm text-slate-600">No accounts yet.</p>
                                    <p class="mt-1 text-xs text-slate-500">A transfer needs two of them.</p>
                                    <a href="<?= e(url('/accounts')) ?>" class="btn-secondary mt-4">Add accounts</a>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($accounts as $account) : ?>
                        <?php
                        $id = (int) $account['id'];
                        $isActive = (int) $account['is_active'] === 1;
                        $balance = (float) ($balances[$id] ?? 0);
                        ?>
                        <tr>
                            <td class="td">
                                <p class="<?= $isActive ? 'font-medium text-ink' : 'text-slate-400' ?>">
                                    <?= e((string) $account['name']) ?>
                                </p>
                                <?php if (!$isActive) : ?>
                                    <span class="badge-mute">Closed</span>
                                <?php endif; ?>
                            </td>
                            <td class="td money text-right text-slate-500">
                                <?= e($money($account['opening_balance'])) ?>
                            </td>
                            <td class="td money text-right text-sm font-semibold <?= $balance < 0 ? 'text-bad-text' : 'text-ink' ?>">
                                <?= e($symbol) ?> <?= e($money($balance)) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- recent transfers, one row per pair -->
        <div class="card overflow-hidden">
            <div class="px-5 pt-4 pb-3">
                <h2 class="font-display text-sm font-semibold text-ink">Recent transfers</h2>
                <p class="mt-0.5 text-[11.5px] text-slate-500">
                    Each is stored as two ledger legs; the outgoing one is listed here
                </p>
            </div>

            <table class="w-full border-collapse">
                <thead>
                    <tr>
                        <th scope="col" class="th">Date</th>
                        <th scope="col" class="th">Description</th>
                        <th scope="col" class="th">Out of</th>
                        <th scope="col" class="th text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows === []) : ?>
                        <tr>
                            <td class="td" colspan="4">
                                <p class="py-8 text-center text-sm text-slate-500">No transfers recorded yet.</p>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($rows as $row) : ?>
                        <tr>
                            <td class="td money whitespace-nowrap text-slate-500">
                                <?= e(date('j M Y', (int) strtotime((string) $row['transaction_date']))) ?>
                            </td>
                            <td class="td">
                                <p class="font-medium text-ink"><?= e((string) $row['description']) ?></p>
                                <?php if (!empty($row['reference_no'])) : ?>
                                    <p class="money text-[11px] text-slate-400"><?= e((string) $row['reference_no']) ?></p>
                                <?php endif; ?>
                            </td>
                            <td class="td text-slate-600"><?= e((string) $row['account_name']) ?></td>
                            <td class="td money text-right font-medium text-ink"><?= e($money($row['amount'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($rows !== []) : ?>
                <div class="border-t border-slate-200 bg-slate-50 px-4 py-3">
                    <a href="<?= e(url('/transactions?type=transfer_out')) ?>" class="text-xs font-medium">
                        See both legs in the ledger
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($canWrite) : ?>
        <div class="card p-5">
            <h2 class="font-display text-sm font-semibold text-ink">Record a transfer</h2>
            <p class="mt-0.5 text-[11.5px] text-slate-500">
                Posted as two legs so balances stay simple and profit is untouched
            </p>

            <?php if (count($active) < 2) : ?>
                <p class="mt-4 rounded-lg bg-slate-50 px-3 py-3 text-xs text-slate-500">
                    You need at least two active accounts.
                </p>
            <?php else : ?>
                <form method="post" action="<?= e(url('/transfers')) ?>" class="mt-4 flex flex-col gap-3">
                    <?= csrf_field() ?>

                    <div>
                        <label for="t-from" class="label">Out of</label>
                        <select id="t-from" name="from_account_id" class="input" required>
                            <?php foreach ($active as $account) : ?>
                                <option value="<?= e((string) $account['id']) ?>"
                                    <?= old('from_account_id') === (string) $account['id'] ? 'selected' : '' ?>>
                                    <?= e((string) $account['name']) ?>
                                    (<?= e($money($balances[(int) $account['id']] ?? 0)) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="t-to" class="label">Into</label>
                        <select id="t-to" name="to_account_id" class="input" required>
                            <?php foreach ($active as $index => $account) : ?>
                                <option value="<?= e((string) $account['id']) ?>"
                                    <?= old('to_account_id') === (string) $account['id']
                                        || (old('to_account_id') === '' && $index === 1) ? 'selected' : '' ?>>
                                    <?= e((string) $account['name']) ?>
                                    (<?= e($money($balances[(int) $account['id']] ?? 0)) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="help">Must differ from the account above.</p>
                    </div>

                    <div>
                        <label for="t-amount" class="label">Amount (<?= e($symbol) ?>)</label>
                        <input id="t-amount" name="amount" type="text" inputmode="decimal" required
                               placeholder="0.00" value="<?= e(old('amount')) ?>"
                               class="money text-lg <?= isset($errors['amount']) ? 'input-error' : 'input' ?>">
                        <?php if (isset($errors['amount'][0])) : ?>
                            <p class="error"><?= e($errors['amount'][0]) ?></p>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label for="t-date" class="label">Date</label>
                        <input id="t-date" name="transaction_date" type="date" required
                               value="<?= e(old('transaction_date', date('Y-m-d'))) ?>" class="input">
                    </div>

                    <div>
                        <label for="t-desc" class="label">Description</label>
                        <input id="t-desc" name="description" type="text" required maxlength="255"
                               placeholder="e.g. cash float to petty cash"
                               value="<?= e(old('description')) ?>"
                               class="<?= isset($errors['description']) ? 'input-error' : 'input' ?>">
                        <?php if (isset($errors['description'][0])) : ?>
                            <p class="error"><?= e($errors['description'][0]) ?></p>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label for="t-ref" class="label">Reference <span class="font-normal text-slate-400">optional</span></label>
                        <input id="t-ref" name="reference_no" type="text" maxlength="80"
                               value="<?= e(old('reference_no')) ?>" class="input money">
                    </div>

                    <button type="submit" class="btn-primary">Record transfer</button>

                    <p class="flex items-start gap-2 text-[11px] leading-relaxed text-slate-500">
                        <svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                            <circle cx="12" cy="12" r="9"></circle><path d="M12 11v5"></path><path d="M12 8h.01"></path>
                        </svg>
                        Voiding either leg later voids both, so a balance can never show money
                        leaving one account without arriving in the other.
                    </p>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
