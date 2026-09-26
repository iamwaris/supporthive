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
 * @var float|null                 $inShare  money in as % of money moved; null when nothing moved
 * @var string                     $rangeLabel
 * @var array<string,string>       $rangeOptions
 * @var array<string,mixed>        $filters
 * @var \App\Services\LedgerFilters $ledgerFilters
 * @var list<array{label:string,href:string}> $chips
 * @var list<array<string,mixed>>  $accounts
 * @var list<array{parent:array<string,mixed>,children:list<array<string,mixed>>}> $categories
 * @var list<array{parent:array<string,mixed>,children:list<array<string,mixed>>}> $incomeCategories
 * @var list<array<string,mixed>>  $partners
 * @var array<string,string>       $types
 * @var array<int,list<array<string,mixed>>> $attachments keyed by transaction_id
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

$netValue = (float) $net;
$netSign = $netValue > 0 ? '+' : ($netValue < 0 ? '−' : '');
$netTone = $netValue > 0 ? 'text-ok-text' : ($netValue < 0 ? 'text-bad-text' : 'text-ink');
$netAmount = $netSign . $symbol . ' ' . $money(abs($netValue));
$countLabel = $total === 1 ? '1 transaction' : number_format($total) . ' transactions';
$panelCount = $ledgerFilters->panelCount();
$outShare = $inShare === null ? null : round(100 - $inShare, 1);
$shareSummary = $inShare === null
    ? 'No money moved in this range'
    : 'Money in ' . $inShare . '% and money out ' . $outShare . '% of all money moved';
?>

<!--
    One Alpine scope around the whole page: the sticky totals bar's container
    must be this tall to stay stuck, and it has to see the hero to observe it.
-->
<div x-data="ledgerStickyTotals">

<!-- ============ totals ============ -->
<section x-ref="hero" aria-labelledby="totals-heading" class="card p-4 sm:p-5">
    <h2 id="totals-heading" class="sr-only">Totals for these transactions</h2>
    <div class="flex flex-col gap-5 md:flex-row md:items-end md:justify-between md:gap-8">
        <div class="min-w-0">
            <p class="kpi-label">Net</p>
            <p class="money mt-1 text-3xl font-semibold tracking-tight sm:text-4xl <?= $netTone ?>">
                <?= e($netAmount) ?>
            </p>
            <p class="mt-1.5 text-xs text-slate-500">
                <span class="money"><?= e($countLabel) ?></span> &middot; <?= e($rangeLabel) ?>
            </p>
        </div>

        <div class="w-full md:max-w-sm">
            <dl class="grid grid-cols-2 gap-4">
                <div>
                    <dt class="kpi-label">Money in</dt>
                    <dd class="money mt-1 text-base font-semibold text-ok-text sm:text-lg"><?= e($money($inbound)) ?></dd>
                </div>
                <div class="text-right">
                    <dt class="kpi-label">Money out</dt>
                    <dd class="money mt-1 text-base font-semibold text-bad-text sm:text-lg"><?= e($money($outbound)) ?></dd>
                </div>
            </dl>
            <div class="mt-3 flex h-2 overflow-hidden rounded-full bg-slate-100" role="img"
                 aria-label="<?= e($shareSummary) ?>">
                <?php if ($inShare !== null) : ?>
                    <?php /* Inline width, not a class: Tailwind cannot generate a per-request percentage. */ ?>
                    <div class="h-full bg-ok" style="width: <?= e((string) $inShare) ?>%"></div>
                    <div class="h-full flex-1 bg-bad"></div>
                <?php endif; ?>
            </div>
            <p class="money mt-1.5 flex justify-between text-[11px] text-slate-500" aria-hidden="true">
                <?php if ($inShare !== null) : ?>
                    <span><?= e((string) $inShare) ?>% in</span>
                    <span><?= e((string) $outShare) ?>% out</span>
                <?php else : ?>
                    <span>No money moved</span>
                <?php endif; ?>
            </p>
        </div>
    </div>
</section>

<!--
    Compact duplicate of the hero, shown only once the hero has scrolled out
    of view (app.js: ledgerStickyTotals). Zero-height sticky wrapper so it
    never shifts the layout; aria-hidden because the hero already says all of
    this. Without JS it never appears. The negative top cancels <main>'s
    py-5 / sm:py-6, which Chromium subtracts from the sticky area, so the bar
    sits flush under the page header instead of 20px below it.
-->
<div class="sticky -top-5 z-20 h-0 sm:-top-6" aria-hidden="true">
    <div x-show="heroGone" x-cloak
         x-transition:enter="transition-opacity duration-150 motion-reduce:transition-none"
         x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition-opacity duration-100 motion-reduce:transition-none"
         x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="-mx-4 border-b border-slate-200 bg-white/95 px-4 py-2 shadow-sm backdrop-blur sm:-mx-7 sm:px-7">
        <p class="money flex flex-wrap items-baseline gap-x-3 gap-y-0.5 text-xs text-slate-500">
            <span class="text-sm font-semibold <?= $netTone ?>">Net <?= e($netAmount) ?></span>
            <span class="text-ok-text">In <?= e($money($inbound)) ?></span>
            <span class="text-bad-text">Out <?= e($money($outbound)) ?></span>
            <span><?= e($countLabel) ?></span>
        </p>
    </div>
