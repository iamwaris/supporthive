<?php

/**
 * New / edit form for a receivables or payables entry — one view for both
 * sections and both modes. Only an open entry ever reaches the edit mode
 * (the controller redirects anything else back to the list).
 *
 * Recording an entry posts it to the ledger, so in edit mode the amount,
 * issue date and account are shown read-only: they are locked once posted
 * (the service refuses a change to them, whatever the request sends).
 *
 * @var \App\Domain\ReceivablePayableKind $kind
 * @var array<string,mixed>|null          $entry  null when creating
 * @var list<array<string,mixed>>         $accounts         active accounts in this branch (create only)
 * @var int|null                          $defaultAccountId the branch's Cash account, preselected
 * @var string|null                       $accountName      the posted account (edit only)
 * @var string                            $symbol
 * @var array<string,list<string>>        $errors
 */

declare(strict_types=1);

$errors = $errors ?? [];
$isEdit = $entry !== null;
$base = $kind->path();
$action = $isEdit ? $base . '/' . (int) $entry['id'] : $base;

// Flashed input is only used straight after a failed submit (the errors are
// read-once); otherwise a stale `_old` from another form could leak in.
$useOld = $errors !== [];
$value = static function (string $field, string $fallback) use ($useOld): string {
    return $useOld ? old($field, $fallback) : $fallback;
};
$stored = static fn (string $field): string => $isEdit ? (string) ($entry[$field] ?? '') : '';

$fields = [
    'party_name' => $value('party_name', $stored('party_name')),
    'reference_no' => $value('reference_no', $stored('reference_no')),
    'description' => $value('description', $stored('description')),
    'amount' => $value('amount', $stored('amount')),
    'issue_date' => $value('issue_date', $isEdit ? $stored('issue_date') : date('Y-m-d')),
    'due_date' => $value('due_date', $stored('due_date')),
    'account_id' => $value('account_id', $defaultAccountId === null ? '' : (string) $defaultAccountId),
];
$lockedError = static fn (string $field): ?string => $errors[$field][0] ?? null;
?>

