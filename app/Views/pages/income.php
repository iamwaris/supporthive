<?php

/**
 * @var list<array<string,mixed>> $rows
 * @var list<array<string,mixed>> $pendingRows
 * @var string                    $monthTotal
 * @var string                    $pendingTotal
 * @var int                       $pendingCount
 * @var int                       $total
 * @var int                       $page
 * @var int                       $pages
 * @var int                       $perPage
 * @var array{from:string,to:string,value:string,label:string} $month
 * @var array<string,mixed>|null  $authUser
 */

declare(strict_types=1);

use App\Services\Access;
use App\Services\Settings;

$canWrite = Access::canWriteTransactions((string) ($authUser['role'] ?? ''));
$symbol = Settings::string('currency_symbol', 'Rs');
$money = static fn (mixed $v): string => number_format((float) $v, 2);
?>

<div class="flex flex-wrap items-end gap-3">
    <form method="get" action="<?= e(url('/income')) ?>" class="flex items-end gap-3">
        <div>
            <label for="month" class="label">Month</label>
            <input id="month" name="month" type="month" value="<?= e($month['value']) ?>" class="input w-44">
        </div>
        <button type="submit" class="btn-dark">Show</button>
    </form>

    <div class="flex-1"></div>

    <?php if ($canWrite) : ?>
        <a href="<?= e(url('/income/new')) ?>" class="btn-primary">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true">
                <path d="M12 5v14"></path><path d="M5 12h14"></path>
            </svg>
            Record Income
        </a>
    <?php endif; ?>
</div>

