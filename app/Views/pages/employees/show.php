<?php

/**
 * One employee — admin only. Profile, account actions and salary.
 *
 * The salary card is deliberately NOT part of partials/employee-profile.php:
 * that partial is also the employee's own portal view, and salary is
 * admin-only data.
 *
 * @var array<string,mixed>        $employee        EmployeeProfile::findWithUser() row
 * @var array<string,mixed>|null   $currentSalary   EmployeeSalary::current()
 * @var array<string,mixed>|null   $upcomingSalary  EmployeeSalary::upcoming()
 * @var list<array<string,mixed>>  $salaryHistory   EmployeeSalary::history()
 * @var array<string,list<string>> $errors
 */

declare(strict_types=1);

use App\Services\Settings;

$errors = $errors ?? [];
$id = (int) $employee['id'];
$isActive = (string) $employee['status'] === 'active';
$profile = $employee;

$symbol = Settings::string('currency_symbol', 'Rs');
$money = static fn (mixed $v): string => number_format((float) $v, 2);
$day = static fn (mixed $date): string => date('j M Y', (int) strtotime((string) $date));

// Flashed input only right after a failed salary change; otherwise defaults.
$salaryValue = static function (string $field, string $default) use ($errors): string {
    return $errors !== [] ? old($field, $default) : $default;
};

$salaryFields = [
    ['name' => 'amount', 'label' => 'New salary (' . $symbol . ')', 'type' => 'text', 'required' => true, 'max' => 13, 'inputmode' => 'decimal', 'default' => '', 'help' => 'Digits only, up to 2 decimal places.'],
    ['name' => 'effective_from', 'label' => 'Effective from', 'type' => 'date', 'required' => true, 'max' => null, 'inputmode' => null, 'default' => date('Y-m-d'), 'help' => 'A future date schedules the change.'],
    ['name' => 'note', 'label' => 'Note', 'type' => 'text', 'required' => false, 'max' => 255, 'inputmode' => null, 'default' => '', 'help' => 'For example: annual raise.'],
];

$statusLabels = ['scheduled' => 'Scheduled', 'current' => 'Current', 'past' => 'Past'];
$statusBadges = ['scheduled' => 'badge-warn', 'current' => 'badge-ok', 'past' => 'badge-mute'];
?>