</div>

<!-- ============ filters ============ -->
<form method="get" action="<?= e(url('/transactions')) ?>" x-data="ledgerFilterForm"
      class="relative mt-4 flex flex-wrap items-center gap-2" role="search" aria-label="Filter transactions">
    <div class="relative min-w-0 basis-full sm:flex-1 sm:basis-auto">
        <label for="f-q" class="sr-only">Search description or reference</label>
        <svg class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24"
             fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <circle cx="11" cy="11" r="7"></circle><path d="M20 20l-3.5-3.5"></path>
        </svg>
        <input id="f-q" name="q" type="search" placeholder="Search description or reference"
               value="<?= e((string) ($filters['q'] ?? '')) ?>" class="input pl-9">
    </div>

    <div class="min-w-0 flex-1 sm:w-44 sm:flex-none">
        <label for="f-range" class="sr-only">Date range</label>
        <select id="f-range" name="range" class="input" x-model="range" x-on:change="rangeChanged()">
            <?php foreach ($rangeOptions as $value => $label) : ?>
                <option value="<?= e($value) ?>" <?= $filters['range'] === $value ? 'selected' : '' ?>>
                    <?= e($label) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- A native disclosure, so the panel opens and closes with JS off; Alpine adds outside-click, Esc and focus. -->
    <details class="md:relative" x-ref="panel"
             x-on:toggle="panelToggled()" x-on:click.outside="closePanel(false)" x-on:keydown.escape="closePanel(true)">
        <summary x-ref="summary"
                 class="btn-secondary cursor-pointer list-none [&::-webkit-details-marker]:hidden">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                <path d="M4 6h16"></path><path d="M7 12h10"></path><path d="M10 18h4"></path>
            </svg>
            Filters
            <?php if ($panelCount > 0) : ?>
                <span class="badge-warn money"><?= e((string) $panelCount) ?><span class="sr-only"> active</span></span>
            <?php endif; ?>
        </summary>

        <div class="card absolute inset-x-0 top-full z-30 mt-2 p-4 shadow-lg md:left-auto md:right-0 md:w-[36rem]">
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label for="f-type" class="label">Type</label>
                    <select id="f-type" name="type" class="input" x-ref="firstField">
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
                    <select id="f-category" name="category_id" class="input" aria-describedby="f-category-help">
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
                    <p id="f-category-help" class="help">Includes subcategories</p>
                </div>
                <div>
                    <label for="f-status" class="label">Status</label>
                    <select id="f-status" name="status" class="input">
                        <option value="posted" <?= $filters['status'] === 'posted' ? 'selected' : '' ?>>Posted only</option>
                        <option value="all" <?= $filters['status'] === 'all' ? 'selected' : '' ?>>Include voided</option>
                        <option value="void" <?= $filters['status'] === 'void' ? 'selected' : '' ?>>Voided only</option>
                    </select>
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
                <div class="grid grid-cols-2 gap-3">
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
                </div>

                <!-- Custom dates: always shown without JS; with JS only while "Custom" is chosen. -->
                <fieldset class="grid grid-cols-2 gap-3 border-t border-slate-100 pt-3 sm:col-span-2"
                          x-show="range === 'custom'">
                    <legend class="sr-only">Custom date range</legend>
                    <div>
                        <label for="f-from" class="label">From</label>
                        <input id="f-from" name="from" type="date" x-ref="from" x-bind:disabled="range !== 'custom'"
                               value="<?= e($filters['range'] === 'custom' ? (string) ($filters['from'] ?? '') : '') ?>"
                               class="input" aria-describedby="f-dates-help">
                    </div>
                    <div>
                        <label for="f-to" class="label">To</label>
                        <input id="f-to" name="to" type="date" x-bind:disabled="range !== 'custom'"
                               value="<?= e($filters['range'] === 'custom' ? (string) ($filters['to'] ?? '') : '') ?>"
                               class="input" aria-describedby="f-dates-help">
                    </div>
                    <p id="f-dates-help" class="help col-span-2 mt-0">Used when the date range is set to Custom.</p>
                </fieldset>
            </div>

            <div class="mt-4 flex items-center justify-end gap-2 border-t border-slate-100 pt-3">
                <a href="<?= e(url('/transactions')) ?>" class="btn-secondary">Clear</a>
                <button type="submit" class="btn-dark">Apply</button>
            </div>
        </div>
    </details>

    <button type="submit" class="btn-dark" aria-label="Apply filters">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <circle cx="11" cy="11" r="7"></circle><path d="M20 20l-3.5-3.5"></path>
        </svg>
        <span class="hidden sm:inline">Apply</span>
    </button>