<form method="post" action="<?= e(url($action)) ?>" class="card max-w-3xl p-5 sm:p-6"
      x-data="{ saving: false }" x-on:submit="saving = true">
    <?= csrf_field() ?>

    <?php if (!$isEdit && $accounts === []) : ?>
        <div class="mb-5 rounded-lg border border-bad-line bg-bad-bg p-4 text-sm text-bad-text" role="alert">
            There is no active account to post this through.
            <a href="<?= e(url('/accounts')) ?>" class="font-medium underline">Add or reopen an account</a> first.
        </div>
    <?php endif; ?>

    <div class="grid gap-5 sm:grid-cols-2">
        <div class="sm:col-span-2">
            <label for="party_name" class="label"><?= e($kind->partyLabel()) ?></label>
            <input id="party_name" name="party_name" type="text" required maxlength="160" autocomplete="off"
                   value="<?= e($fields['party_name']) ?>"
                   class="<?= isset($errors['party_name']) ? 'input-error' : 'input' ?>" <?= isset($errors['party_name']) ? 'aria-invalid="true" aria-describedby="party_name-error"' : '' ?>>
            <?php if (isset($errors['party_name'][0])) : ?>
                <p id="party_name-error" class="error"><?= e($errors['party_name'][0]) ?></p>
            <?php endif; ?>
        </div>

        <?php if ($isEdit) : ?>
            <!-- Locked once posted: shown, never submitted. -->
            <div class="sm:col-span-2 rounded-lg border border-slate-200 bg-slate-50 p-4">
                <dl class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <dt class="kpi-label">Amount</dt>
                        <dd class="money mt-1 text-sm font-semibold text-ink">
                            <?= e($symbol) ?> <?= e(number_format((float) $entry['amount'], 2)) ?>
                        </dd>
                        <?php if ($lockedError('amount') !== null) : ?>
                            <p class="error"><?= e((string) $lockedError('amount')) ?></p>
                        <?php endif; ?>
                    </div>
                    <div>
                        <dt class="kpi-label">Issue date</dt>
                        <dd class="mt-1 text-sm text-ink"><?= e(date('j M Y', (int) strtotime((string) $entry['issue_date']))) ?></dd>
                        <?php if ($lockedError('issue_date') !== null) : ?>
                            <p class="error"><?= e((string) $lockedError('issue_date')) ?></p>
                        <?php endif; ?>
                    </div>
                    <div>
                        <dt class="kpi-label"><?= e($kind->issueAccountLabel()) ?></dt>
                        <dd class="mt-1 text-sm text-ink"><?= e($accountName ?? 'Not posted yet') ?></dd>
                        <?php if ($lockedError('account_id') !== null) : ?>
                            <p class="error"><?= e((string) $lockedError('account_id')) ?></p>
                        <?php endif; ?>
                    </div>
                </dl>
                <p class="help mt-3">
                    Amount, issue date and account are locked because this entry is posted to the ledger.
                    To correct one of them, cancel this entry (its posting is voided) and record it again.
                </p>
            </div>
        <?php else : ?>
            <div>
                <label for="amount" class="label">Amount (<?= e($symbol) ?>)</label>
                <input id="amount" name="amount" type="text" inputmode="decimal" required autocomplete="off"
                       placeholder="0.00" value="<?= e($fields['amount']) ?>"
                       class="money <?= isset($errors['amount']) ? 'input-error' : 'input' ?>" <?= isset($errors['amount']) ? 'aria-invalid="true" aria-describedby="amount-error"' : '' ?>>
                <?php if (isset($errors['amount'][0])) : ?>
                    <p id="amount-error" class="error"><?= e($errors['amount'][0]) ?></p>
                <?php endif; ?>
            </div>

            <div>
                <label for="account_id" class="label"><?= e($kind->issueAccountLabel()) ?></label>
                <select id="account_id" name="account_id" required
                        class="<?= isset($errors['account_id']) ? 'input-error' : 'input' ?>" <?= isset($errors['account_id']) ? 'aria-invalid="true" aria-describedby="account_id-error"' : 'aria-describedby="account_id-help"' ?>>
                    <?php foreach ($accounts as $account) : ?>
                        <option value="<?= e((string) $account['id']) ?>" <?= $fields['account_id'] === (string) $account['id'] ? 'selected' : '' ?>>
                            <?= e((string) $account['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['account_id'][0])) : ?>
                    <p id="account_id-error" class="error"><?= e($errors['account_id'][0]) ?></p>
                <?php else : ?>
                    <p id="account_id-help" class="help">
                        <?= e($kind->issueAccountHelp()) ?>
                    </p>
                <?php endif; ?>
            </div>

            <div>
                <label for="issue_date" class="label">Issue date</label>
                <input id="issue_date" name="issue_date" type="date" required
                       value="<?= e($fields['issue_date']) ?>"
                       class="<?= isset($errors['issue_date']) ? 'input-error' : 'input' ?>" <?= isset($errors['issue_date']) ? 'aria-invalid="true" aria-describedby="issue_date-error"' : '' ?>>
                <?php if (isset($errors['issue_date'][0])) : ?>
                    <p id="issue_date-error" class="error"><?= e($errors['issue_date'][0]) ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div>
            <label for="reference_no" class="label">
                Reference <span class="font-normal text-slate-400">optional, e.g. agreement or voucher no.</span>
            </label>
            <input id="reference_no" name="reference_no" type="text" maxlength="80" autocomplete="off"
                   value="<?= e($fields['reference_no']) ?>"
                   class="<?= isset($errors['reference_no']) ? 'input-error' : 'input' ?>" <?= isset($errors['reference_no']) ? 'aria-invalid="true" aria-describedby="reference_no-error"' : '' ?>>
            <?php if (isset($errors['reference_no'][0])) : ?>
                <p id="reference_no-error" class="error"><?= e($errors['reference_no'][0]) ?></p>
            <?php endif; ?>
        </div>

        <div>
            <label for="due_date" class="label">Due date <span class="font-normal text-slate-400">optional</span></label>
            <input id="due_date" name="due_date" type="date"
                   value="<?= e($fields['due_date']) ?>"
                   class="<?= isset($errors['due_date']) ? 'input-error' : 'input' ?>" <?= isset($errors['due_date']) ? 'aria-invalid="true" aria-describedby="due_date-error"' : '' ?>>
            <?php if (isset($errors['due_date'][0])) : ?>
                <p id="due_date-error" class="error"><?= e($errors['due_date'][0]) ?></p>
            <?php else : ?>
                <p class="help">Shown as overdue once this date has passed while still open.</p>
            <?php endif; ?>
        </div>

        <div class="sm:col-span-2">
            <label for="description" class="label">Description <span class="font-normal text-slate-400">optional</span></label>
            <input id="description" name="description" type="text" maxlength="255"
                   value="<?= e($fields['description']) ?>"
                   class="<?= isset($errors['description']) ? 'input-error' : 'input' ?>" <?= isset($errors['description']) ? 'aria-invalid="true" aria-describedby="description-error"' : '' ?>>
            <?php if (isset($errors['description'][0])) : ?>
                <p id="description-error" class="error"><?= e($errors['description'][0]) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$isEdit) : ?>
        <p class="help mt-5">
            Recording this posts it to the ledger and changes the account balance straight away. It is never
            income or an expense, so profit, budgets and partner shares are unaffected. Once recorded, the
            amount, issue date and account are locked; to change them, cancel the entry and record it again.
        </p>
    <?php endif; ?>

    <div class="mt-5 flex flex-wrap items-center gap-3">
        <button type="submit" class="btn-primary" x-bind:disabled="saving">
            <span x-show="!saving"><?= e($isEdit ? 'Save changes' : 'Record ' . $kind->singular()) ?></span>
            <span x-show="saving" x-cloak>Saving&hellip;</span>
        </button>
        <a href="<?= e(url($base)) ?>" class="btn-secondary">Back to <?= e(strtolower($kind->title())) ?></a>
    </div>
    <p class="sr-only" aria-live="polite" x-text="saving ? 'Saving, please wait.' : ''"></p>
</form>