<div class="flex flex-col gap-5">
    <p>
        <a href="<?= e(url('/employees')) ?>" class="text-xs font-medium text-slate-600 hover:text-ink hover:underline">&larr; All employees</a>
    </p>

    <div class="grid gap-5 lg:grid-cols-[1fr_320px]">
        <section class="card p-5 sm:p-6" aria-labelledby="profile-heading">
            <div class="mb-5 flex flex-wrap items-center gap-3">
                <h2 id="profile-heading" class="flex-1 font-display text-sm font-semibold text-ink">Profile</h2>
                <a href="<?= e(url('/employees/' . $id . '/edit')) ?>" class="btn-secondary">Edit</a>
            </div>
            <?php require APP_PATH . '/Views/partials/employee-profile.php'; ?>

            <?php if ((int) $employee['must_change_password'] === 1) : ?>
                <p class="help mt-5">Must change their password at next sign-in.</p>
            <?php endif; ?>
        </section>

        <section class="card flex flex-col gap-5 p-5" aria-labelledby="account-heading">
            <h2 id="account-heading" class="font-display text-sm font-semibold text-ink">Account</h2>

            <div>
                <p class="text-xs text-slate-600">
                    <?= e($isActive
                        ? 'Deactivating signs the employee out immediately and blocks sign-in until reactivated. Nothing is deleted.'
                        : 'This employee cannot sign in. Reactivate to restore access.') ?>
                </p>
                <form method="post" action="<?= e(url('/employees/' . $id . '/toggle')) ?>" class="mt-2">
                    <?= csrf_field() ?>
                    <button type="submit" class="<?= $isActive ? 'btn-secondary text-bad-text' : 'btn-dark' ?>">
                        <?= e($isActive ? 'Deactivate' : 'Reactivate') ?>
                    </button>
                </form>
            </div>

            <div class="border-t border-slate-200 pt-5">
                <p class="text-xs text-slate-600">
                    Issues a new one-time password, shown to you once. The employee must change it at next sign-in.
                </p>
                <details class="mt-2">
                    <summary class="btn-secondary cursor-pointer list-none [&::-webkit-details-marker]:hidden">Reset password</summary>
                    <div class="mt-2 rounded-lg border border-slate-200 bg-slate-50 p-3">
                        <p class="text-xs text-slate-700">Their current password stops working immediately.</p>
                        <form method="post" action="<?= e(url('/employees/' . $id . '/reset-password')) ?>" class="mt-2">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn-dark">Yes, reset password</button>
                        </form>
                    </div>
                </details>
            </div>
        </section>
    </div>

    <section id="salary" class="card overflow-hidden" aria-labelledby="salary-heading">
        <div class="px-5 pt-4 pb-3 sm:px-6">
            <h2 id="salary-heading" class="font-display text-sm font-semibold text-ink">Salary</h2>
            <p class="mt-0.5 text-[11.5px] text-slate-500">
                Visible to admins only — never shown to the employee. Every change is kept.
            </p>
        </div>

        <div class="grid gap-5 border-t border-slate-200 p-5 sm:p-6 lg:grid-cols-[1fr_320px]">
            <dl class="grid content-start gap-x-6 gap-y-4 sm:grid-cols-2">
                <div class="min-w-0">
                    <dt class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Current salary</dt>
                    <?php if ($currentSalary === null) : ?>
                        <dd class="mt-1 text-sm text-slate-600">No salary recorded</dd>
                    <?php else : ?>
                        <dd class="money mt-1 text-lg font-semibold text-ink">
                            <?= e($symbol) ?> <?= e($money($currentSalary['amount'])) ?>
                        </dd>
                        <dd class="text-xs text-slate-600">Since <?= e($day($currentSalary['effective_from'])) ?></dd>
                    <?php endif; ?>
                </div>
                <div class="min-w-0">
                    <dt class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Next scheduled change</dt>
                    <?php if ($upcomingSalary === null) : ?>
                        <dd class="mt-1 text-sm text-slate-600">None scheduled</dd>
                    <?php else : ?>
                        <dd class="money mt-1 text-lg font-semibold text-ink">
                            <?= e($symbol) ?> <?= e($money($upcomingSalary['amount'])) ?>
                        </dd>
                        <dd class="text-xs text-slate-600">From <?= e($day($upcomingSalary['effective_from'])) ?></dd>
                    <?php endif; ?>
                </div>
            </dl>

            <form method="post" action="<?= e(url('/employees/' . $id . '/salary')) ?>"
                  class="flex flex-col gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4" novalidate
                  aria-labelledby="salary-change-heading">
                <h3 id="salary-change-heading" class="font-display text-[13px] font-semibold text-ink">Change salary</h3>
                <?= csrf_field() ?>
                <?php foreach ($salaryFields as $field) : ?>
                    <?php
                    $name = $field['name'];
                    $inputId = 'salary-' . str_replace('_', '-', $name);
                    $hasError = isset($errors[$name][0]);
                    $describedBy = trim($inputId . '-help ' . ($hasError ? $inputId . '-error' : ''));
                    ?>
                    <div>
                        <label for="<?= e($inputId) ?>" class="label">
                            <?= e($field['label']) ?>
                            <?php if (!$field['required']) : ?>
                                <span class="font-normal text-slate-400">(optional)</span>
                            <?php endif; ?>
                        </label>
                        <input id="<?= e($inputId) ?>" name="<?= e($name) ?>" type="<?= e($field['type']) ?>"
                               value="<?= e($salaryValue($name, $field['default'])) ?>"
                               <?= $field['required'] ? 'required' : '' ?>
                               <?= $field['max'] !== null ? 'maxlength="' . e((string) $field['max']) . '"' : '' ?>
                               <?= $field['inputmode'] !== null ? 'inputmode="' . e($field['inputmode']) . '"' : '' ?>
                               autocomplete="off"
                               aria-describedby="<?= e($describedBy) ?>"
                               <?= $hasError ? 'aria-invalid="true"' : '' ?>
                               class="<?= $hasError ? 'input-error' : 'input' ?> <?= $name === 'amount' ? 'money' : '' ?>">
                        <p id="<?= e($inputId . '-help') ?>" class="help"><?= e($field['help']) ?></p>
                        <?php if ($hasError) : ?>
                            <p id="<?= e($inputId . '-error') ?>" class="error"><?= e($errors[$name][0]) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <div>
                    <button type="submit" class="btn-dark">Save salary change</button>
                </div>
            </form>
        </div>

        <div class="border-t border-slate-200">
            <h3 class="px-5 pt-4 pb-2 font-display text-[13px] font-semibold text-ink sm:px-6">History</h3>
            <div class="overflow-x-auto">
                <table class="w-full border-collapse">
                    <caption class="sr-only">Salary history, newest effective date first</caption>
                    <thead>
                        <tr>
                            <th scope="col" class="th">Effective from</th>
                            <th scope="col" class="th text-right">Amount</th>
                            <th scope="col" class="th hidden text-right sm:table-cell">Change</th>
                            <th scope="col" class="th hidden md:table-cell">Note</th>
                            <th scope="col" class="th hidden lg:table-cell">Changed by</th>
                            <th scope="col" class="th hidden lg:table-cell">Recorded at</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($salaryHistory === []) : ?>
                            <tr>
                                <td class="td" colspan="6">
                                    <p class="py-8 text-center text-sm text-slate-600">
                                        No salary recorded yet. Use “Change salary” to record the first one.
                                    </p>
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($salaryHistory as $row) : ?>
                            <?php
                            $change = $row['change'];
                            if ($change === null) {
                                $changeText = 'First record';
                            } elseif (bccomp((string) $change, '0', 2) === 0) {
                                $changeText = 'No change';
                            } else {
                                $isRaise = bccomp((string) $change, '0', 2) > 0;
                                $changeText = ($isRaise ? '+' : '−') . $symbol . ' '
                                    . $money(ltrim((string) $change, '-')) . ($isRaise ? ' increase' : ' decrease');
                            }
                            $status = (string) $row['status'];
                            $note = $row['note'] !== null && $row['note'] !== '' ? (string) $row['note'] : null;
                            ?>
                            <tr>
                                <td class="td">
                                    <span class="whitespace-nowrap text-ink"><?= e($day($row['effective_from'])) ?></span>
                                    <span class="<?= e($statusBadges[$status] ?? 'badge-mute') ?> ml-1"><?= e($statusLabels[$status] ?? $status) ?></span>
                                    <p class="text-[11.5px] text-slate-500 sm:hidden"><?= e($changeText) ?></p>
                                    <?php if ($note !== null) : ?>
                                        <p class="text-[11.5px] break-words text-slate-500 md:hidden"><?= e($note) ?></p>
                                    <?php endif; ?>
                                    <p class="text-[11.5px] text-slate-500 lg:hidden">
                                        By <?= e((string) ($row['created_by_name'] ?? 'Unknown')) ?>, <?= e($day($row['created_at'])) ?>
                                    </p>
                                </td>
                                <td class="td money text-right whitespace-nowrap text-ink">
                                    <?= e($symbol) ?> <?= e($money($row['amount'])) ?>
                                </td>
                                <td class="td money hidden text-right whitespace-nowrap text-slate-600 sm:table-cell"><?= e($changeText) ?></td>
                                <td class="td hidden break-words text-slate-600 md:table-cell"><?= e($note ?? '—') ?></td>
                                <td class="td hidden text-slate-600 lg:table-cell"><?= e((string) ($row['created_by_name'] ?? 'Unknown')) ?></td>
                                <td class="td hidden whitespace-nowrap text-slate-600 lg:table-cell">
                                    <?= e(date('j M Y, H:i', (int) strtotime((string) $row['created_at']))) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>