</form>

<?php if ($chips !== []) : ?>
    <div class="mt-3 flex flex-wrap items-center gap-2">
        <ul class="flex flex-wrap items-center gap-2" aria-label="Active filters">
            <?php foreach ($chips as $chip) : ?>
                <li>
                    <a href="<?= e($chip['href']) ?>" aria-label="Remove filter: <?= e($chip['label']) ?>"
                       class="inline-flex min-h-8 max-w-64 items-center gap-1.5 rounded-full border border-slate-300 bg-white py-1 pr-2 pl-3 text-xs font-medium text-slate-700 hover:border-slate-400 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-500">
                        <span class="truncate"><?= e($chip['label']) ?></span>
                        <svg class="h-3.5 w-3.5 shrink-0 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
                            <path d="M6 6l12 12"></path><path d="M18 6L6 18"></path>
                        </svg>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <a href="<?= e(url('/transactions')) ?>"
           class="rounded px-1 text-xs font-medium text-brand-700 underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700">
            Clear all
        </a>
    </div>
<?php endif; ?>

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
            <tbody x-data="{ openId: null, attachId: null }">
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
                            <?php foreach ($attachments[$rowId] ?? [] as $file) : ?>
                                <p class="mt-0.5 text-[11px]">
                                    <a href="<?= e(url('/documents/' . (int) $file['id'])) ?>" target="_blank"
                                       rel="noopener" class="text-brand-700 hover:underline">
                                        Attachment: <?= e((string) $file['original_filename']) ?>
                                    </a>
                                </p>
                            <?php endforeach; ?>
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
                            <td class="td text-right whitespace-nowrap">
                                <button type="button"
                                        x-on:click="attachId = attachId === <?= $rowId ?> ? null : <?= $rowId ?>"
                                        class="rounded-md px-2 py-1 text-[11.5px] font-medium text-slate-600 hover:bg-slate-100">
                                    Attach
                                </button>
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

                    <?php if ($canWrite) : ?>
                        <tr x-show="attachId === <?= $rowId ?>" x-cloak>
                            <td class="border-b border-slate-100 bg-slate-50 px-3.5 py-4" colspan="8">
                                <form method="post" action="<?= e(url('/transactions/' . $rowId . '/documents')) ?>"
                                      enctype="multipart/form-data" class="flex flex-wrap items-end gap-3">
                                    <?= csrf_field() ?>
                                    <div class="min-w-64 flex-1">
                                        <label for="document-<?= $rowId ?>" class="label">Attach a receipt or proof</label>
                                        <input id="document-<?= $rowId ?>" name="document" type="file"
                                               required accept="image/jpeg,image/png,image/webp,application/pdf"
                                               class="input file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-slate-700">
                                    </div>
                                    <button type="submit" class="btn-primary">Upload</button>
                                    <button type="button" x-on:click="attachId = null" class="btn-secondary">Cancel</button>
                                    <p class="help w-full">JPG, PNG, WebP or PDF.</p>
                                </form>
                            </td>
                        </tr>
                    <?php endif; ?>

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
                                    </div>
                                    <button type="submit" class="btn-primary">Void transaction</button>
                                    <button type="button" x-on:click="openId = null" class="btn-secondary">Cancel</button>
                                    <p class="help w-full">
                                        The row is kept with this reason. Nothing is deleted.
                                        <?php if (!empty($row['transfer_group'])) : ?>
                                            <strong class="font-medium text-bad-text">Both legs of the transfer will be voided.</strong>
                                        <?php endif; ?>
                                    </p>
                                </form>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total > 0) : ?>
        <div class="flex flex-col gap-3 border-t border-slate-200 bg-slate-50 px-4 py-3 sm:flex-row sm:items-center">
            <p class="flex-1 text-[11.5px] text-slate-500">
                Showing <span class="money"><?= e((string) (($page - 1) * $perPage + 1)) ?></span>&ndash;<span
                    class="money"><?= e((string) min($page * $perPage, $total)) ?></span>
                of <span class="money"><?= e(number_format($total)) ?></span>.
                Fetched a page at a time, not the whole table.
            </p>

            <div class="flex flex-wrap items-center gap-3">
                <?php if ($page > 1) : ?>
                    <a href="<?= e($ledgerFilters->href([], $page - 1)) ?>" class="btn-secondary">Previous</a>
                <?php else : ?>
                    <span class="btn-secondary pointer-events-none opacity-40">Previous</span>
                <?php endif; ?>

                <span class="money text-xs text-slate-600">
                    <?= e((string) $page) ?> of <?= e((string) $pages) ?>
                </span>

                <?php if ($page < $pages) : ?>
                    <a href="<?= e($ledgerFilters->href([], $page + 1)) ?>" class="btn-secondary">Next</a>
                <?php else : ?>
                    <span class="btn-secondary pointer-events-none opacity-40">Next</span>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

</div>
