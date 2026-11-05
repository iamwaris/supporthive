<?php

/**
 * One month's salary notices: the note, then one row per employee with
 * their figures, screenshot, email status and response, and the actions
 * each line allows in its current state. Plain forms throughout; <details>
 * is the confirm step for sending, so nothing needs JavaScript.
 *
 * @var array<string,mixed>                               $run
 * @var list<array<string,mixed>>                         $lines        PayrollRunLine::forRun()
 * @var bool                                              $noteEditable no line emailed yet
 * @var list<array{name:string,employee_code:?string}>    $missing      active employees with no line in this run
 * @var list<array{row:int,employee_code:string,message:string}> $sheetErrors problems in a rejected "add employees" file
 * @var string                                            $symbol       currency symbol
 * @var float                                             $maxUploadMb
 * @var int                                               $editLineId   the line whose figure form just failed, else 0
 * @var array<string,list<string>>                        $errors
 */

declare(strict_types=1);

use App\Domain\PayrollLineStatus;

$errors = $errors ?? [];
$runId = (int) $run['id'];
$base = '/salary-notices/' . $runId;
$when = static fn (mixed $value): string => date('j M Y, H:i', (int) strtotime((string) $value));
$money = static fn (mixed $value): string => number_format((float) $value, 2);
$days = static fn (mixed $value): string => rtrim(rtrim(number_format((float) $value, 1), '0'), '.');

