<?php

/**
 * @var list<array<string,mixed>> $accounts
 * @var list<array{parent:array<string,mixed>,children:list<array<string,mixed>>}> $tree
 * @var list<array<string,mixed>> $customers
 * @var int                       $lastAccountId
 * @var array<string,list<string>> $errors
 */

declare(strict_types=1);

use App\Services\Settings;

$errors = $errors ?? [];
$symbol = Settings::string('currency_symbol', 'Rs');
$activeAccounts = array_values(array_filter($accounts, static fn (array $a): bool => (int) $a['is_active'] === 1));
$hasCategories = $tree !== [];
?>

<?php if (!$hasCategories || $activeAccounts === []) : ?>
    <div class="card p-6">
        <h2 class="font-display text-base font-semibold text-ink">Set up first</h2>
        <p class="mt-2 text-sm text-slate-600">Income needs an account and a category before it can be recorded.</p>
        <ul class="mt-3 flex flex-col gap-1.5 text-sm text-slate-600">
            <?php if ($activeAccounts === []) : ?>
                <li>• No active account to receive into &mdash; <a href="<?= e(url('/accounts')) ?>">add one</a></li>
            <?php endif; ?>
            <?php if (!$hasCategories) : ?>
                <li>• No income categories &mdash; <a href="<?= e(url('/categories')) ?>">add one</a></li>
            <?php endif; ?>
        </ul>
    </div>
