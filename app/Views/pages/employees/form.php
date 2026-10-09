<?php

/**
 * Add / edit an employee — one form for both. $employee is null when adding.
 *
 * @var array<string,mixed>|null   $employee
 * @var array<string,list<string>> $errors
 */

declare(strict_types=1);

use App\Services\Settings;

$errors = $errors ?? [];
$editing = $employee !== null;
$action = $editing ? '/employees/' . (int) $employee['id'] : '/employees';
$cancel = $editing ? '/employees/' . (int) $employee['id'] : '/employees';

// Flashed input only right after a failed submit: on a fresh edit page a
// leftover value from some earlier form must not overwrite the record.
$value = static function (string $field) use ($employee, $errors): string {
    $current = $employee !== null ? (string) ($employee[$field] ?? '') : '';

    return $errors !== [] ? old($field, $current) : $current;
};

$fields = [
    ['name' => 'name', 'label' => 'Full name', 'type' => 'text', 'required' => true, 'max' => 120, 'autocomplete' => 'name', 'inputmode' => null, 'help' => null],
    ['name' => 'email', 'label' => 'Email (used to sign in)', 'type' => 'email', 'required' => true, 'max' => 190, 'autocomplete' => 'email', 'inputmode' => null, 'help' => null],
    ['name' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'required' => false, 'max' => 30, 'autocomplete' => 'tel', 'inputmode' => 'tel', 'help' => 'Digits, spaces, + - ( ) only.'],
    ['name' => 'designation', 'label' => 'Designation', 'type' => 'text', 'required' => true, 'max' => 120, 'autocomplete' => 'organization-title', 'inputmode' => null, 'help' => null],
    ['name' => 'joining_date', 'label' => 'Joining date', 'type' => 'date', 'required' => false, 'max' => null, 'autocomplete' => 'off', 'inputmode' => null, 'help' => null],
];

// Creation only: after that, salary changes go through the employee page so
// each one gets an effective date and an audit entry.
if (!$editing) {
    $fields[] = ['name' => 'salary', 'label' => 'Starting salary (' . Settings::string('currency_symbol', 'Rs') . ')', 'type' => 'text', 'required' => false, 'max' => 13, 'autocomplete' => 'off', 'inputmode' => 'decimal', 'help' => 'Digits only, up to 2 decimal places. Takes effect from the joining date, or today. Admins only; never shown to the employee.'];
}
?>

<form method="post" action="<?= e(url($action)) ?>" class="card max-w-2xl p-5 sm:p-6" novalidate>
    <?= csrf_field() ?>

    <div class="grid gap-4 sm:grid-cols-2">
        <?php foreach ($fields as $field) : ?>
            <?php
            $name = $field['name'];
            $inputId = 'emp-' . str_replace('_', '-', $name);
            $hasError = isset($errors[$name][0]);
            $describedBy = trim(($field['help'] !== null ? $inputId . '-help ' : '') . ($hasError ? $inputId . '-error' : ''));
            ?>
            <div class="<?= $name === 'name' || $name === 'email' ? 'sm:col-span-2' : '' ?>">
                <label for="<?= e($inputId) ?>" class="label">
                    <?= e($field['label']) ?>
                    <?php if (!$field['required']) : ?>
                        <span class="font-normal text-slate-400">(optional)</span>
                    <?php endif; ?>
                </label>
                <input id="<?= e($inputId) ?>" name="<?= e($name) ?>" type="<?= e($field['type']) ?>"
                       value="<?= e($value($name)) ?>"
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
    </div>

    <?php if (!$editing) : ?>
        <p class="help mt-4">
            The employee gets a one-time temporary password, shown to you once after saving.
            They must change it the first time they sign in.
        </p>
    <?php endif; ?>

    <div class="mt-5 flex flex-wrap gap-2">
        <button type="submit" class="btn-primary"><?= e($editing ? 'Save changes' : 'Add employee') ?></button>
        <a href="<?= e(url($cancel)) ?>" class="btn-secondary">Cancel</a>
    </div>
</form>
