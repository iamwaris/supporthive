<?php

/**
 * The ledger listing.
 *
 * @var list<array<string,mixed>>  $rows
 * @var int                        $total
 * @var int                        $page
 * @var int                        $pages
 * @var int                        $perPage
 * @var string                     $inbound
 * @var string                     $outbound
 * @var string                     $net
 * @var array<string,mixed>        $filters
 * @var list<array<string,mixed>>  $accounts
 * @var list<array{parent:array<string,mixed>,children:list<array<string,mixed>>}> $categories
 * @var list<array{parent:array<string,mixed>,children:list<array<string,mixed>>}> $incomeCategories
 * @var list<array<string,mixed>>  $partners
 * @var array<string,string>       $types
 * @var array<string,mixed>|null   $authUser
 */

declare(strict_types=1);

use App\Domain\TransactionType;
use App\Services\Access;
use App\Services\Settings;

$canWrite = Access::canWriteTransactions((string) ($authUser['role'] ?? ''));
$symbol = Settings::string('currency_symbol', 'Rs');

$money = static fn (mixed $value): string => number_format((float) $value, 2);

/** Badge styling per type. Full class strings: Tailwind cannot see built-up names. */
$typeBadge = [
    'income' => 'badge-ok',
    'expense' => 'badge-bad',
    'transfer_in' => 'badge-mute',
    'transfer_out' => 'badge-mute',
    'partner_contribution' => 'badge bg-indigo-50 text-indigo-800',
    'partner_withdrawal' => 'badge bg-fuchsia-50 text-fuchsia-800',
    'profit_distribution' => 'badge-warn',
];

/** Preserve the current filters when building a page link. */
$pageUrl = static function (int $target) use ($filters): string {
    $query = array_filter(
        [
            'from' => $filters['from'],
            'to' => $filters['to'],
            'account_id' => $filters['account_id'],
            'category_id' => $filters['category_id'],
            'partner_id' => $filters['partner_id'],
            'type' => $filters['type'],
            'status' => $filters['status'] === 'posted' ? null : $filters['status'],
            'q' => $filters['q'],
            'min' => $filters['min'],
            'max' => $filters['max'],
            'page' => $target > 1 ? $target : null,
        ],
        static fn (mixed $v): bool => $v !== null && $v !== ''
    );

        return url('/transactions') . ($query === [] ? '' : '?' . http_build_query($query));
};
?>