<?php else : ?>
<form method="post" action="<?= e(url('/income')) ?>" novalidate
      x-data="{ status: '<?= e(old('payment_status', 'received')) ?>' }">
    <?= csrf_field() ?>

    <div class="grid gap-5 xl:grid-cols-[1fr_320px]">

        <div class="card p-6">

            <fieldset>
                <legend class="label mb-2">Has the money been received?</legend>
                <div class="flex gap-2">
                    <label class="flex-1">
                        <input type="radio" name="payment_status" value="received" x-model="status" class="peer sr-only">
                        <span class="flex min-h-11 cursor-pointer items-center justify-center rounded-lg border border-slate-300 px-4 text-sm font-medium text-slate-600 peer-checked:border-ok peer-checked:bg-ok-bg peer-checked:text-ok-text">
                            Received
                        </span>
                    </label>
                    <label class="flex-1">
                        <input type="radio" name="payment_status" value="pending" x-model="status" class="peer sr-only">
                        <span class="flex min-h-11 cursor-pointer items-center justify-center rounded-lg border border-slate-300 px-4 text-sm font-medium text-slate-600 peer-checked:border-brand-500 peer-checked:bg-brand-50 peer-checked:text-brand-900">
                            Pending
                        </span>
                    </label>
                </div>
                <p class="help" x-show="status === 'pending'" x-cloak>
                    Tracked as expected income. It will not count as revenue or reach the account balance
                    until you mark it received.
                </p>
            </fieldset>

            <div class="my-5 h-px bg-slate-100"></div>

            <div class="flex flex-wrap gap-5">
                <div class="w-full sm:w-48">
                    <label for="transaction_date" class="label" x-text="status === 'pending' ? 'Invoice date' : 'Date'"></label>
                    <input id="transaction_date" name="transaction_date" type="date" required
                           value="<?= e(old('transaction_date', date('Y-m-d'))) ?>" class="input">
                </div>

                <div class="min-w-48 flex-1">
                    <label for="amount" class="label">Amount (<?= e($symbol) ?>)</label>
                    <input id="amount" name="amount" type="text" inputmode="decimal" required
                           placeholder="0.00" value="<?= e(old('amount')) ?>"
                           class="money h-14 text-2xl font-medium tracking-tight
                                  <?= isset($errors['amount']) ? 'input-error' : 'input' ?>">
                    <?php if (isset($errors['amount'][0])) : ?>
                        <p class="error"><?= e($errors['amount'][0]) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="category_id" class="label">Revenue category</label>
                    <select id="category_id" name="category_id" required
                            class="<?= isset($errors['category_id']) ? 'input-error' : 'input' ?>">
                        <option value="">Choose one&hellip;</option>
                        <?php foreach ($tree as $node) : ?>
                            <?php if ((int) $node['parent']['is_active'] !== 1) : ?>
                                <?php continue; ?>
                            <?php endif; ?>
                            <option value="<?= e((string) $node['parent']['id']) ?>"
                                <?= old('category_id') === (string) $node['parent']['id'] ? 'selected' : '' ?>>
                                <?= e((string) $node['parent']['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['category_id'][0])) : ?>
                        <p class="error"><?= e($errors['category_id'][0]) ?></p>
                    <?php endif; ?>
                </div>

                <div>
                    <label for="account_id" class="label" x-text="status === 'pending' ? 'Will be received into' : 'Received into'"></label>
                    <select id="account_id" name="account_id" required class="input">
                        <?php foreach ($activeAccounts as $account) : ?>
                            <?php
                            $id = (string) $account['id'];
                            $selected = old('account_id') !== ''
                                ? old('account_id') === $id
                                : $lastAccountId === (int) $account['id'];
                            ?>
                            <option value="<?= e($id) ?>" <?= $selected ? 'selected' : '' ?>>
                                <?= e((string) $account['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="customer_id" class="label">
                        Customer <span class="font-normal text-slate-400">optional</span>
                    </label>
                    <select id="customer_id" name="customer_id" class="input">
                        <option value="">&mdash;</option>
                        <?php foreach ($customers as $customer) : ?>
                            <?php if ((int) $customer['is_active'] !== 1) : ?>
                                <?php continue; ?>
                            <?php endif; ?>
                            <option value="<?= e((string) $customer['id']) ?>"
                                <?= old('customer_id') === (string) $customer['id'] ? 'selected' : '' ?>>
                                <?= e((string) $customer['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="invoice_no" class="label">
                        Invoice / reference <span class="font-normal text-slate-400">optional</span>
                    </label>
                    <input id="invoice_no" name="invoice_no" type="text" maxlength="80"
                           value="<?= e(old('invoice_no')) ?>"
                           class="money <?= isset($errors['invoice_no']) ? 'input-error' : 'input' ?>">
                    <?php if (isset($errors['invoice_no'][0])) : ?>
                        <p class="error"><?= e($errors['invoice_no'][0]) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="mt-5">
                <label for="description" class="label">Description</label>
                <input id="description" name="description" type="text" required maxlength="255"
                       value="<?= e(old('description')) ?>"
                       class="<?= isset($errors['description']) ? 'input-error' : 'input' ?>">
                <?php if (isset($errors['description'][0])) : ?>
                    <p class="error"><?= e($errors['description'][0]) ?></p>
                <?php endif; ?>
            </div>

            <div class="mt-5">
                <label for="notes" class="label">Notes <span class="font-normal text-slate-400">optional</span></label>
                <textarea id="notes" name="notes" rows="2" maxlength="2000" class="input h-auto py-2.5"><?= e(old('notes')) ?></textarea>
            </div>

            <div class="mt-6 flex flex-wrap items-center gap-3 border-t border-slate-100 pt-5">
                <p class="flex-1 text-[11.5px] text-slate-500">Recorded against your account.</p>
                <a href="<?= e(url('/income')) ?>" class="btn-secondary">Cancel</a>
                <button type="submit" name="add_another" value="1" class="btn-secondary">Save &amp; add another</button>
                <button type="submit" class="btn-primary">Save income</button>
            </div>
        </div>

        <div class="card p-5">
            <h2 class="font-display text-[13px] font-semibold text-ink">Cash basis</h2>
            <p class="mt-2 text-[11.5px] leading-relaxed text-slate-600">
                Only money you have actually received counts as revenue or reaches an account
                balance. An invoice that has not been paid stays visible as expected income, and
                moves across the moment you mark it received &mdash; nothing is re-entered.
            </p>
        </div>
    </div>
</form>

<?php endif; ?>
