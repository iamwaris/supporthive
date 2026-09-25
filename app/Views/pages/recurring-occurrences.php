<?php

/**
 * The recurring-transactions approval queue: drafts
 * RecurringRuleService::generateForBranch() produced, waiting for a human to
 * approve (optionally editing amount/date/description first — a utility
 * bill varies month to month) or reject (never touches the ledger) before
 * they reach `transactions`.
 *
 * @var list<array<string,mixed>>  $occurrences
 * @var array<string,mixed>|null   $authUser
 */

declare(strict_types=1);

use App\Services\Access;
use App\Services\Settings;

$canWrite = Access::canWriteTransactions((string) ($authUser['role'] ?? ''));
$symbol = Settings::string('currency_symbol', 'Rs');

$typeBadge = ['income' => 'badge-ok', 'expense' => 'badge-bad'];
?>

<div class="flex flex-col gap-5" x-data="{ openId: null }">

    <?php if ($occurrences === []) : ?>
        <div class="card p-8 text-center text-sm text-slate-500">
            Nothing pending review.
        </div>
    <?php else : ?>
        <?php if ($canWrite) : ?>
            <!-- Bulk-approve form. It wraps nothing visible of its own — the
                 row checkboxes and the "Approve selected" button below are
                 associated to it via the HTML `form` attribute rather than
                 DOM nesting, because each row's own approve/reject <form>
                 sits inside the same table and <form> elements cannot
                 legally nest. -->
            <form id="bulk-approve-form" method="post"
                  action="<?= e(url('/recurring-occurrences/approve-bulk')) ?>">
                <?= csrf_field() ?>
            </form>
        <?php endif; ?>

        <div class="min-w-0 card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 px-5 pt-4 pb-3">
                <h2 class="font-display text-sm font-semibold text-ink">
                    Pending review
                    <span class="font-normal text-slate-400">(<?= count($occurrences) ?>)</span>
                </h2>
                <?php if ($canWrite) : ?>
                    <button type="submit" form="bulk-approve-form" class="btn-primary">Approve selected</button>
                <?php endif; ?>
            </div>

            <div class="overflow-x-auto">
            <table class="w-full border-collapse">
                <thead>
                    <tr>
                        <?php if ($canWrite) : ?>
                            <th scope="col" class="th"><span class="sr-only">Select</span></th>
                        <?php endif; ?>
                        <th scope="col" class="th">Description</th>
                        <th scope="col" class="th">Date</th>
                        <th scope="col" class="th">Type</th>
                        <th scope="col" class="th text-right">Amount</th>
                        <th scope="col" class="th">Account</th>
                        <th scope="col" class="th">Category</th>
                        <?php if ($canWrite) : ?>
                            <th scope="col" class="th"><span class="sr-only">Actions</span></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($occurrences as $occurrence) : ?>
                        <?php
                        $id = (int) $occurrence['id'];
                        $type = (string) $occurrence['type'];
                        ?>
                        <tr>
                            <?php if ($canWrite) : ?>
                                <td class="td">
                                    <label class="sr-only" for="select-<?= $id ?>">
                                        Select the <?= e((string) $occurrence['description']) ?> draft
                                    </label>
                                    <input id="select-<?= $id ?>" type="checkbox" name="occurrence_ids[]"
                                           value="<?= $id ?>" form="bulk-approve-form"
                                           class="h-4 w-4 rounded border-slate-300 text-brand-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-400">
                                </td>
                            <?php endif; ?>
                            <td class="td font-medium text-ink"><?= e((string) $occurrence['description']) ?></td>
                            <td class="td text-slate-600"><?= e((string) $occurrence['occurrence_date']) ?></td>
                            <td class="td"><span class="<?= $typeBadge[$type] ?? 'badge-mute' ?>"><?= e(ucfirst($type)) ?></span></td>
                            <td class="td money text-right text-slate-600">
                                <?= e($symbol) ?> <?= e(number_format((float) $occurrence['amount'], 2)) ?>
                            </td>
                            <td class="td text-slate-600"><?= e((string) $occurrence['account_name']) ?></td>
                            <td class="td text-slate-600"><?= e((string) $occurrence['category_name']) ?></td>
                            <?php if ($canWrite) : ?>
                                <td class="td text-right whitespace-nowrap">
                                    <button type="button" x-on:click="openId = openId === <?= $id ?> ? null : <?= $id ?>"
                                            class="rounded-md px-2 py-1 text-[11.5px] font-medium text-brand-700 hover:bg-brand-50">
                                        Review
                                    </button>
                                </td>
                            <?php endif; ?>
                        </tr>

                        <?php if ($canWrite) : ?>
                            <tr x-show="openId === <?= $id ?>" x-cloak>
                                <td class="border-b border-slate-100 bg-slate-50 px-3.5 py-4" colspan="8">
                                    <div class="grid gap-5 lg:grid-cols-2">

                                        <form method="post"
                                              action="<?= e(url('/recurring-occurrences/' . $id . '/approve')) ?>"
                                              class="flex min-w-0 flex-col gap-3">
                                            <?= csrf_field() ?>
                                            <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                                                Approve
                                            </p>
                                            <p class="text-[11.5px] text-slate-500">
                                                Edit the amount, date or description before posting — the rest of
                                                this draft (account, category<?= $type === 'expense' ? ', vendor' : ', customer' ?>)
                                                posts exactly as scheduled.
                                            </p>

                                            <div>
                                                <label for="amount-<?= $id ?>" class="label">Amount (<?= e($symbol) ?>)</label>
                                                <input id="amount-<?= $id ?>" name="amount" type="text" inputmode="decimal"
                                                       required value="<?= e((string) $occurrence['amount']) ?>"
                                                       class="input money">
                                            </div>
                                            <div>
                                                <label for="date-<?= $id ?>" class="label">Transaction date</label>
                                                <input id="date-<?= $id ?>" name="transaction_date" type="date" required
                                                       value="<?= e((string) $occurrence['occurrence_date']) ?>" class="input">
                                            </div>
                                            <div>
                                                <label for="desc-<?= $id ?>" class="label">Description</label>
                                                <input id="desc-<?= $id ?>" name="description" type="text" required
                                                       maxlength="255" value="<?= e((string) $occurrence['description']) ?>"
                                                       class="input">
                                            </div>

                                            <div class="mt-1 flex flex-wrap gap-3">
                                                <button type="submit" class="btn-primary">Approve &amp; post</button>
                                                <button type="button" x-on:click="openId = null" class="btn-secondary">
                                                    Cancel
                                                </button>
                                            </div>
                                        </form>

                                        <form method="post"
                                              action="<?= e(url('/recurring-occurrences/' . $id . '/reject')) ?>"
                                              class="flex min-w-0 flex-col gap-3 rounded-lg bg-bad-bg p-4">
                                            <?= csrf_field() ?>
                                            <p class="text-[11px] font-semibold uppercase tracking-wide text-bad-text">
                                                Reject
                                            </p>
                                            <p class="text-[11.5px] text-bad-text">
                                                Nothing is posted to the ledger. This draft is kept, marked rejected.
                                            </p>

                                            <div>
                                                <label for="reason-<?= $id ?>" class="label">
                                                    Reason <span class="font-normal text-slate-400">optional</span>
                                                </label>
                                                <textarea id="reason-<?= $id ?>" name="reject_reason" rows="3"
                                                          maxlength="255" class="input h-auto py-2.5"></textarea>
                                            </div>

                                            <div class="mt-1 flex flex-wrap gap-3">
                                                <button type="submit"
                                                        class="btn-primary bg-bad-text border-bad-text hover:bg-bad-text/90">
                                                    Reject draft
                                                </button>
                                                <button type="button" x-on:click="openId = null" class="btn-secondary">
                                                    Cancel
                                                </button>
                                            </div>
                                        </form>

                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    <?php endif; ?>
</div>