<!-- ============ filters ============ -->
<form method="get" action="<?= e(url('/transactions')) ?>" class="card p-4">
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6">
        <div>
            <label for="f-from" class="label">From</label>
            <input id="f-from" name="from" type="date" value="<?= e((string) ($filters['from'] ?? '')) ?>" class="input">
        </div>
        <div>
            <label for="f-to" class="label">To</label>
            <input id="f-to" name="to" type="date" value="<?= e((string) ($filters['to'] ?? '')) ?>" class="input">
        </div>
        <div>
            <label for="f-type" class="label">Type</label>
            <select id="f-type" name="type" class="input">
                <option value="">All types</option>
                <?php foreach ($types as $value => $label) : ?>
                    <option value="<?= e($value) ?>" <?= $filters['type'] === $value ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="f-account" class="label">Account</label>
            <select id="f-account" name="account_id" class="input">
                <option value="">All accounts</option>
                <?php foreach ($accounts as $account) : ?>
                    <option value="<?= e((string) $account['id']) ?>"
                        <?= (int) ($filters['account_id'] ?? 0) === (int) $account['id'] ? 'selected' : '' ?>>
                        <?= e((string) $account['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="f-category" class="label">Category</label>
            <select id="f-category" name="category_id" class="input">
                <option value="">All categories</option>
                <optgroup label="Expense">
                    <?php foreach ($categories as $node) : ?>
                        <option value="<?= e((string) $node['parent']['id']) ?>"
                            <?= (int) ($filters['category_id'] ?? 0) === (int) $node['parent']['id'] ? 'selected' : '' ?>>
                            <?= e((string) $node['parent']['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </optgroup>
                <optgroup label="Income">
                    <?php foreach ($incomeCategories as $node) : ?>
                        <option value="<?= e((string) $node['parent']['id']) ?>"
                            <?= (int) ($filters['category_id'] ?? 0) === (int) $node['parent']['id'] ? 'selected' : '' ?>>
                            <?= e((string) $node['parent']['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </optgroup>
            </select>
            <p class="help">Includes subcategories</p>
        </div>
        <div>
            <label for="f-status" class="label">Status</label>
            <select id="f-status" name="status" class="input">
                <option value="posted" <?= $filters['status'] === 'posted' ? 'selected' : '' ?>>Posted only</option>
                <option value="all" <?= $filters['status'] === 'all' ? 'selected' : '' ?>>Include voided</option>
                <option value="void" <?= $filters['status'] === 'void' ? 'selected' : '' ?>>Voided only</option>
            </select>
        </div>
        <div class="sm:col-span-2">
            <label for="f-q" class="label">Search</label>
            <input id="f-q" name="q" type="search" placeholder="Description or reference"
                   value="<?= e((string) ($filters['q'] ?? '')) ?>" class="input">
        </div>
        <div>
            <label for="f-min" class="label">Min amount</label>
            <input id="f-min" name="min" type="text" inputmode="decimal"
                   value="<?= e((string) ($filters['min'] ?? '')) ?>" class="input money">
        </div>
        <div>
            <label for="f-max" class="label">Max amount</label>
            <input id="f-max" name="max" type="text" inputmode="decimal"
                   value="<?= e((string) ($filters['max'] ?? '')) ?>" class="input money">
        </div>
        <div>
            <label for="f-partner" class="label">Partner</label>
            <select id="f-partner" name="partner_id" class="input">
                <option value="">Any</option>
                <?php foreach ($partners as $partner) : ?>
                    <option value="<?= e((string) $partner['id']) ?>"
                        <?= (int) ($filters['partner_id'] ?? 0) === (int) $partner['id'] ? 'selected' : '' ?>>
                        <?= e((string) $partner['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex items-end gap-2">
            <button type="submit" class="btn-dark flex-1">Apply</button>
            <a href="<?= e(url('/transactions')) ?>" class="btn-secondary">Clear</a>
        </div>
    </div>
</form>

<!-- ============ summary ============ -->
<div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="card flex items-baseline gap-2.5 px-4 py-3">
        <span class="kpi-label flex-1">Money in</span>
        <span class="money text-sm font-semibold text-ok-text"><?= e($money($inbound)) ?></span>
    </div>
    <div class="card flex items-baseline gap-2.5 px-4 py-3">
        <span class="kpi-label flex-1">Money out</span>
        <span class="money text-sm font-semibold text-bad-text"><?= e($money($outbound)) ?></span>
    </div>
    <div class="card flex items-baseline gap-2.5 px-4 py-3">
        <span class="kpi-label flex-1">Net</span>
        <span class="money text-sm font-semibold text-ink"><?= e($money($net)) ?></span>
    </div>
    <div class="flex items-baseline gap-2.5 rounded-card border border-slate-200 bg-slate-100 px-4 py-3">
        <span class="kpi-label flex-1">Matching</span>
        <span class="money text-sm font-semibold text-slate-600"><?= e(number_format($total)) ?></span>
    </div>
</div>

<!-- ============ rows ============ -->
<div class="card mt-4 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full border-collapse">
            <thead>
                <tr>
                    <th scope="col" class="th">Date</th>
                    <th scope="col" class="th">Description</th>
                    <th scope="col" class="th">Type</th>
                    <th scope="col" class="th">Category</th>
                    <th scope="col" class="th">Account</th>
                    <th scope="col" class="th">By</th>
                    <th scope="col" class="th text-right">Amount</th>
                    <?php if ($canWrite) : ?>
                        <th scope="col" class="th"><span class="sr-only">Actions</span></th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody x-data="{ openId: null }">
                <?php if ($rows === []) : ?>
                    <tr>
                        <td class="td" colspan="8">
                            <div class="py-10 text-center">
                                <p class="text-sm text-slate-600">
                                    <?= $total === 0 && ($filters['q'] ?? null) === null
                                        ? 'Nothing recorded yet.'
                                        : 'No transactions match these filters.' ?>
                                </p>
                                <?php if ($total === 0) : ?>
                                    <p class="mt-1 text-xs text-slate-500">
                                        Expenses and income arrive with the next module. Transfers can be
                                        recorded now.
                                    </p>
                                    <a href="<?= e(url('/transfers')) ?>" class="btn-secondary mt-4">Record a transfer</a>
                                <?php else : ?>
                                    <a href="<?= e(url('/transactions')) ?>" class="btn-secondary mt-4">Clear filters</a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($rows as $row) : ?>
                    <?php
                    $isVoid = (string) $row['status'] === 'void';
                    $type = TransactionType::from((string) $row['type']);
                    $signed = ((int) $row['direction'] === 1 ? '+' : '−') . $money($row['amount']);
                    $category = $row['parent_category_name'] !== null
                        ? (string) $row['parent_category_name'] . ' · ' . (string) $row['category_name']
                        : (string) ($row['category_name'] ?? '');
                    ?>
                    <?php $rowId = (int) $row['id']; ?>
                    <tr class="<?= $isVoid ? 'bg-bad-bg/40' : '' ?>">
                        <td class="td money whitespace-nowrap <?= $isVoid ? 'text-slate-400' : 'text-slate-500' ?>">
                            <?= e(date('j M Y', (int) strtotime((string) $row['transaction_date']))) ?>
                        </td>
                        <td class="td">
                            <p class="<?= $isVoid ? 'text-slate-400 line-through' : 'font-medium text-ink' ?>">
                                <?= e((string) $row['description']) ?>
                            </p>
                            <?php if (!empty($row['reference_no'])) : ?>
                                <p class="money text-[11px] text-slate-400"><?= e((string) $row['reference_no']) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($row['partner_name'])) : ?>
                                <p class="text-[11px] text-slate-400"><?= e((string) $row['partner_name']) ?></p>
                            <?php endif; ?>
                            <?php if ($isVoid) : ?>
                                <p class="mt-0.5 text-[11px] text-bad-text">
                                    Voided by <?= e((string) ($row['voided_by_name'] ?? 'unknown')) ?>
                                    &mdash; &ldquo;<?= e((string) $row['void_reason']) ?>&rdquo;
                                </p>
                            <?php endif; ?>
                            <?php if (!empty($row['transfer_group'])) : ?>
                                <p class="text-[11px] text-slate-400">part of a transfer &middot; nets to zero in P&amp;L</p>
                            <?php endif; ?>
                        </td>
                        <td class="td">
                            <span class="<?= $typeBadge[(string) $row['type']] ?? 'badge-mute' ?>">
                                <?= e($type->label()) ?>
                            </span>
                        </td>
                        <td class="td <?= $isVoid ? 'text-slate-400' : 'text-slate-600' ?>">
                            <?= $category === '' ? '<span class="text-slate-300">—</span>' : e($category) ?>
                        </td>
                        <td class="td <?= $isVoid ? 'text-slate-400' : 'text-slate-600' ?>">
                            <?= e((string) $row['account_name']) ?>
                        </td>
                        <td class="td text-slate-500"><?= e((string) $row['created_by_name']) ?></td>
                        <td class="td money text-right <?= $isVoid
                                ? 'text-slate-400 line-through'
                                : ((int) $row['direction'] === 1 ? 'font-medium text-ok-text' : 'font-medium text-ink') ?>">
                            <?= e($signed) ?>
                        </td>
                        <?php if ($canWrite) : ?>
                            <td class="td text-right">
                                <?php if (!$isVoid) : ?>
                                    <button type="button"
                                            x-on:click="openId = openId === <?= $rowId ?> ? null : <?= $rowId ?>"
                                            class="rounded-md px-2 py-1 text-[11.5px] font-medium text-bad-text hover:bg-bad-bg">
                                        Void
                                    </button>
                                <?php else : ?>
                                    <span class="badge-bad">Void</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>

                    <?php if ($canWrite && !$isVoid) : ?>
                        <tr x-show="openId === <?= $rowId ?>" x-cloak>
                            <td class="border-b border-slate-100 bg-bad-bg px-3.5 py-4" colspan="8">
                                <form method="post" action="<?= e(url('/transactions/' . $rowId . '/void')) ?>"
                                      class="flex flex-wrap items-end gap-3">
                                    <?= csrf_field() ?>
                                    <div class="min-w-64 flex-1">
                                        <label for="reason-<?= $rowId ?>" class="label">
                                            Why is this being voided?
                                        </label>
                                        <input id="reason-<?= $rowId ?>" name="void_reason" type="text"
                                               required maxlength="255" class="input"
                                               placeholder="e.g. duplicate of entry #4418">
                                        <p class="help">
                                            The row is kept with this reason. Nothing is deleted.
                                            <?php if (!empty($row['transfer_group'])) : ?>
                                                <strong class="font-medium text-bad-text">Both legs of the transfer will be voided.</strong>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <button type="submit" class="btn-primary">Void transaction</button>
                                    <button type="button" x-on:click="openId = null" class="btn-secondary">Cancel</button>
                                </form>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total > 0) : ?>
        <div class="flex flex-wrap items-center gap-3 border-t border-slate-200 bg-slate-50 px-4 py-3">
            <p class="flex-1 text-[11.5px] text-slate-500">
                Showing <span class="money"><?= e((string) (($page - 1) * $perPage + 1)) ?></span>&ndash;<span
                    class="money"><?= e((string) min($page * $perPage, $total)) ?></span>
                of <span class="money"><?= e(number_format($total)) ?></span>.
                Fetched a page at a time, not the whole table.
            </p>

            <?php if ($page > 1) : ?>
                <a href="<?= e($pageUrl($page - 1)) ?>" class="btn-secondary">Previous</a>
            <?php else : ?>
                <span class="btn-secondary pointer-events-none opacity-40">Previous</span>
            <?php endif; ?>

            <span class="money text-xs text-slate-600">
                <?= e((string) $page) ?> of <?= e((string) $pages) ?>
            </span>

            <?php if ($page < $pages) : ?>
                <a href="<?= e($pageUrl($page + 1)) ?>" class="btn-secondary">Next</a>
            <?php else : ?>
                <span class="btn-secondary pointer-events-none opacity-40">Next</span>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
