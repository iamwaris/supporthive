<?php

/**
 * One joiner's submission — admin only. Everything they sent, bank details
 * in full (admin-only, like the employee page), and, while it is pending,
 * the approve and delete forms. Once approved the bank details live only on
 * the employee, so this page points there instead.
 *
 * @var array<string,mixed>        $submission  JoinerSubmission::findWithReviewer()
 * @var array{bank_name:?string,bank_account_number:?string,bank_iban:?string}|null $bankDetails
 *      null once approved
 * @var array<string,string>       $defaults    owner's defaults for designation and salary
 * @var array<string,list<string>> $errors
 */

declare(strict_types=1);

use App\Services\Settings;

$errors = $errors ?? [];
$id = (int) $submission['id'];
$status = (string) $submission['status'];
$isPending = $status === 'pending';
$day = static fn (mixed $date): string => date('j M Y', (int) strtotime((string) $date));
$bankShown = static fn (string $field): string => $bankDetails[$field] ?? 'Not given';

$rows = [
    'Full name' => (string) $submission['name'],
    'Email' => (string) $submission['email'],
    'Phone' => $submission['phone'] !== null ? (string) $submission['phone'] : 'Not given',
    'Joining date' => $submission['joining_date'] !== null ? $day($submission['joining_date']) : 'Not given',
];
if ($bankDetails !== null) {
    $rows += [
        'Bank name' => $bankShown('bank_name'),
        'Account number' => $bankShown('bank_account_number'),
        'IBAN' => $bankShown('bank_iban'),
    ];
}

$approveFailed = array_intersect_key(
    $errors,
    array_flip(['employee_code', 'designation', 'salary', 'email', 'joining_date'])
) !== [];

// The approve form opens prefilled: the owner's defaults for designation and
// salary, and what the joiner gave for email and joining date. After a failed
// approve, what the admin typed instead.
$approveValue = static function (string $field, string $prefill) use ($approveFailed): string {
    return $approveFailed ? old($field, $prefill) : $prefill;
};
$approveFields = [
    ['name' => 'employee_code', 'label' => 'Employee ID', 'type' => 'text', 'required' => true, 'max' => 30, 'inputmode' => null, 'prefill' => '', 'help' => 'Letters, digits and - only. Unique in this branch.'],
    ['name' => 'designation', 'label' => 'Designation', 'type' => 'text', 'required' => true, 'max' => 120, 'inputmode' => null, 'prefill' => $defaults['designation'], 'help' => null],
    ['name' => 'salary', 'label' => 'Starting salary (' . Settings::string('currency_symbol', 'Rs') . ')', 'type' => 'text', 'required' => false, 'max' => 13, 'inputmode' => 'decimal', 'prefill' => $defaults['salary'], 'help' => 'Takes effect from the joining date, or today if there is none. Leave empty for no salary record.'],
    ['name' => 'email', 'label' => 'Email (used to sign in)', 'type' => 'email', 'required' => true, 'max' => 190, 'inputmode' => null, 'prefill' => (string) $submission['email'], 'help' => null],
    ['name' => 'joining_date', 'label' => 'Joining date', 'type' => 'date', 'required' => false, 'max' => null, 'inputmode' => null, 'prefill' => (string) ($submission['joining_date'] ?? ''), 'help' => null],
];
?>

