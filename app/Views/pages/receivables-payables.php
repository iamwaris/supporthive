<?php

/**
 * Receivables / payables register — one view for both sections.
 *
 * Settle and cancel are native <details> disclosures holding real POST forms,
 * so both work without JavaScript; Alpine only disables a submit button while
 * its request is in flight. Opening the cancel panel and giving a reason is
 * the confirmation step before anything changes.
 *
 * Recording, settling and cancelling post to (or void in) the ledger; each
 * row names the account its money moved through.
 *
 * @var \App\Domain\ReceivablePayableKind $kind
 * @var list<array<string,mixed>>         $rows
 * @var array{open_total:string,open_count:int,overdue_total:string,overdue_count:int}|null $summary
 * @var bool                              $loadFailed
 * @var string                            $status
 * @var string                            $search
 * @var int                               $total
 * @var int                               $page
 * @var int                               $pages
 * @var int                               $perPage
 * @var string                            $today
 * @var array{id:int,action:string,errors:array<string,list<string>>,settled_on:string,
 *            settlement_note:string,cancel_reason:string,settlement_account_id:string}|null $rowFailure
 * @var list<array<string,mixed>>         $accounts         active accounts in this branch
 * @var int|null                          $defaultAccountId the branch's Cash account, preselected
 * @var array<string,mixed>|null          $authUser
 */

declare(strict_types=1);

use App\Domain\ReceivablePayableRules;
use App\Services\Access;
use App\Services\Settings;

$canWrite = Access::canWriteTransactions((string) ($authUser['role'] ?? ''));
$symbol = Settings::string('currency_symbol', 'Rs');
$money = static fn (mixed $v): string => number_format((float) $v, 2);
$day = static fn (mixed $v): string => date('j M Y', (int) strtotime((string) $v));
$base = $kind->path();
$singular = $kind->singular();

$statusOptions = [
    'open' => 'Open',
    'settled' => $kind->settledLabel(),
    'cancelled' => 'Cancelled',
    'all' => 'All',
];

$pageUrl = static function (int $target) use ($base, $status, $search): string {
    $query = array_filter(
        ['status' => $status, 'q' => $search, 'page' => $target > 1 ? $target : null],
        static fn (mixed $v): bool => $v !== null && $v !== ''
    );

    return url($base) . ($query === [] ? '' : '?' . http_build_query($query));
};

$isFiltered = $search !== '' || $status !== 'open';
?>

<!-- ============ filters + primary action ============ -->
<div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
    <form method="get" action="<?= e(url($base)) ?>" role="search"
          class="flex flex-col gap-3 sm:flex-row sm:items-end"
          x-data="{ busy: false }" x-on:submit="busy = true">
        <div>
            <label for="rp-status" class="label">Status</label>
            <select id="rp-status" name="status" class="input sm:w-40">
                <?php foreach ($statusOptions as $value => $label) : ?>
                    <option value="<?= e($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="rp-search" class="label"><?= e($kind->partyLabel()) ?> name</label>
            <input id="rp-search" name="q" type="search" maxlength="160"
                   value="<?= e($search) ?>" placeholder="Search by name" class="input sm:w-56">
        </div>
        <div class="flex gap-2">
            <button type="submit" class="btn-dark" x-bind:disabled="busy">
                <span x-show="!busy">Apply</span>
                <span x-show="busy" x-cloak>Loading&hellip;</span>
            </button>
            <?php if ($isFiltered) : ?>
                <a href="<?= e(url($base)) ?>" class="btn-secondary">Clear</a>
            <?php endif; ?>
        </div>
        <p class="sr-only" aria-live="polite" x-text="busy ? 'Loading results.' : ''"></p>
    </form>

    <div class="hidden flex-1 sm:block"></div>

    <?php if ($canWrite) : ?>
        <a href="<?= e(url($base . '/new')) ?>" class="btn-primary">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true">
                <path d="M12 5v14"></path><path d="M5 12h14"></path>
            </svg>
            New <?= e($singular) ?>
        </a>
    <?php endif; ?>
</div>

<?php if ($loadFailed || $summary === null) : ?>
    <!-- ============ error state ============ -->
    <div class="card mt-4 p-6" role="alert">
        <h2 class="font-display text-sm font-semibold text-ink"><?= e($kind->title()) ?> could not be loaded</h2>
        <p class="mt-1 text-sm text-slate-600">Something went wrong reading the register. Nothing was changed.</p>
        <a href="<?= e($pageUrl($page)) ?>" class="btn-secondary mt-4">Try again</a>
    </div>
