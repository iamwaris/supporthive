<?php

/**
 * @var list<array<string,mixed>>  $rules
 * @var list<array<string,mixed>>  $accounts
 * @var list<array{parent:array<string,mixed>,children:list<array<string,mixed>>}> $expenseTree
 * @var list<array{parent:array<string,mixed>,children:list<array<string,mixed>>}> $incomeTree
 * @var list<array<string,mixed>>  $customers
 * @var array<string,list<string>> $errors
 * @var array<string,mixed>|null   $authUser
 */

declare(strict_types=1);

use App\Services\Access;
use App\Services\Settings;

$errors = $errors ?? [];
$canEdit = Access::canManageMasterData((string) ($authUser['role'] ?? ''));
$symbol = Settings::string('currency_symbol', 'Rs');
$activeAccounts = array_values(array_filter($accounts, static fn (array $a): bool => (int) $a['is_active'] === 1));

$dayNames = [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];

$frequencyLabel = static function (array $rule) use ($dayNames): string {
    return match ((string) $rule['frequency']) {
        'daily' => 'Daily',
        'weekly' => 'Weekly on ' . ($dayNames[(int) $rule['day_of_week']] ?? ''),
        'monthly' => 'Monthly on day ' . (string) $rule['day_of_month'],
        'quarterly' => 'Quarterly on day ' . (string) $rule['day_of_month'],
        'yearly' => 'Yearly on day ' . (string) $rule['day_of_month'],
        default => ucfirst((string) $rule['frequency']),
    };
};

// Category options, one closure shared by the list-free "create" form and
// every row's inline edit form. Expense nests children under their parent
// in an optgroup (matching expense-form.php); income shows parents only
// (matching income-form.php) — this view reuses the same tree() model call
// both other entry forms use, just renders it the same way they each do.
$expenseOptions = static function (?string $selectedId) use ($expenseTree): void {
    foreach ($expenseTree as $node) {
        if ((int) $node['parent']['is_active'] !== 1) {
            continue;
        }
        if ($node['children'] === []) {
            $id = (string) $node['parent']['id'];
            echo '<option value="' . e($id) . '"' . ($selectedId === $id ? ' selected' : '') . '>'
                . e((string) $node['parent']['name']) . '</option>';
            continue;
        }
        echo '<optgroup label="' . e((string) $node['parent']['name']) . '">';
        $parentId = (string) $node['parent']['id'];
        echo '<option value="' . e($parentId) . '"' . ($selectedId === $parentId ? ' selected' : '') . '>'
            . e((string) $node['parent']['name']) . ' (general)</option>';
        foreach ($node['children'] as $child) {
            if ((int) $child['is_active'] !== 1) {
                continue;
            }
            $childId = (string) $child['id'];
            echo '<option value="' . e($childId) . '"' . ($selectedId === $childId ? ' selected' : '') . '>'
                . e((string) $child['name']) . '</option>';
        }
        echo '</optgroup>';
    }
};
$incomeOptions = static function (?string $selectedId) use ($incomeTree): void {
    foreach ($incomeTree as $node) {
        if ((int) $node['parent']['is_active'] !== 1) {
            continue;
        }
        $id = (string) $node['parent']['id'];
        echo '<option value="' . e($id) . '"' . ($selectedId === $id ? ' selected' : '') . '>'
            . e((string) $node['parent']['name']) . '</option>';
    }
};
?>

