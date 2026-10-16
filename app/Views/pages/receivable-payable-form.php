<?php

/**
 * New / edit form for a receivables or payables entry — one view for both
 * sections and both modes. Only an open entry ever reaches the edit mode
 * (the controller redirects anything else back to the list).
 *
 * @var \App\Domain\ReceivablePayableKind $kind
 * @var array<string,mixed>|null          $entry  null when creating
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
];
?>

<form method="post" action="<?= e(url($action)) ?>" class="card max-w-3xl p-5 sm:p-6"
      x-data="{ saving: false }" x-on:submit="saving = true">
    <?= csrf_field() ?>

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
            <label for="reference_no" class="label">
                Reference <span class="font-normal text-slate-400">optional, e.g. invoice or bill no.</span>
            </label>
            <input id="reference_no" name="reference_no" type="text" maxlength="80" autocomplete="off"
                   value="<?= e($fields['reference_no']) ?>"
                   class="<?= isset($errors['reference_no']) ? 'input-error' : 'input' ?>" <?= isset($errors['reference_no']) ? 'aria-invalid="true" aria-describedby="reference_no-error"' : '' ?>>
            <?php if (isset($errors['reference_no'][0])) : ?>
                <p id="reference_no-error" class="error"><?= e($errors['reference_no'][0]) ?></p>
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

    <p class="help mt-5">
        Tracked in this register only &mdash; it is never posted to the ledger and does not change any
        account balance, budget or report.
    </p>

    <div class="mt-5 flex flex-wrap items-center gap-3">
        <button type="submit" class="btn-primary" x-bind:disabled="saving">
            <span x-show="!saving"><?= e($isEdit ? 'Save changes' : 'Record ' . $kind->singular()) ?></span>
            <span x-show="saving" x-cloak>Saving&hellip;</span>
        </button>
        <a href="<?= e(url($base)) ?>" class="btn-secondary">Back to <?= e(strtolower($kind->title())) ?></a>
    </div>
    <p class="sr-only" aria-live="polite" x-text="saving ? 'Saving, please wait.' : ''"></p>
</form>
