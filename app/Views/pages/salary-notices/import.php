<?php

/**
 * Import a month's salary sheet. Plain multipart form; nothing here needs
 * JavaScript. A rejected sheet comes back to this page with every problem
 * listed by row, and nothing saved.
 *
 * @var string                                                   $defaultMonth  YYYY-MM, last month
 * @var list<array{row:int,employee_code:string,message:string}> $importErrors
 * @var string|null                                              $replaceMonth  set after "a run already exists"
 * @var array{period_month:string,note:string}|null              $submitted     values of a 422 render
 * @var array<string,list<string>>                               $errors
 */

declare(strict_types=1);

$errors = $errors ?? [];
$month = $submitted['period_month'] ?? old('period_month', $defaultMonth);
$note = $submitted['note'] ?? old('note');

$field = static function (string $name) use ($errors): array {
    $hasError = isset($errors[$name][0]);

    return [
        'class' => $hasError ? 'input-error' : 'input',
        'error' => $errors[$name][0] ?? null,
    ];
};
$monthField = $field('period_month');
$sheetField = $field('sheet');
$noteField = $field('note');
?>
<div class="flex max-w-3xl flex-col gap-5">

    <?php if ($importErrors !== []) : ?>
        <section class="card border-bad-line p-5 sm:p-6" role="alert" aria-labelledby="import-errors-heading">
            <h2 id="import-errors-heading" class="font-display text-sm font-semibold text-bad-text">
                Nothing was imported — <?= e(count($importErrors) === 1 ? '1 problem' : count($importErrors) . ' problems') ?> in the file
            </h2>
            <p class="mt-1 text-xs text-slate-600">Correct these in the sheet, then choose the file again and import.</p>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full border-collapse">
                    <caption class="sr-only">Problems found in the uploaded sheet, by row</caption>
                    <thead>
                        <tr>
                            <th scope="col" class="th">Row</th>
                            <th scope="col" class="th">EmpID</th>
                            <th scope="col" class="th">Problem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($importErrors as $problem) : ?>
                            <tr>
                                <td class="td money"><?= e((string) $problem['row']) ?></td>
                                <td class="td money"><?= e($problem['employee_code'] !== '' ? $problem['employee_code'] : '—') ?></td>
                                <td class="td"><?= e($problem['message']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/salary-notices')) ?>" enctype="multipart/form-data" class="card flex flex-col gap-5 p-5 sm:p-6" novalidate>
        <?= csrf_field() ?>

        <div>
            <label for="import-period_month" class="label">Month</label>
            <input id="import-period_month" name="period_month" type="month" required value="<?= e($month) ?>"
                   pattern="20[0-9]{2}-(0[1-9]|1[0-2])" placeholder="YYYY-MM"
                   class="<?= e($monthField['class']) ?> sm:max-w-xs"
                   <?= $monthField['error'] !== null ? 'aria-invalid="true" aria-describedby="import-period_month-error"' : '' ?>>
            <p class="help">The month these salaries are for. Each month has one run per branch.</p>
            <?php if ($monthField['error'] !== null) : ?>
                <p id="import-period_month-error" class="error"><?= e($monthField['error']) ?></p>
            <?php endif; ?>
        </div>

        <?php if ($replaceMonth !== null) : ?>
            <div class="rounded-lg border border-brand-400 bg-brand-50 p-4">
                <label class="flex items-start gap-2 text-sm text-ink">
                    <input type="checkbox" name="replace" value="1" class="mt-0.5 h-4 w-4">
                    <span>
                        <strong>Replace its lines.</strong> The run for <?= e($replaceMonth) ?> has not been emailed yet;
                        its current lines and attached screenshots are deleted and replaced by this file.
                    </span>
                </label>
            </div>
        <?php endif; ?>

        <div>
            <label for="import-sheet" class="label">Salary sheet (.xlsx or .csv)</label>
            <input id="import-sheet" name="sheet" type="file" required
                   accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv"
                   class="<?= e($sheetField['class']) ?>" aria-describedby="import-sheet-help<?= $sheetField['error'] !== null ? ' import-sheet-error' : '' ?>"
                   <?= $sheetField['error'] !== null ? 'aria-invalid="true"' : '' ?>>
            <p id="import-sheet-help" class="help">
                First row: <span class="money">EmpID, Salary, Absent, WorkingDays</span>, then one row per employee.
                Salary is the final amount to pay. Absent and WorkingDays are shown to the employee as they are.
            </p>
            <?php if ($sheetField['error'] !== null) : ?>
                <p id="import-sheet-error" class="error"><?= e($sheetField['error']) ?></p>
            <?php endif; ?>
        </div>

        <div>
            <label for="import-note" class="label">Note for the month <span class="font-normal text-slate-500">(optional)</span></label>
            <textarea id="import-note" name="note" rows="4" maxlength="5000"
                      class="<?= e($noteField['class']) ?>"
                      <?= $noteField['error'] !== null ? 'aria-invalid="true" aria-describedby="import-note-error"' : '' ?>><?= e($note) ?></textarea>
            <p class="help">Shown to every employee on their notice. You can change it until the first notice is emailed.</p>
            <?php if ($noteField['error'] !== null) : ?>
                <p id="import-note-error" class="error"><?= e($noteField['error']) ?></p>
            <?php endif; ?>
        </div>

        <div class="flex flex-wrap gap-2">
            <button type="submit" class="btn-primary">Import</button>
            <a href="<?= e(url('/salary-notices')) ?>" class="btn-secondary">Cancel</a>
        </div>
    </form>
</div>