$counts = ['draft' => 0, 'sent' => 0, 'confirmed' => 0, 'query' => 0];
$withoutScreenshot = 0;
foreach ($lines as $line) {
    $counts[(string) $line['status']]++;
    if ($line['screenshot_filename'] === null && (string) $line['status'] === 'draft') {
        $withoutScreenshot++;
    }
}
$cancelWarning = "This cancels the link already sent; you'll need to resend.";
$badge = [
    'draft' => 'badge-mute',
    'sent' => 'badge-warn',
    'confirmed' => 'badge-ok',
    'query' => 'badge-bad',
];
?>
<div class="flex flex-col gap-5">

    <?php if ($missing !== []) : ?>
        <section class="card border-brand-400 p-5" aria-labelledby="missing-heading">
            <h2 id="missing-heading" class="font-display text-sm font-semibold text-brand-900">
                Warning: <?= e(count($missing) === 1 ? '1 active employee is' : count($missing) . ' active employees are') ?> not in this month
            </h2>
            <p class="mt-1 text-xs text-slate-600">
                They get no notice this month. If that is a mistake, upload a file with just their rows under
                <a href="#add-employees" class="font-medium text-brand-900 underline">Add employees to this month</a>.
            </p>
            <ul class="mt-2 list-disc pl-5 text-sm text-slate-700">
                <?php foreach ($missing as $person) : ?>
                    <li>
                        <?= e($person['name']) ?> —
                        <?php if ($person['employee_code'] !== null && $person['employee_code'] !== '') : ?>
                            <span class="money"><?= e($person['employee_code']) ?></span>
                            · <a href="#add-employees" class="text-brand-900 underline">add with a file</a>
                        <?php else : ?>
                            no Employee ID set — set it on their profile first
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <section class="grid grid-cols-2 gap-3 sm:grid-cols-4" aria-label="Where this month's notices stand">
        <?php foreach (['confirmed' => 'Confirmed', 'query' => 'Query raised', 'sent' => 'Awaiting response', 'draft' => 'Not sent'] as $key => $label) : ?>
            <div class="card p-4">
                <p class="kpi-label"><?= e($label) ?></p>
                <p class="kpi-value money"><?= e((string) $counts[$key]) ?></p>
            </div>
        <?php endforeach; ?>
    </section>

    <section id="note" class="card p-5 sm:p-6" aria-labelledby="note-heading">
        <h2 id="note-heading" class="font-display text-sm font-semibold text-ink">Note for the month</h2>
        <?php if ($noteEditable) : ?>
            <form method="post" action="<?= e(url($base . '/note')) ?>" class="mt-3 flex flex-col gap-3">
                <?= csrf_field() ?>
                <label for="run-note" class="sr-only">Note for the month</label>
                <textarea id="run-note" name="note" rows="3" maxlength="5000"
                          class="<?= isset($errors['note'][0]) ? 'input-error' : 'input' ?>"
                          aria-describedby="run-note-help<?= isset($errors['note'][0]) ? ' run-note-error' : '' ?>"><?= e(old('note', (string) ($run['note'] ?? ''))) ?></textarea>
                <p id="run-note-help" class="help -mt-1">Shown to every employee. It can change until the first notice is emailed.</p>
                <?php if (isset($errors['note'][0])) : ?>
                    <p id="run-note-error" class="error"><?= e($errors['note'][0]) ?></p>
                <?php endif; ?>
                <div><button type="submit" class="btn-secondary">Save note</button></div>
            </form>
        <?php else : ?>
            <p class="mt-2 text-sm whitespace-pre-line text-slate-700"><?= e(($run['note'] ?? '') !== '' ? (string) $run['note'] : 'No note.') ?></p>
            <p class="help">Locked: notices for this month have been emailed.</p>
        <?php endif; ?>
    </section>

    <section class="card overflow-hidden" aria-labelledby="lines-heading">
        <div class="flex flex-wrap items-center gap-3 border-b border-slate-200 px-4 py-3 sm:px-5">
            <h2 id="lines-heading" class="flex-1 font-display text-sm font-semibold text-ink">Employees</h2>
            <?php if ($lines !== []) : ?>
                <a href="<?= e(url($base . '/lines/' . (int) $lines[0]['id'] . '/preview-email')) ?>" class="btn-secondary"
                   aria-label="<?= e('Preview email, as ' . (string) $lines[0]['name'] . ' would receive it') ?>">Preview email</a>
            <?php endif; ?>
            <?php if ($counts['draft'] > 0) : ?>
                <details class="relative">
                    <summary class="btn-primary cursor-pointer list-none [&::-webkit-details-marker]:hidden">Send to all not yet sent</summary>
                    <div class="mt-2 max-w-sm rounded-lg border border-slate-200 bg-slate-50 p-3">
                        <p class="text-xs text-slate-700">
                            Emails <?= e($counts['draft'] === 1 ? '1 employee' : $counts['draft'] . ' employees') ?> their private link.
                            <?php if ($withoutScreenshot > 0) : ?>
                                <?= e($withoutScreenshot === 1 ? '1 line has' : $withoutScreenshot . ' lines have') ?> no screenshot yet and will be skipped.
                            <?php endif; ?>
                        </p>
                        <form method="post" action="<?= e(url($base . '/send')) ?>" class="mt-2">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn-dark">Yes, send now</button>
                        </form>
                    </div>
                </details>
            <?php endif; ?>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full border-collapse">
                <caption class="sr-only">One salary notice per employee, by Employee ID</caption>
                <thead>
                    <tr>
                        <th scope="col" class="th">Employee</th>
                        <th scope="col" class="th text-right">Salary</th>
                        <th scope="col" class="th hidden text-right md:table-cell">Absent</th>
                        <th scope="col" class="th hidden text-right md:table-cell">Working days</th>
                        <th scope="col" class="th hidden lg:table-cell">Screenshot</th>
                        <th scope="col" class="th">Status</th>
                    </tr>
                </thead>
                <?php if ($lines === []) : ?>
                    <tbody>
                        <tr>
                            <td class="td" colspan="6">
                                <p class="py-10 text-center text-sm text-slate-600">
                                    This run has no lines. Add employees with a file <a href="#add-employees" class="text-brand-900 underline">below</a>.
                                </p>
                            </td>
                        </tr>
                    </tbody>
                <?php endif; ?>

                <?php foreach ($lines as $line) : ?>
                    <?php
                    $lineId = (int) $line['id'];
                    $status = PayrollLineStatus::from((string) $line['status']);
                    $lineBase = $base . '/lines/' . $lineId;
                    $screenshotUrl = url($lineBase . '/screenshot');
                    $hasScreenshot = $line['screenshot_filename'] !== null;
                    $disabled = (string) $line['user_status'] !== 'active';
                    $cancelsLink = $status->editCancelsLink();
                    $editErrors = $lineId === $editLineId
                        ? array_intersect_key($errors, ['salary' => 1, 'absent_days' => 1, 'working_days' => 1])
                        : [];
                    ?>
                    <tbody id="line-<?= e((string) $lineId) ?>" class="border-b-2 border-slate-200">
                        <tr>
                            <td class="td">
                                <p class="font-medium text-ink"><?= e((string) $line['name']) ?></p>
                                <p class="text-[11.5px] text-slate-500">
                                    Employee ID <span class="money"><?= e((string) $line['employee_code']) ?></span>
                                    <?php if ($disabled) : ?>
                                        · <span class="badge-mute">Disabled</span>
                                    <?php endif; ?>
                                </p>
                                <p class="text-[11.5px] text-slate-500 md:hidden">
                                    Absent <?= e($days($line['absent_days'])) ?> of <?= e($days($line['working_days'])) ?> working days
                                </p>
                            </td>
                            <td class="td money text-right whitespace-nowrap text-ink"><?= e($symbol) ?> <?= e($money($line['salary'])) ?></td>
                            <td class="td money hidden text-right md:table-cell"><?= e($days($line['absent_days'])) ?></td>
                            <td class="td money hidden text-right md:table-cell"><?= e($days($line['working_days'])) ?></td>
                            <td class="td hidden lg:table-cell">
                                <?php if ($hasScreenshot) : ?>
                                    <a href="<?= e($screenshotUrl) ?>" target="_blank" rel="noopener" class="inline-block rounded border border-slate-200 hover:border-slate-400">
                                        <img src="<?= e($screenshotUrl) ?>" alt="<?= e('Attendance screenshot for ' . (string) $line['name'] . ', opens full size') ?>"
                                             class="h-12 w-20 object-cover object-top" loading="lazy">
                                    </a>
                                <?php else : ?>
                                    <span class="badge-bad">Missing</span>
                                <?php endif; ?>
                            </td>
                            <td class="td">
                                <span class="<?= e($badge[$status->value]) ?>"><?= e($status->label()) ?></span>
                                <?php if ($line['emailed_at'] !== null) : ?>
                                    <p class="mt-1 text-[11.5px] text-slate-500">Emailed <?= e($when($line['emailed_at'])) ?></p>
                                <?php endif; ?>
                                <?php if ($status === PayrollLineStatus::Confirmed) : ?>
                                    <p class="mt-1 text-xs text-slate-700">
                                        Confirmed by “<?= e((string) $line['signed_name']) ?>” on <?= e($when($line['responded_at'])) ?>
                                    </p>
                                <?php elseif ($status === PayrollLineStatus::Query) : ?>
                                    <p class="mt-1 text-[11.5px] text-slate-500">Raised <?= e($when($line['responded_at'])) ?>:</p>
                                    <p class="text-xs whitespace-pre-line text-slate-700"><?= e((string) $line['query_text']) ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="td bg-slate-50" colspan="6">
                                <div class="flex flex-wrap items-start gap-3">
                                    <?php if ($hasScreenshot) : ?>
                                        <a href="<?= e($screenshotUrl) ?>" target="_blank" rel="noopener" class="btn-secondary lg:hidden">View screenshot</a>
                                    <?php endif; ?>
                                    <a href="<?= e(url($lineBase . '/preview-email')) ?>" class="btn-secondary"
                                       aria-label="<?= e('Preview email for ' . (string) $line['name']) ?>">Preview email</a>
                                    <a href="<?= e(url($lineBase . '/preview')) ?>" class="btn-secondary"
                                       aria-label="<?= e('Preview notice page for ' . (string) $line['name']) ?>">Preview notice page</a>

                                    <?php if ($cancelsLink) : ?>
                                        <details>
                                            <summary class="btn-secondary cursor-pointer list-none [&::-webkit-details-marker]:hidden">Replace screenshot</summary>
                                            <div class="mt-2 max-w-md rounded-lg border border-slate-200 bg-white p-3">
                                                <p class="mb-2 text-xs font-medium text-bad-text"><?= e($cancelWarning) ?></p>
                                    <?php endif; ?>
                                    <?php if ($status->canEdit()) : ?>
                                        <form method="post" action="<?= e(url($lineBase . '/screenshot')) ?>" enctype="multipart/form-data"
                                              class="flex flex-wrap items-center gap-2">
                                            <?= csrf_field() ?>
                                            <label for="shot-<?= e((string) $lineId) ?>" class="text-xs font-medium text-slate-700">
                                                <?= e($hasScreenshot ? 'Replace screenshot' : 'Attendance screenshot') ?>
                                            </label>
                                            <input id="shot-<?= e((string) $lineId) ?>" name="screenshot" type="file" required
                                                   accept="image/png,image/jpeg,image/webp" class="max-w-56 text-xs"
                                                   aria-describedby="shot-help-<?= e((string) $lineId) ?>">
                                            <button type="submit" class="btn-secondary">Upload</button>
                                            <span id="shot-help-<?= e((string) $lineId) ?>" class="text-[11.5px] text-slate-500">PNG, JPEG or WebP, up to <?= e((string) $maxUploadMb) ?> MB</span>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($cancelsLink) : ?>
                                            </div>
                                        </details>
                                    <?php endif; ?>

                                    <?php if ($status->canSend() && !$disabled) : ?>
                                        <?php if ($status === PayrollLineStatus::Draft) : ?>
                                            <form method="post" action="<?= e(url($lineBase . '/send')) ?>">
                                                <?= csrf_field() ?>
                                                <button type="submit" class="btn-dark" <?= $hasScreenshot ? '' : 'disabled aria-describedby="send-needs-' . e((string) $lineId) . '"' ?>>Send</button>
                                                <?php if (!$hasScreenshot) : ?>
                                                    <span id="send-needs-<?= e((string) $lineId) ?>" class="ml-1 text-[11.5px] text-slate-500">Attach the screenshot first.</span>
                                                <?php endif; ?>
                                            </form>
                                        <?php else : ?>
                                            <details>
                                                <summary class="btn-secondary cursor-pointer list-none [&::-webkit-details-marker]:hidden">Resend</summary>
                                                <div class="mt-2 max-w-sm rounded-lg border border-slate-200 bg-white p-3">
                                                    <p class="text-xs text-slate-700">
                                                        A new link is emailed and the previous one stops working.
                                                        <?php if ($status === PayrollLineStatus::Query) : ?>
                                                            Their query is cleared from this page (it stays in the audit log) and they can answer again.
                                                        <?php endif; ?>
                                                    </p>
                                                    <form method="post" action="<?= e(url($lineBase . '/send')) ?>" class="mt-2">
                                                        <?= csrf_field() ?>
                                                        <button type="submit" class="btn-dark">Yes, resend</button>
                                                    </form>
                                                </div>
                                            </details>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <?php if ($status->canEdit()) : ?>
                                        <details id="line-<?= e((string) $lineId) ?>-edit" <?= $editErrors !== [] ? 'open' : '' ?>>
                                            <summary class="btn-secondary cursor-pointer list-none [&::-webkit-details-marker]:hidden">Edit figures</summary>
                                            <form method="post" action="<?= e(url($lineBase)) ?>" class="mt-2 grid max-w-xl grid-cols-1 gap-3 rounded-lg border border-slate-200 bg-white p-3 sm:grid-cols-3">
                                                <?= csrf_field() ?>
                                                <?php if ($cancelsLink) : ?>
                                                    <p class="text-xs font-medium text-bad-text sm:col-span-3"><?= e($cancelWarning) ?></p>
                                                <?php endif; ?>
                                                <?php
                                                $figureFields = [
                                                    'salary' => ['Salary (final)', $line['salary']],
                                                    'absent_days' => ['Absent days', $days($line['absent_days'])],
                                                    'working_days' => ['Working days', $days($line['working_days'])],
                                                ];
                                                ?>
                                                <?php foreach ($figureFields as $name => [$label, $current]) : ?>
                                                    <?php $inputId = 'line-' . $lineId . '-' . $name; ?>
                                                    <div>
                                                        <label for="<?= e($inputId) ?>" class="label"><?= e($label) ?></label>
                                                        <input id="<?= e($inputId) ?>" name="<?= e($name) ?>" type="text" inputmode="decimal" required
                                                               value="<?= e($editErrors !== [] ? old($name, (string) $current) : (string) $current) ?>"
                                                               class="<?= isset($editErrors[$name][0]) ? 'input-error' : 'input' ?> money"
                                                               <?= isset($editErrors[$name][0]) ? 'aria-invalid="true" aria-describedby="' . e($inputId) . '-error"' : '' ?>>
                                                        <?php if (isset($editErrors[$name][0])) : ?>
                                                            <p id="<?= e($inputId) ?>-error" class="error"><?= e($editErrors[$name][0]) ?></p>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                                <div class="sm:col-span-3">
                                                    <button type="submit" class="btn-dark"><?= e($cancelsLink ? 'Save and cancel the link' : 'Save figures') ?></button>
                                                    <?php if ($status === PayrollLineStatus::Query) : ?>
                                                        <span class="ml-2 text-[11.5px] text-slate-500">Then resend so they see the corrected notice.</span>
                                                    <?php endif; ?>
                                                </div>
                                            </form>
                                        </details>
                                    <?php endif; ?>

                                    <?php if ($status === PayrollLineStatus::Confirmed) : ?>
                                        <p class="text-[11.5px] text-slate-500">Closed: the employee confirmed this notice.</p>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                <?php endforeach; ?>
            </table>
        </div>
    </section>

    <?php if ($sheetErrors !== []) : ?>
        <?php
        $sheetErrorsAction = 'added';
        require APP_PATH . '/Views/partials/payroll-sheet-errors.php';
        ?>
    <?php endif; ?>

    <section id="add-employees" class="card p-5 sm:p-6" aria-labelledby="add-employees-heading">
        <h2 id="add-employees-heading" class="font-display text-sm font-semibold text-ink">Add employees to this month</h2>
        <p class="mt-1 text-xs text-slate-600">
            For anyone missing from the first file. Their rows are added as "Not sent"; nobody is emailed and no
            existing row changes, even if other notices were already sent. Everyone in the file must be new to
            this month — to correct someone already listed, edit their row instead.
        </p>
        <form method="post" action="<?= e(url($base . '/lines')) ?>" enctype="multipart/form-data" class="mt-4 flex flex-col gap-3" novalidate>
            <?= csrf_field() ?>
            <div>
                <label for="add-sheet" class="label">Sheet with the employees to add (.xlsx or .csv)</label>
                <input id="add-sheet" name="sheet" type="file" required
                       accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv"
                       class="<?= isset($errors['sheet'][0]) ? 'input-error' : 'input' ?>"
                       aria-describedby="add-sheet-help<?= isset($errors['sheet'][0]) ? ' add-sheet-error' : '' ?>"
                       <?= isset($errors['sheet'][0]) ? 'aria-invalid="true"' : '' ?>>
                <p id="add-sheet-help" class="help">
                    Same format as the import: first row <span class="money">EmpID, Salary, Absent, WorkingDays</span>,
                    then one row per employee. Nothing is added unless every row is valid.
                </p>
                <?php if (isset($errors['sheet'][0])) : ?>
                    <p id="add-sheet-error" class="error"><?= e($errors['sheet'][0]) ?></p>
                <?php endif; ?>
            </div>
            <div><button type="submit" class="btn-primary">Upload and add</button></div>
        </form>
    </section>
</div>
