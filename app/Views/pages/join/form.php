<?php

/**
 * The public new-joiner form. Plain HTML: no JavaScript is needed to fill it
 * in or submit it. Asks only for the joiner's own details — designation,
 * salary and Employee ID are set by an admin on approval and are not fields
 * here.
 *
 * @var string                     $action    /join/{token}
 * @var string                     $honeypot  name of the trap field
 * @var array<string,list<string>> $errors
 */

declare(strict_types=1);

$errors = $errors ?? [];
$bankFailed = isset($errors['bank_account_number']) || isset($errors['bank_iban']);

$sections = [
    'About you' => [
        ['name' => 'name', 'label' => 'Full name', 'type' => 'text', 'required' => true, 'max' => 120, 'autocomplete' => 'name', 'inputmode' => null, 'help' => null],
        ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'max' => 190, 'autocomplete' => 'email', 'inputmode' => 'email', 'help' => 'We will use this to set up your account.'],
        ['name' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'required' => false, 'max' => 30, 'autocomplete' => 'tel', 'inputmode' => 'tel', 'help' => 'Digits, spaces, + - ( ) only.'],
        ['name' => 'joining_date', 'label' => 'Joining date', 'type' => 'date', 'required' => false, 'max' => null, 'autocomplete' => 'off', 'inputmode' => null, 'help' => 'If you know it.'],
    ],
    'Bank details for your salary' => [
        ['name' => 'bank_name', 'label' => 'Bank name', 'type' => 'text', 'required' => false, 'max' => 100, 'autocomplete' => 'off', 'inputmode' => null, 'help' => null],
        ['name' => 'bank_account_number', 'label' => 'Account number', 'type' => 'text', 'required' => false, 'max' => 40, 'autocomplete' => 'off', 'inputmode' => 'numeric', 'help' => '6 to 24 digits.'],
        ['name' => 'bank_iban', 'label' => 'IBAN', 'type' => 'text', 'required' => false, 'max' => 40, 'autocomplete' => 'off', 'inputmode' => null, 'help' => '24 characters starting with PK, e.g. PK36 SCBL 0000 0011 2345 6702.'],
    ],
];
?>
<header class="mb-6">
    <h1 class="font-display text-2xl font-semibold tracking-tight text-ink">Welcome — tell us about yourself</h1>
    <p class="mt-2 text-sm text-slate-600">
        Fill this in once. After you submit you will not be able to see or change it, so please check your
        details first. If something needs correcting later, contact the person who sent you this link.
    </p>
</header>

<form method="post" action="<?= e(url($action)) ?>" class="card flex flex-col gap-6 p-5 sm:p-6" novalidate>
    <?= csrf_field() ?>

    <?php foreach ($sections as $legend => $fields) : ?>
        <fieldset class="flex flex-col gap-4">
            <legend class="mb-1 font-display text-sm font-semibold text-ink"><?= e($legend) ?></legend>
            <?php if ($legend !== 'About you') : ?>
                <p class="help -mt-2">
                    Optional. Seen only by the admins who set up your salary.
                    <?php if ($bankFailed) : ?>
                        For your security, the account number and IBAN are not kept after an error — please enter them again.
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <?php foreach ($fields as $field) : ?>
                <?php
                $name = $field['name'];
                $inputId = 'join-' . str_replace('_', '-', $name);
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
                           value="<?= e(old($name)) ?>"
                           <?= $field['required'] ? 'required' : '' ?>
                           <?= $field['max'] !== null ? 'maxlength="' . e((string) $field['max']) . '"' : '' ?>
                           autocomplete="<?= e($field['autocomplete']) ?>"
                           <?= $field['inputmode'] !== null ? 'inputmode="' . e($field['inputmode']) . '"' : '' ?>
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
        </fieldset>
    <?php endforeach; ?>

    <?php /* Spam trap: off-screen, hidden from assistive technology and skipped by Tab. People leave it empty. */ ?>
    <div class="absolute -left-[9999px] h-px w-px overflow-hidden" aria-hidden="true">
        <label for="join-<?= e($honeypot) ?>">Leave this field empty</label>
        <input id="join-<?= e($honeypot) ?>" name="<?= e($honeypot) ?>" type="text" value="" tabindex="-1" autocomplete="off">
    </div>

    <div>
        <button type="submit" class="btn-primary w-full sm:w-auto">Submit my details</button>
    </div>
</form>