<div class="flex flex-col gap-5">
    <div class="grid gap-5 xl:grid-cols-[1fr_380px]">

        <div class="min-w-0 card overflow-hidden">
            <div class="px-5 pt-4 pb-3">
                <h2 class="font-display text-sm font-semibold text-ink">Rules</h2>
            </div>

            <div class="overflow-x-auto">
            <table class="w-full border-collapse">
                <thead>
                    <tr>
                        <th scope="col" class="th">Description</th>
                        <th scope="col" class="th">Type</th>
                        <th scope="col" class="th text-right">Amount</th>
                        <th scope="col" class="th">Schedule</th>
                        <th scope="col" class="th">Account</th>
                        <th scope="col" class="th">Category</th>
                        <th scope="col" class="th">Next due</th>
                        <th scope="col" class="th">Status</th>
                        <?php if ($canEdit) : ?>
                            <th scope="col" class="th"><span class="sr-only">Actions</span></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody x-data="{ openId: null }">
                    <?php if ($rules === []) : ?>
                        <tr>
                            <td class="td" colspan="9">
                                <p class="py-6 text-center text-sm text-slate-500">
                                    No recurring rules yet.<?= $canEdit ? ' Add one on the right.' : '' ?>
                                </p>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($rules as $rule) : ?>
                        <?php
                        $id = (int) $rule['id'];
                        $isActive = (int) $rule['is_active'] === 1;
                        $ruleType = (string) $rule['type'];
                        $ruleFrequency = (string) $rule['frequency'];
                        $nextDue = $rule['next_due_date'];
                        ?>
                        <tr>
                            <td class="td font-medium text-ink"><?= e((string) $rule['description']) ?></td>
                            <td class="td capitalize text-slate-600"><?= e($ruleType) ?></td>
                            <td class="td money text-right text-slate-600"><?= e($symbol) ?> <?= e(number_format((float) $rule['amount'], 2)) ?></td>
                            <td class="td text-slate-600"><?= e($frequencyLabel($rule)) ?></td>
                            <td class="td text-slate-600"><?= e((string) $rule['account_name']) ?></td>
                            <td class="td text-slate-600"><?= e((string) $rule['category_name']) ?></td>
                            <td class="td money text-slate-600">
                                <?= $nextDue !== null ? e((string) $nextDue) : '—' ?>
                            </td>
                            <td class="td">
                                <?php if ($isActive) : ?>
                                    <span class="badge-ok">Active</span>
                                <?php else : ?>
                                    <span class="badge-mute">Paused</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($canEdit) : ?>
                                <td class="td text-right whitespace-nowrap">
                                    <button type="button" x-on:click="openId = openId === <?= $id ?> ? null : <?= $id ?>"
                                            class="rounded-md px-2 py-1 text-[11.5px] font-medium text-brand-700 hover:bg-brand-50">Edit</button>
                                    <form method="post" action="<?= e(url('/recurring-rules/' . $id . '/toggle')) ?>" class="inline">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="rounded-md px-2 py-1 text-[11.5px] font-medium text-slate-600 hover:bg-slate-100">
                                            <?= $isActive ? 'Pause' : 'Resume' ?>
                                        </button>
                                    </form>
                                </td>
                            <?php endif; ?>
                        </tr>

                        <?php if ($canEdit) : ?>
                            <tr x-show="openId === <?= $id ?>" x-cloak>
                                <td class="border-b border-slate-100 bg-slate-50 px-3.5 py-4" colspan="9">
                                    <form method="post" action="<?= e(url('/recurring-rules/' . $id)) ?>"
                                          x-data="{ type: <?= e(json_encode($ruleType, JSON_THROW_ON_ERROR)) ?>, frequency: <?= e(json_encode($ruleFrequency, JSON_THROW_ON_ERROR)) ?> }"
                                          class="flex flex-col gap-3">
                                        <?= csrf_field() ?>

                                        <div class="grid gap-3 sm:grid-cols-3">
                                            <div>
                                                <label for="e-type-<?= $id ?>" class="label">Type</label>
                                                <select id="e-type-<?= $id ?>" name="type" x-model="type" class="input">
                                                    <option value="expense" <?= $ruleType === 'expense' ? 'selected' : '' ?>>Expense</option>
                                                    <option value="income" <?= $ruleType === 'income' ? 'selected' : '' ?>>Income</option>
                                                </select>
                                            </div>
                                            <div class="sm:col-span-2">
                                                <label for="e-description-<?= $id ?>" class="label">Description</label>
                                                <input id="e-description-<?= $id ?>" name="description" type="text" required maxlength="255"
                                                       value="<?= e((string) $rule['description']) ?>" class="input">
                                            </div>
                                        </div>

                                        <div class="grid gap-3 sm:grid-cols-3">
                                            <div>
                                                <label for="e-amount-<?= $id ?>" class="label">Amount (<?= e($symbol) ?>)</label>
                                                <input id="e-amount-<?= $id ?>" name="amount" type="text" inputmode="decimal" required
                                                       value="<?= e((string) $rule['amount']) ?>" class="input money">
                                            </div>
                                            <div>
                                                <label for="e-account-<?= $id ?>" class="label">Account</label>
                                                <select id="e-account-<?= $id ?>" name="account_id" required class="input">
                                                    <?php foreach ($activeAccounts as $account) : ?>
                                                        <option value="<?= e((string) $account['id']) ?>"
                                                            <?= (int) $account['id'] === (int) $rule['account_id'] ? 'selected' : '' ?>>
                                                            <?= e((string) $account['name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div>
                                                <label for="e-category-expense-<?= $id ?>" class="label">Category</label>
                                                <select id="e-category-expense-<?= $id ?>" name="category_id_expense" x-show="type === 'expense'" class="input">
                                                    <?php $expenseOptions($ruleType === 'expense' ? (string) $rule['category_id'] : null) ?>
                                                </select>
                                                <select id="e-category-income-<?= $id ?>" name="category_id_income" x-show="type === 'income'" class="input">
                                                    <?php $incomeOptions($ruleType === 'income' ? (string) $rule['category_id'] : null) ?>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="grid gap-3 sm:grid-cols-4">
                                            <div>
                                                <label for="e-frequency-<?= $id ?>" class="label">Frequency</label>
                                                <select id="e-frequency-<?= $id ?>" name="frequency" x-model="frequency" class="input">
                                                    <?php foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'yearly' => 'Yearly'] as $value => $label) : ?>
                                                        <option value="<?= $value ?>" <?= $ruleFrequency === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div x-show="frequency === 'monthly' || frequency === 'quarterly' || frequency === 'yearly'">
                                                <label for="e-dom-<?= $id ?>" class="label">Day of month</label>
                                                <input id="e-dom-<?= $id ?>" name="day_of_month" type="number" min="1" max="31"
                                                       value="<?= e((string) ($rule['day_of_month'] ?? '')) ?>" class="input">
                                            </div>
                                            <div x-show="frequency === 'weekly'">
                                                <label for="e-dow-<?= $id ?>" class="label">Day of week</label>
                                                <select id="e-dow-<?= $id ?>" name="day_of_week" class="input">
                                                    <?php foreach ($dayNames as $num => $label) : ?>
                                                        <option value="<?= $num ?>" <?= (int) ($rule['day_of_week'] ?? -1) === $num ? 'selected' : '' ?>><?= e($label) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div>
                                                <label for="e-start-<?= $id ?>" class="label">Start date</label>
                                                <input id="e-start-<?= $id ?>" name="start_date" type="date" required
                                                       value="<?= e((string) $rule['start_date']) ?>" class="input">
                                            </div>
                                            <div>
                                                <label for="e-end-<?= $id ?>" class="label">End date <span class="font-normal text-slate-400">optional</span></label>
                                                <input id="e-end-<?= $id ?>" name="end_date" type="date"
                                                       value="<?= e((string) ($rule['end_date'] ?? '')) ?>" class="input">
                                            </div>
                                        </div>

                                        <div class="grid gap-3 sm:grid-cols-3">
                                            <div x-show="type === 'expense'">
                                                <label for="e-vendor-<?= $id ?>" class="label">Vendor <span class="font-normal text-slate-400">optional</span></label>
                                                <input id="e-vendor-<?= $id ?>" name="vendor" type="text" maxlength="160"
                                                       value="<?= e((string) ($rule['vendor'] ?? '')) ?>" class="input">
                                            </div>
                                            <div x-show="type === 'income'">
                                                <label for="e-customer-<?= $id ?>" class="label">Customer <span class="font-normal text-slate-400">optional</span></label>
                                                <select id="e-customer-<?= $id ?>" name="customer_id" class="input">
                                                    <option value="">&mdash;</option>
                                                    <?php foreach ($customers as $customer) : ?>
                                                        <?php if ((int) $customer['is_active'] !== 1) :
                                                            continue;
                                                        endif; ?>
                                                        <option value="<?= e((string) $customer['id']) ?>"
                                                            <?= (int) ($rule['customer_id'] ?? 0) === (int) $customer['id'] ? 'selected' : '' ?>>
                                                            <?= e((string) $customer['name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div>
                                                <label for="e-reference-<?= $id ?>" class="label">Reference <span class="font-normal text-slate-400">optional</span></label>
                                                <input id="e-reference-<?= $id ?>" name="reference_no" type="text" maxlength="80"
                                                       value="<?= e((string) ($rule['reference_no'] ?? '')) ?>" class="input">
                                            </div>
                                        </div>

                                        <div>
                                            <label for="e-notes-<?= $id ?>" class="label">Notes <span class="font-normal text-slate-400">optional</span></label>
                                            <textarea id="e-notes-<?= $id ?>" name="notes" rows="2" maxlength="2000"
                                                      class="input h-auto py-2.5"><?= e((string) ($rule['notes'] ?? '')) ?></textarea>
                                        </div>

                                        <div class="flex gap-3">
                                            <button type="submit" class="btn-primary">Save</button>
                                            <button type="button" x-on:click="openId = null" class="btn-secondary">Cancel</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>

        <?php if ($canEdit) : ?>
            <div class="card p-5">
                <h2 class="font-display text-sm font-semibold text-ink">Add a recurring rule</h2>
                <p class="mt-0.5 text-[11.5px] text-slate-500">Generates a draft entry on schedule, for review before it is posted</p>

                <?php if ($activeAccounts === [] || ($expenseTree === [] && $incomeTree === [])) : ?>
                    <p class="mt-4 rounded-lg bg-slate-50 px-3 py-3 text-xs text-slate-500">
                        An account and at least one category are needed first &mdash;
                        <a href="<?= e(url('/accounts')) ?>" class="underline">accounts</a> /
                        <a href="<?= e(url('/categories')) ?>" class="underline">categories</a>.
                    </p>
                <?php else : ?>
                    <form method="post" action="<?= e(url('/recurring-rules')) ?>"
                          x-data="{ type: <?= e(json_encode(old('type', 'expense'), JSON_THROW_ON_ERROR)) ?>, frequency: <?= e(json_encode(old('frequency', 'monthly'), JSON_THROW_ON_ERROR)) ?> }"
                          class="mt-4 flex flex-col gap-3">
                        <?= csrf_field() ?>

                        <fieldset>
                            <legend class="label mb-1.5">Type</legend>
                            <div class="flex gap-2">
                                <label class="flex-1">
                                    <input type="radio" name="type" value="expense" x-model="type" class="peer sr-only">
                                    <span class="flex min-h-10 cursor-pointer items-center justify-center rounded-lg border border-slate-300 px-3 text-xs font-medium text-slate-600 peer-checked:border-brand-500 peer-checked:bg-brand-50 peer-checked:text-brand-900">
                                        Expense
                                    </span>
                                </label>
                                <label class="flex-1">
                                    <input type="radio" name="type" value="income" x-model="type" class="peer sr-only">
                                    <span class="flex min-h-10 cursor-pointer items-center justify-center rounded-lg border border-slate-300 px-3 text-xs font-medium text-slate-600 peer-checked:border-ok peer-checked:bg-ok-bg peer-checked:text-ok-text">
                                        Income
                                    </span>
                                </label>
                            </div>
                        </fieldset>

                        <div>
                            <label for="new-description" class="label">Description</label>
                            <input id="new-description" name="description" type="text" required maxlength="255"
                                   value="<?= e(old('description')) ?>"
                                   class="<?= isset($errors['description']) ? 'input-error' : 'input' ?>">
                            <?php if (isset($errors['description'][0])) : ?>
                                <p class="error"><?= e($errors['description'][0]) ?></p>
                            <?php endif; ?>
                        </div>

                        <div>
                            <label for="new-amount" class="label">Amount (<?= e($symbol) ?>)</label>
                            <input id="new-amount" name="amount" type="text" inputmode="decimal" required
                                   placeholder="0.00" value="<?= e(old('amount')) ?>"
                                   class="money <?= isset($errors['amount']) ? 'input-error' : 'input' ?>">
                            <?php if (isset($errors['amount'][0])) : ?>
                                <p class="error"><?= e($errors['amount'][0]) ?></p>
                            <?php endif; ?>
                        </div>

                        <div>
                            <label for="new-account" class="label">Account</label>
                            <select id="new-account" name="account_id" required
                                    class="<?= isset($errors['account_id']) ? 'input-error' : 'input' ?>">
                                <option value="">Choose one&hellip;</option>
                                <?php foreach ($activeAccounts as $account) : ?>
                                    <option value="<?= e((string) $account['id']) ?>"
                                        <?= old('account_id') === (string) $account['id'] ? 'selected' : '' ?>>
                                        <?= e((string) $account['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($errors['account_id'][0])) : ?>
                                <p class="error"><?= e($errors['account_id'][0]) ?></p>
                            <?php endif; ?>
                        </div>

                        <div>
                            <label for="new-category-expense" class="label">Category</label>
                            <select id="new-category-expense" name="category_id_expense" x-show="type === 'expense'"
                                    class="<?= isset($errors['category_id']) ? 'input-error' : 'input' ?>">
                                <option value="">Choose one&hellip;</option>
                                <?php $expenseOptions(old('category_id_expense')) ?>
                            </select>
                            <select id="new-category-income" name="category_id_income" x-show="type === 'income'"
                                    class="<?= isset($errors['category_id']) ? 'input-error' : 'input' ?>">
                                <option value="">Choose one&hellip;</option>
                                <?php $incomeOptions(old('category_id_income')) ?>
                            </select>
                            <?php if (isset($errors['category_id'][0])) : ?>
                                <p class="error"><?= e($errors['category_id'][0]) ?></p>
                            <?php endif; ?>
                        </div>

                        <div>
                            <label for="new-frequency" class="label">Frequency</label>
                            <select id="new-frequency" name="frequency" x-model="frequency"
                                    class="<?= isset($errors['frequency']) ? 'input-error' : 'input' ?>">
                                <?php foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'yearly' => 'Yearly'] as $value => $label) : ?>
                                    <option value="<?= $value ?>" <?= old('frequency', 'monthly') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div x-show="frequency === 'monthly' || frequency === 'quarterly' || frequency === 'yearly'">
                            <label for="new-dom" class="label">Day of month</label>
                            <input id="new-dom" name="day_of_month" type="number" min="1" max="31"
                                   value="<?= e(old('day_of_month')) ?>"
                                   class="<?= isset($errors['day_of_month']) ? 'input-error' : 'input' ?>">
                            <?php if (isset($errors['day_of_month'][0])) : ?>
                                <p class="error"><?= e($errors['day_of_month'][0]) ?></p>
                            <?php endif; ?>
                        </div>

                        <div x-show="frequency === 'weekly'">
                            <label for="new-dow" class="label">Day of week</label>
                            <select id="new-dow" name="day_of_week"
                                    class="<?= isset($errors['day_of_week']) ? 'input-error' : 'input' ?>">
                                <option value="">Choose one&hellip;</option>
                                <?php foreach ($dayNames as $num => $label) : ?>
                                    <option value="<?= $num ?>" <?= old('day_of_week') === (string) $num ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($errors['day_of_week'][0])) : ?>
                                <p class="error"><?= e($errors['day_of_week'][0]) ?></p>
                            <?php endif; ?>
                        </div>

                        <div>
                            <label for="new-start" class="label">Start date</label>
                            <input id="new-start" name="start_date" type="date" required
                                   value="<?= e(old('start_date', date('Y-m-d'))) ?>"
                                   class="<?= isset($errors['start_date']) ? 'input-error' : 'input' ?>">
                        </div>

                        <div>
                            <label for="new-end" class="label">End date <span class="font-normal text-slate-400">optional</span></label>
                            <input id="new-end" name="end_date" type="date" value="<?= e(old('end_date')) ?>"
                                   class="<?= isset($errors['end_date']) ? 'input-error' : 'input' ?>">
                            <?php if (isset($errors['end_date'][0])) : ?>
                                <p class="error"><?= e($errors['end_date'][0]) ?></p>
                            <?php endif; ?>
                        </div>

                        <div x-show="type === 'expense'">
                            <label for="new-vendor" class="label">Vendor <span class="font-normal text-slate-400">optional</span></label>
                            <input id="new-vendor" name="vendor" type="text" maxlength="160" value="<?= e(old('vendor')) ?>" class="input">
                        </div>

                        <div x-show="type === 'income'">
                            <label for="new-customer" class="label">Customer <span class="font-normal text-slate-400">optional</span></label>
                            <select id="new-customer" name="customer_id" class="input">
                                <option value="">&mdash;</option>
                                <?php foreach ($customers as $customer) : ?>
                                    <?php if ((int) $customer['is_active'] !== 1) :
                                        continue;
                                    endif; ?>
                                    <option value="<?= e((string) $customer['id']) ?>"
                                        <?= old('customer_id') === (string) $customer['id'] ? 'selected' : '' ?>>
                                        <?= e((string) $customer['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="new-reference" class="label">Reference <span class="font-normal text-slate-400">optional</span></label>
                            <input id="new-reference" name="reference_no" type="text" maxlength="80"
                                   value="<?= e(old('reference_no')) ?>" class="input">
                        </div>

                        <div>
                            <label for="new-notes" class="label">Notes <span class="font-normal text-slate-400">optional</span></label>
                            <textarea id="new-notes" name="notes" rows="2" maxlength="2000"
                                      class="input h-auto py-2.5"><?= e(old('notes')) ?></textarea>
                        </div>

                        <button type="submit" class="btn-primary">Create rule</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