<!-- ============ received (revenue) ============ -->
<div class="mt-4 card overflow-hidden">
    <div class="flex items-baseline gap-3 px-5 pt-4 pb-3">
        <div class="flex-1">
            <h2 class="font-display text-sm font-semibold text-ink"><?= e($month['label']) ?> &mdash; received</h2>
            <p class="mt-0.5 text-[11.5px] text-slate-500">Counted as revenue</p>
        </div>
        <span class="money text-lg font-semibold text-ok-text"><?= e($symbol) ?> <?= e($money($monthTotal)) ?></span>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full border-collapse">
            <thead>
                <tr>
                    <th scope="col" class="th">Date</th>
                    <th scope="col" class="th">Description</th>
                    <th scope="col" class="th">Customer</th>
                    <th scope="col" class="th">Account</th>
                    <th scope="col" class="th text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []) : ?>
                    <tr>
                        <td class="td" colspan="5">
                            <div class="py-10 text-center">
                                <p class="text-sm text-slate-600">No income received for <?= e($month['label']) ?>.</p>
                                <?php if ($canWrite) : ?>
                                    <a href="<?= e(url('/income/new')) ?>" class="btn-secondary mt-4">Record income</a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($rows as $row) : ?>
                    <tr>
                        <td class="td money whitespace-nowrap text-slate-500">
                            <?= e(date('j M', (int) strtotime((string) $row['transaction_date']))) ?>
                        </td>
                        <td class="td">
                            <p class="font-medium text-ink"><?= e((string) $row['description']) ?></p>
                            <?php if (!empty($row['invoice_no'])) : ?>
                                <p class="money text-[11px] text-slate-400"><?= e((string) $row['invoice_no']) ?></p>
                            <?php endif; ?>
                        </td>
                        <td class="td text-slate-600"><?= e((string) ($row['customer_name'] ?? '—')) ?></td>
                        <td class="td text-slate-600"><?= e((string) $row['account_name']) ?></td>
                        <td class="td money text-right font-medium text-ok-text">+<?= e($money($row['amount'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total > $perPage) : ?>
        <div class="flex items-center gap-3 border-t border-slate-200 bg-slate-50 px-4 py-3">
            <p class="flex-1 text-[11.5px] text-slate-500">Page <?= e((string) $page) ?> of <?= e((string) $pages) ?></p>
            <?php if ($page > 1) : ?>
                <a href="<?= e(url('/income')) ?>?month=<?= e($month['value']) ?>&page=<?= $page - 1 ?>" class="btn-secondary">Previous</a>
            <?php endif; ?>
            <?php if ($page < $pages) : ?>
                <a href="<?= e(url('/income')) ?>?month=<?= e($month['value']) ?>&page=<?= $page + 1 ?>" class="btn-secondary">Next</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<!-- ============ pending (not revenue) ============ -->
<div class="mt-4 card overflow-hidden border-dashed">
    <div class="flex items-baseline gap-3 px-5 pt-4 pb-3">
        <div class="flex-1">
            <div class="flex items-center gap-2">
                <h2 class="font-display text-sm font-semibold text-ink">Pending</h2>
                <svg class="h-3.5 w-3.5 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="9"></circle><path d="M12 11v5"></path><path d="M12 8h.01"></path>
                </svg>
            </div>
            <p class="mt-0.5 text-[11.5px] text-slate-500">
                Invoiced but not received &mdash; <strong class="font-medium">not counted as revenue</strong>
            </p>
        </div>
        <span class="money text-lg font-semibold text-slate-500"><?= e($symbol) ?> <?= e($money($pendingTotal)) ?></span>
    </div>

    <?php if ($pendingRows === []) : ?>
        <p class="px-5 pb-5 text-sm text-slate-500">Nothing pending.</p>
    <?php else : ?>
        <div class="overflow-x-auto">
            <table class="w-full border-collapse">
                <thead>
                    <tr>
                        <th scope="col" class="th">Invoice date</th>
                        <th scope="col" class="th">Description</th>
                        <th scope="col" class="th">Customer</th>
                        <th scope="col" class="th text-right">Amount</th>
                        <?php if ($canWrite) : ?>
                            <th scope="col" class="th"><span class="sr-only">Actions</span></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingRows as $row) : ?>
                        <tr x-data="{ receiving: false }">
                            <td class="td money whitespace-nowrap text-slate-500">
                                <?= e(date('j M Y', (int) strtotime((string) $row['transaction_date']))) ?>
                            </td>
                            <td class="td">
                                <p class="font-medium text-ink"><?= e((string) $row['description']) ?></p>
                                <?php if (!empty($row['invoice_no'])) : ?>
                                    <p class="money text-[11px] text-slate-400"><?= e((string) $row['invoice_no']) ?></p>
                                <?php endif; ?>
                            </td>
                            <td class="td text-slate-600"><?= e((string) ($row['customer_name'] ?? '—')) ?></td>
                            <td class="td money text-right font-medium text-slate-600"><?= e($money($row['amount'])) ?></td>
                            <?php if ($canWrite) : ?>
                                <td class="td text-right">
                                    <button type="button" x-on:click="receiving = !receiving" class="btn-secondary">
                                        Mark received
                                    </button>
                                </td>
                            <?php endif; ?>
                        </tr>
                        <?php if ($canWrite) : ?>
                            <tr x-show="receiving" x-cloak>
                                <td class="border-b border-slate-100 bg-ok-bg px-3.5 py-4" colspan="5">
                                    <form method="post" action="<?= e(url('/income/' . (int) $row['id'] . '/received')) ?>"
                                          class="flex flex-wrap items-end gap-3">
                                        <?= csrf_field() ?>
                                        <div>
                                            <label for="rcv-<?= (int) $row['id'] ?>" class="label">Received on</label>
                                            <input id="rcv-<?= (int) $row['id'] ?>" name="received_at" type="date"
                                                   value="<?= e(date('Y-m-d')) ?>" class="input">
                                        </div>
                                        <button type="submit" class="btn-primary">Confirm received</button>
                                        <button type="button" x-on:click="receiving = false" class="btn-secondary">Cancel</button>
                                        <p class="w-full text-[11px] text-slate-600">
                                            This starts counting towards revenue and the account balance from today.
                                        </p>
                                    </form>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