<?php else : ?>
    <!-- ============ summary ============ -->
    <div class="mt-4 grid gap-3 sm:grid-cols-3">
        <div class="card px-5 py-4">
            <p class="kpi-label">Total open</p>
            <p class="money mt-1.5 font-display text-xl font-semibold text-ink">
                <?= e($symbol) ?> <?= e($money($summary['open_total'])) ?>
            </p>
        </div>
        <div class="card px-5 py-4">
            <p class="kpi-label">Total overdue</p>
            <p class="money mt-1.5 font-display text-xl font-semibold <?= $summary['overdue_count'] > 0 ? 'text-bad-text' : 'text-ink' ?>">
                <?= e($symbol) ?> <?= e($money($summary['overdue_total'])) ?>
            </p>
            <p class="mt-1 text-[11.5px] text-slate-500">
                <?= e(number_format($summary['overdue_count'])) ?> overdue <?= $summary['overdue_count'] === 1 ? 'entry' : 'entries' ?>
            </p>
        </div>
        <div class="card px-5 py-4">
            <p class="kpi-label">Open entries</p>
            <p class="money mt-1.5 font-display text-xl font-semibold text-ink"><?= e(number_format($summary['open_count'])) ?></p>
            <p class="mt-1 text-[11.5px] text-slate-500">Past due date counts as overdue</p>
        </div>
    </div>

    <!-- ============ entries ============ -->
    <section class="card mt-4 overflow-hidden" aria-labelledby="rp-list-heading">
        <div class="px-5 pt-4 pb-3">
            <h2 id="rp-list-heading" class="font-display text-sm font-semibold text-ink">
                <?= e($statusOptions[$status] ?? 'All') ?> <?= e(strtolower($kind->title())) ?>
            </h2>
            <p class="mt-0.5 text-[11.5px] text-slate-500">
                <?= e(number_format($total)) ?> <?= $total === 1 ? 'entry' : 'entries' ?><?= e($search !== '' ? ' matching “' . $search . '”' : '') ?>
            </p>
        </div>

        <?php if ($rows === []) : ?>
            <div class="border-t border-slate-100 px-5 py-10 text-center">
                <p class="text-sm text-slate-600">
                    <?php if ($isFiltered) : ?>
                        No <?= e(strtolower($kind->title())) ?> match these filters.
                    <?php else : ?>
                        Nothing open. Every <?= e($singular) ?> is settled or there are none yet.
                    <?php endif; ?>
                </p>
                <?php if ($canWrite && !$isFiltered) : ?>
                    <a href="<?= e(url($base . '/new')) ?>" class="btn-secondary mt-4">Record a <?= e($singular) ?></a>
                <?php elseif ($isFiltered) : ?>
                    <a href="<?= e(url($base)) ?>" class="btn-secondary mt-4">Clear filters</a>
                <?php endif; ?>
            </div>
        <?php else : ?>
            <ul role="list" class="border-t border-slate-100">
                <?php foreach ($rows as $row) : ?>
                    <?php
                    $rowId = (int) $row['id'];
                    $rowStatus = (string) $row['status'];
                    $dueDate = $row['due_date'] === null ? null : (string) $row['due_date'];
                    $isOpen = ReceivablePayableRules::isChangeable($rowStatus);
                    $isOverdue = ReceivablePayableRules::isOverdue($rowStatus, $dueDate, $today);
                    $failure = $rowFailure !== null && $rowFailure['id'] === $rowId ? $rowFailure : null;
                    $failedErrors = $failure['errors'] ?? [];
                    $settleOpen = $failure !== null && $failure['action'] === 'settle';
                    $cancelOpen = $failure !== null && $failure['action'] === 'cancel';
                    $settleAccount = $settleOpen && $failure['settlement_account_id'] !== ''
                        ? $failure['settlement_account_id']
                        : (string) ($defaultAccountId ?? '');
                    ?>
                    <li class="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 last:border-b-0">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0">
                                <p class="font-medium break-words text-ink"><?= e((string) $row['party_name']) ?></p>
                                <?php if (!empty($row['reference_no']) || !empty($row['description'])) : ?>
                                    <p class="mt-0.5 text-[11.5px] break-words text-slate-500">
                                        <?php if (!empty($row['reference_no'])) : ?>
                                            <span class="money"><?= e((string) $row['reference_no']) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($row['reference_no']) && !empty($row['description'])) : ?>
                                            &middot;
                                        <?php endif; ?>
                                        <?= e((string) ($row['description'] ?? '')) ?>
                                    </p>
                                <?php endif; ?>
                                <p class="mt-0.5 text-[11.5px] text-slate-500">
                                    Issued <?= e($day($row['issue_date'])) ?>
                                    &middot;
                                    <?= e($dueDate === null ? 'No due date' : 'Due ' . $day($dueDate)) ?>
                                </p>
                                <?php if ($row['account_name'] !== null) : ?>
                                    <p class="mt-0.5 text-[11.5px] text-slate-500">
                                        <?= e($kind->issueAccountLabel()) ?> <?= e((string) $row['account_name']) ?>
                                        <?php if ($rowStatus === ReceivablePayableRules::STATUS_CANCELLED) : ?>
                                            &middot; posting voided
                                        <?php endif; ?>
                                    </p>
                                <?php elseif ($isOpen) : ?>
                                    <p class="mt-0.5 text-[11.5px] text-brand-900">
                                        Not posted to the ledger yet &mdash; waiting for the one-time backfill.
                                    </p>
                                <?php endif; ?>
                                <?php if ($rowStatus === ReceivablePayableRules::STATUS_SETTLED) : ?>
                                    <p class="mt-0.5 text-[11.5px] text-ok-text">
                                        <?= e($kind->settledLabel()) ?> on <?= e($day($row['settled_on'])) ?>
                                        <?php if ($row['settlement_account_name'] !== null) : ?>
                                            &middot; <?= e(strtolower($kind->settlementAccountLabel())) ?>
                                            <?= e((string) $row['settlement_account_name']) ?>
                                        <?php endif; ?>
                                        by <?= e((string) ($row['settled_by_name'] ?? 'unknown')) ?>
                                        <?php if (!empty($row['settlement_note'])) : ?>
                                            &mdash; &ldquo;<?= e((string) $row['settlement_note']) ?>&rdquo;
                                        <?php endif; ?>
                                    </p>
                                <?php elseif ($rowStatus === ReceivablePayableRules::STATUS_CANCELLED) : ?>
                                    <p class="mt-0.5 text-[11.5px] text-bad-text">
                                        Cancelled by <?= e((string) ($row['cancelled_by_name'] ?? 'unknown')) ?>
                                        &mdash; &ldquo;<?= e((string) $row['cancel_reason']) ?>&rdquo;
                                    </p>
                                <?php endif; ?>
                            </div>

                            <div class="flex shrink-0 flex-wrap items-center gap-2 sm:flex-col sm:items-end">
                                <p class="money text-base font-semibold <?= $isOpen ? 'text-ink' : 'text-slate-400' ?>">
                                    <?= e($symbol) ?> <?= e($money($row['amount'])) ?>
                                </p>
                                <div class="flex flex-wrap gap-1.5">
                                    <?php if ($isOverdue) : ?>
                                        <span class="badge-bad">Overdue</span>
                                    <?php endif; ?>
                                    <?php if ($isOpen) : ?>
                                        <span class="badge-warn">Open</span>
                                    <?php elseif ($rowStatus === ReceivablePayableRules::STATUS_SETTLED) : ?>
                                        <span class="badge-ok"><?= e($kind->settledLabel()) ?></span>
                                    <?php else : ?>
                                        <span class="badge-mute">Cancelled</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <?php if ($canWrite && $isOpen) : ?>
                            <div class="flex flex-wrap items-start gap-2">
                                <a href="<?= e(url($base . '/' . $rowId . '/edit')) ?>" class="btn-secondary">
                                    Edit<span class="sr-only"> <?= e((string) $row['party_name']) ?></span>
                                </a>

                                <details class="open:order-last open:basis-full" <?= $settleOpen ? 'open' : '' ?>>
                                    <summary class="btn-secondary cursor-pointer list-none [&::-webkit-details-marker]:hidden">
                                        <?= e($kind->settleLabel()) ?><span class="sr-only"> for <?= e((string) $row['party_name']) ?></span>
                                    </summary>
                                    <form method="post" action="<?= e(url($base . '/' . $rowId . '/settle')) ?>"
                                          class="mt-2 grid gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4 sm:grid-cols-2 sm:items-start lg:grid-cols-[11rem_13rem_1fr_auto]"
                                          x-data="{ sending: false }" x-on:submit="sending = true">
                                        <?= csrf_field() ?>
                                        <div>
                                            <label for="settled-on-<?= $rowId ?>" class="label"><?= e($kind->settledLabel()) ?> on</label>
                                            <input id="settled-on-<?= $rowId ?>" name="settled_on" type="date" required
                                                   min="<?= e((string) $row['issue_date']) ?>" max="<?= e($today) ?>"
                                                   value="<?= e($settleOpen && $failure['settled_on'] !== '' ? $failure['settled_on'] : $today) ?>"
                                                   class="<?= isset($failedErrors['settled_on']) ? 'input-error' : 'input' ?>"
                                                   <?= isset($failedErrors['settled_on']) ? 'aria-invalid="true" aria-describedby="settled-on-error-' . $rowId . '"' : '' ?>>
                                            <?php if (isset($failedErrors['settled_on'][0])) : ?>
                                                <p id="settled-on-error-<?= $rowId ?>" class="error"><?= e($failedErrors['settled_on'][0]) ?></p>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <label for="settle-account-<?= $rowId ?>" class="label"><?= e($kind->settlementAccountLabel()) ?></label>
                                            <select id="settle-account-<?= $rowId ?>" name="settlement_account_id" required
                                                    class="<?= isset($failedErrors['settlement_account_id']) ? 'input-error' : 'input' ?>"
                                                    <?= isset($failedErrors['settlement_account_id']) ? 'aria-invalid="true" aria-describedby="settle-account-error-' . $rowId . '"' : '' ?>>
                                                <?php foreach ($accounts as $account) : ?>
                                                    <option value="<?= e((string) $account['id']) ?>" <?= $settleAccount === (string) $account['id'] ? 'selected' : '' ?>>
                                                        <?= e((string) $account['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <?php if (isset($failedErrors['settlement_account_id'][0])) : ?>
                                                <p id="settle-account-error-<?= $rowId ?>" class="error"><?= e($failedErrors['settlement_account_id'][0]) ?></p>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <label for="settle-note-<?= $rowId ?>" class="label">
                                                Note <span class="font-normal text-slate-400">optional</span>
                                            </label>
                                            <input id="settle-note-<?= $rowId ?>" name="settlement_note" type="text" maxlength="255"
                                                   value="<?= e($settleOpen ? $failure['settlement_note'] : '') ?>"
                                                   placeholder="e.g. bank transfer ref"
                                                   class="<?= isset($failedErrors['settlement_note']) ? 'input-error' : 'input' ?>"
                                                   <?= isset($failedErrors['settlement_note']) ? 'aria-invalid="true" aria-describedby="settle-note-error-' . $rowId . '"' : '' ?>>
                                            <?php if (isset($failedErrors['settlement_note'][0])) : ?>
                                                <p id="settle-note-error-<?= $rowId ?>" class="error"><?= e($failedErrors['settlement_note'][0]) ?></p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="lg:pt-5.5">
                                            <button type="submit" class="btn-primary w-full sm:w-auto" x-bind:disabled="sending">
                                                Confirm <?= e(strtolower($kind->settledLabel())) ?>
                                            </button>
                                        </div>
                                        <p class="help sm:col-span-2 lg:col-span-4">
                                            Settles the full <?= e($symbol) ?> <?= e($money($row['amount'])) ?> and posts it to the
                                            ledger as &ldquo;<?= e($kind->settlementType()->label()) ?>&rdquo; in the account chosen
                                            here, which can differ from the one it was issued through. Profit is unaffected.
                                        </p>
                                    </form>
                                </details>

                                <details class="open:order-last open:basis-full" <?= $cancelOpen ? 'open' : '' ?>>
                                    <summary class="btn-secondary cursor-pointer list-none text-bad-text [&::-webkit-details-marker]:hidden">
                                        Cancel<span class="sr-only"> <?= e($singular) ?> for <?= e((string) $row['party_name']) ?></span>
                                    </summary>
                                    <form method="post" action="<?= e(url($base . '/' . $rowId . '/cancel')) ?>"
                                          class="mt-2 grid gap-3 rounded-lg border border-bad-line bg-bad-bg p-4 sm:grid-cols-[1fr_auto] sm:items-start"
                                          x-data="{ sending: false }" x-on:submit="sending = true">
                                        <?= csrf_field() ?>
                                        <div>
                                            <label for="cancel-reason-<?= $rowId ?>" class="label">Why is this being cancelled?</label>
                                            <input id="cancel-reason-<?= $rowId ?>" name="cancel_reason" type="text" required maxlength="255"
                                                   value="<?= e($cancelOpen ? $failure['cancel_reason'] : '') ?>"
                                                   placeholder="e.g. raised in error, duplicate of INV-102"
                                                   class="<?= isset($failedErrors['cancel_reason']) ? 'input-error' : 'input' ?>"
                                                   <?= isset($failedErrors['cancel_reason']) ? 'aria-invalid="true" aria-describedby="cancel-reason-error-' . $rowId . '"' : '' ?>>
                                            <?php if (isset($failedErrors['cancel_reason'][0])) : ?>
                                                <p id="cancel-reason-error-<?= $rowId ?>" class="error"><?= e($failedErrors['cancel_reason'][0]) ?></p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="sm:pt-5.5">
                                            <button type="submit" class="btn-dark w-full sm:w-auto" x-bind:disabled="sending">
                                                Confirm cancellation
                                            </button>
                                        </div>
                                        <p class="help sm:col-span-2">
                                            This cannot be undone. The ledger posting is voided with this reason, which
                                            restores the account balance. The entry stays listed; nothing is deleted.
                                        </p>
                                    </form>
                                </details>
                            </div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php require APP_PATH . '/Views/partials/pagination.php'; ?>
    </section>
<?php endif; ?>
