<?php

/**
 * @var list<array<string,mixed>>  $rows
 * @var string                     $monthTotal
 * @var list<array<string,mixed>>  $byCategory
 * @var int                        $total
 * @var int                        $page
 * @var int                        $pages
 * @var int                        $perPage
 * @var array{from:string,to:string,value:string,label:string} $month
 * @var string|null                $search
 * @var array<string,mixed>|null   $authUser
 */

declare(strict_types=1);

use App\Services\Access;
use App\Services\Settings;

$canWrite = Access::canWriteTransactions((string) ($authUser['role'] ?? ''));
$symbol = Settings::string('currency_symbol', 'Rs');
$money = static fn (mixed $v): string => number_format((float) $v, 2);

$pageUrl = static fn (int $target): string => url('/expenses') . '?' . http_build_query(array_filter([
    'month' => $month['value'],
    'q' => $search,
    'page' => $target > 1 ? $target : null,
], static fn (mixed $v): bool => $v !== null && $v !== ''));
?>

<div class="flex flex-wrap items-end gap-3">
    <form method="get" action="<?= e(url('/expenses')) ?>" class="flex flex-wrap items-end gap-3">
        <div>
            <label for="month" class="label">Month</label>
            <input id="month" name="month" type="month" value="<?= e($month['value']) ?>" class="input w-44">
        </div>
        <div>
            <label for="q" class="label">Search</label>
            <input id="q" name="q" type="search" placeholder="Description or reference"
                   value="<?= e((string) ($search ?? '')) ?>" class="input w-56">
        </div>
        <button type="submit" class="btn-dark">Show</button>
    </form>

    <div class="flex-1"></div>

    <?php if ($canWrite) : ?>
        <a href="<?= e(url('/expenses/new')) ?>" class="btn-primary">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true">
                <path d="M12 5v14"></path><path d="M5 12h14"></path>
            </svg>
            Add Expense
        </a>
    <?php endif; ?>
</div>

<div class="mt-4 grid gap-4 xl:grid-cols-[1fr_320px]">

    <div class="card overflow-hidden">
        <div class="flex items-baseline gap-3 px-5 pt-4 pb-3">
            <h2 class="flex-1 font-display text-sm font-semibold text-ink"><?= e($month['label']) ?></h2>
            <span class="money text-lg font-semibold text-ink"><?= e($symbol) ?> <?= e($money($monthTotal)) ?></span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full border-collapse">
                <thead>
                    <tr>
                        <th scope="col" class="th">Date</th>
                        <th scope="col" class="th">Description</th>
                        <th scope="col" class="th">Category</th>
                        <th scope="col" class="th">Paid from</th>
                        <th scope="col" class="th text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows === []) : ?>
                        <tr>
                            <td class="td" colspan="5">
                                <div class="py-10 text-center">
                                    <p class="text-sm text-slate-600">
                                        <?= $search !== null
                                            ? 'Nothing matches that search this month.'
                                            : 'No expenses recorded for ' . e($month['label']) . '.' ?>
                                    </p>
                                    <?php if ($canWrite) : ?>
                                        <a href="<?= e(url('/expenses/new')) ?>" class="btn-secondary mt-4">Add the first one</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($rows as $row) : ?>
                        <?php
                        $category = $row['parent_category_name'] !== null
                            ? (string) $row['parent_category_name'] . ' · ' . (string) $row['category_name']
                            : (string) ($row['category_name'] ?? '');
                        ?>
                        <tr>
                            <td class="td money whitespace-nowrap text-slate-500">
                                <?= e(date('j M', (int) strtotime((string) $row['transaction_date']))) ?>
                            </td>
                            <td class="td">
                                <p class="font-medium text-ink"><?= e((string) $row['description']) ?></p>
                                <?php if (!empty($row['vendor'])) : ?>
                                    <p class="text-[11px] text-slate-400"><?= e((string) $row['vendor']) ?></p>
                                <?php endif; ?>
                                <?php if (!empty($row['reference_no'])) : ?>
                                    <p class="money text-[11px] text-slate-400"><?= e((string) $row['reference_no']) ?></p>
                                <?php endif; ?>
                            </td>
                            <td class="td text-slate-600"><?= e($category) ?></td>
                            <td class="td text-slate-600"><?= e((string) $row['account_name']) ?></td>
                            <td class="td money text-right font-medium text-ink"><?= e($money($row['amount'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total > $perPage) : ?>
            <div class="flex flex-col gap-3 border-t border-slate-200 bg-slate-50 px-4 py-3 sm:flex-row sm:items-center">
                <p class="flex-1 text-[11.5px] text-slate-500">
                    Page <span class="money"><?= e((string) $page) ?></span> of
                    <span class="money"><?= e((string) $pages) ?></span>
                </p>
                <div class="flex flex-wrap gap-3">
                    <?php if ($page > 1) : ?>
                        <a href="<?= e($pageUrl($page - 1)) ?>" class="btn-secondary">Previous</a>
                    <?php endif; ?>
                    <?php if ($page < $pages) : ?>
                        <a href="<?= e($pageUrl($page + 1)) ?>" class="btn-secondary">Next</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="card p-5">
        <h2 class="font-display text-sm font-semibold text-ink">By category</h2>
        <p class="mt-0.5 text-[11.5px] text-slate-500">Subcategories roll into their parent</p>

        <?php if ($byCategory === []) : ?>
            <p class="mt-4 text-xs text-slate-500">Nothing to break down yet.</p>
        <?php else : ?>
            <?php $largest = max(array_map(static fn (array $r): float => (float) $r['total'], $byCategory)); ?>
            <div class="mt-4 flex flex-col gap-3">
                <?php foreach ($byCategory as $bucket) : ?>
                    <?php $share = $largest > 0 ? ((float) $bucket['total'] / $largest) * 100 : 0; ?>
                    <div>
                        <div class="flex items-baseline gap-2">
                            <span class="flex-1 truncate text-xs text-slate-700">
                                <?= e((string) ($bucket['category_name'] ?? 'Uncategorised')) ?>
                            </span>
                            <span class="money text-[11.5px] font-medium text-ink"><?= e($money($bucket['total'])) ?></span>
                        </div>
                        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100">
                            <div class="h-1.5 rounded-full bg-brand-500" style="width: <?= e((string) round($share, 1)) ?>%"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