<div class="flex flex-col gap-5">
    <p>
        <a href="<?= e(url('/joiners')) ?>" class="text-xs font-medium text-slate-600 hover:text-ink hover:underline">&larr; All joiners</a>
    </p>

    <div class="grid gap-5 lg:grid-cols-[1fr_360px]">
        <section class="card p-5 sm:p-6" aria-labelledby="submission-heading">
            <div class="mb-5 flex flex-wrap items-center gap-3">
                <h2 id="submission-heading" class="flex-1 font-display text-sm font-semibold text-ink">What they sent</h2>
                <?php if ($isPending) : ?>
                    <span class="badge-warn">Pending review</span>
                <?php else : ?>
                    <span class="badge-ok">Approved</span>
                <?php endif; ?>
            </div>
            <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                <?php foreach ($rows as $label => $text) : ?>
                    <div class="min-w-0">
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-slate-500"><?= e($label) ?></dt>
                        <dd class="mt-1 text-sm break-all <?= $text === 'Not given' ? 'text-slate-500' : 'text-ink' ?>">
                            <?= e($text) ?>
                        </dd>
                    </div>
                <?php endforeach; ?>
            </dl>
            <?php if ($bankDetails !== null) : ?>
                <p class="help mt-5">Bank details are visible to admins only.</p>
            <?php elseif ($submission['employee_user_id'] !== null) : ?>
                <p class="mt-5 text-sm text-slate-700">
                    Bank details moved to the employee record.
                    <a href="<?= e(url('/employees/' . (int) $submission['employee_user_id'] . '#bank-details')) ?>"
                       class="font-medium text-ink underline">See them there</a>.
                </p>
            <?php else : ?>
                <p class="mt-5 text-sm text-slate-700">Bank details moved to the employee record.</p>
            <?php endif; ?>

            <?php if (!$isPending) : ?>
                <div class="mt-5 border-t border-slate-200 pt-4 text-sm text-slate-700">
                    <p>
                        Approved by <?= e((string) ($submission['reviewed_by_name'] ?? 'Unknown')) ?>
                        on <?= e($day($submission['reviewed_at'])) ?>.
                    </p>
                    <?php if ($submission['employee_user_id'] !== null) : ?>
                        <p class="mt-1">
                            <a href="<?= e(url('/employees/' . (int) $submission['employee_user_id'])) ?>" class="font-medium text-ink underline">View the employee</a>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($isPending) : ?>
            <div class="flex flex-col gap-5">
                <form id="approve" method="post" action="<?= e(url('/joiners/' . $id . '/approve')) ?>"
                      class="card flex flex-col gap-3 p-5" novalidate aria-labelledby="approve-heading">
                    <h2 id="approve-heading" class="font-display text-sm font-semibold text-ink">Approve</h2>
                    <p class="text-xs text-slate-600">
                        Creates the employee and their login. You will see a one-time temporary password to pass on;
                        they must change it the first time they sign in.
                    </p>
                    <?= csrf_field() ?>
                    <?php foreach ($approveFields as $field) : ?>
                        <?php
                        $name = $field['name'];
                        $inputId = 'approve-' . str_replace('_', '-', $name);
                        $hasError = isset($errors[$name][0]);
                        $describedBy = trim(($field['help'] !== null ? $inputId . '-help ' : '') . ($hasError ? $inputId . '-error' : ''));
                        ?>
                        <div>
                            <label for="<?= e($inputId) ?>" class="label">
                                <?= e($field['label']) ?>
                                <?php if (!$field['required']) : ?>
                                    <span class="font-normal text-slate-500">(optional)</span>
                                <?php endif; ?>
                            </label>
                            <input id="<?= e($inputId) ?>" name="<?= e($name) ?>" type="<?= e($field['type']) ?>"
                                   value="<?= e($approveValue($name, $field['prefill'])) ?>"
                                   <?= $field['required'] ? 'required' : '' ?>
                                   <?= $field['max'] !== null ? 'maxlength="' . e((string) $field['max']) . '"' : '' ?>
                                   <?= $field['inputmode'] !== null ? 'inputmode="' . e($field['inputmode']) . '"' : '' ?>
                                   autocomplete="off"
                                   <?= $describedBy !== '' ? 'aria-describedby="' . e($describedBy) . '"' : '' ?>
                                   <?= $hasError ? 'aria-invalid="true"' : '' ?>
                                   class="<?= $hasError ? 'input-error' : 'input' ?>">
                            <?php if ($field['help'] !== null) : ?>
                                <p id="<?= e($inputId . '-help') ?>" class="help"><?= e($field['help']) ?></p>
                            <?php endif; ?>
                            <?php if ($hasError) : ?>
                                <p id="<?= e($inputId . '-error') ?>" class="error"><?= e($errors[$name][0]) ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <div>
                        <button type="submit" class="btn-primary">Approve and create employee</button>
                    </div>
                </form>

                <section id="delete" class="card p-5" aria-labelledby="delete-heading">
                    <h2 id="delete-heading" class="font-display text-sm font-semibold text-ink">Delete</h2>
                    <p class="mt-1 text-xs text-slate-600">
                        Not joining, or sent twice? Deleting removes everything they submitted, bank details included.
                        No account is created.
                    </p>
                    <details class="mt-3">
                        <summary class="btn-secondary cursor-pointer list-none text-bad-text [&::-webkit-details-marker]:hidden">Delete this joiner</summary>
                        <div class="mt-3 rounded-lg border border-slate-200 bg-slate-50 p-3">
                            <p class="text-xs text-slate-700">
                                This cannot be undone. <?= e((string) $submission['name']) ?>’s submission is removed permanently.
                            </p>
                            <form method="post" action="<?= e(url('/joiners/' . $id . '/delete')) ?>" class="mt-2">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn-dark">Yes, delete permanently</button>
                            </form>
                        </div>
                    </details>
                </section>
            </div>
        <?php endif; ?>
    </div>
</div>
