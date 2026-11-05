<?php

/**
 * The confirm step of an "add or update" sheet (PAY-4), shown when applying
 * it would cancel a link already emailed. Nothing has changed yet: this
 * lists every change, old -> new, and Apply posts the held sheet's key.
 *
 * @var array<string,mixed>                                $run
 * @var \App\Services\Payroll\PayrollSheetUpdatePlan      $plan
 * @var string                                             $pendingKey
 * @var string                                             $symbol           currency symbol
 * @var int                                                $expiresInMinutes
 */

declare(strict_types=1);

$runId = (int) $run['id'];
$base = '/salary-notices/' . $runId;
$money = static fn (string $value): string => number_format((float) $value, 2);
$days = static fn (string $value): string => rtrim(rtrim(number_format((float) $value, 1), '0'), '.');
$cancelled = $plan->linksCancelled();

$changes = [];
foreach ($plan->updated as $change) {
    $changes[] = [
        'name' => $change['name'],
        'employee_code' => $change['employee_code'],
        'kind' => $change['status']->editCancelsLink() ? 'Update — link cancelled' : 'Update',
        'badge' => $change['status']->editCancelsLink() ? 'badge-bad' : 'badge-warn',
        'status' => $change['status']->label(),
        'before' => $change['before'],
        'after' => $change['after'],
    ];
}
foreach ($plan->added as $line) {
    $changes[] = [
        'name' => $line['name'],
        'employee_code' => $line['employee_code'],
        'kind' => 'New',
        'badge' => 'badge-ok',
        'status' => 'Not in this month yet',
        'before' => null,
        'after' => [
            'salary' => $line['salary'],
            'absent_days' => $line['absent_days'],
            'working_days' => $line['working_days'],
        ],
    ];
}
$counts = [
    'New' => count($plan->added),
    'Updated' => count($plan->updated),
    'Links cancelled' => $cancelled,
    'Unchanged' => $plan->unchanged,
];
$columns = [
    'salary' => ['label' => 'Salary (' . $symbol . ')', 'format' => $money],
    'absent_days' => ['label' => 'Absent', 'format' => $days],
    'working_days' => ['label' => 'Working days', 'format' => $days],
];
?>
<div class="flex max-w-4xl flex-col gap-5">

    <p class="text-sm">
        <a href="<?= e(url($base . '#add-employees')) ?>" class="font-medium text-brand-900 underline">Back to the month without applying</a>
    </p>

    <section class="card border-bad-line p-5 sm:p-6" aria-labelledby="sheet-update-heading">
        <h2 id="sheet-update-heading" class="font-display text-sm font-semibold text-bad-text">
            Warning: applying this file cancels <?= e($cancelled === 1 ? '1 link' : $cancelled . ' links') ?> already emailed
        </h2>
        <p class="mt-1 text-sm text-slate-700">
            Nothing has changed yet. Those employees' links stop working and their rows go back to "Not sent" with the
            new figures — send their notices again afterwards. Screenshots stay attached.
        </p>
        <dl class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <?php foreach ($counts as $label => $count) : ?>
                <div class="rounded-lg border border-slate-200 p-3">
                    <dt class="kpi-label"><?= e($label) ?></dt>
                    <dd class="kpi-value money"><?= e((string) $count) ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>
        <?php if ($plan->leftOut > 0) : ?>
            <p class="mt-3 text-xs text-slate-600">
                <?= e($plan->leftOut === 1
                    ? '1 employee in this month is not in the file and will be left as they are.'
                    : $plan->leftOut . ' employees in this month are not in the file and will be left as they are.') ?>
            </p>
        <?php endif; ?>
    </section>

    <section class="card overflow-hidden" aria-labelledby="sheet-changes-heading">
        <h2 id="sheet-changes-heading" class="border-b border-slate-200 px-4 py-3 font-display text-sm font-semibold text-ink sm:px-5">
            What will change
        </h2>
        <div class="overflow-x-auto">
            <table class="w-full border-collapse">
                <caption class="sr-only">Each employee the file adds or updates, with the figures before and after</caption>
                <thead>
                    <tr>
                        <th scope="col" class="th">Employee</th>
                        <th scope="col" class="th">Change</th>
                        <?php foreach ($columns as $column) : ?>
                            <th scope="col" class="th text-right"><?= e($column['label']) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($changes as $change) : ?>
                        <tr>
                            <td class="td">
                                <p class="font-medium text-ink"><?= e($change['name']) ?></p>
                                <p class="text-[11.5px] text-slate-500">
                                    Employee ID <span class="money"><?= e($change['employee_code']) ?></span> · <?= e($change['status']) ?>
                                </p>
                            </td>
                            <td class="td"><span class="<?= e($change['badge']) ?>"><?= e($change['kind']) ?></span></td>
                            <?php foreach ($columns as $field => $column) : ?>
                                <?php
                                $after = $column['format']($change['after'][$field]);
                                $before = $change['before'] === null ? null : $column['format']($change['before'][$field]);
                                ?>
                                <td class="td money text-right whitespace-nowrap">
                                    <?php if ($before === null || $before === $after) : ?>
                                        <?= e($after) ?>
                                    <?php else : ?>
                                        <span class="text-slate-500 line-through"><?= e($before) ?></span>
                                        <span aria-hidden="true">→</span><span class="sr-only">changes to</span>
                                        <strong class="font-semibold text-ink"><?= e($after) ?></strong>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <form method="post" action="<?= e(url($base . '/sheet-update')) ?>" class="flex flex-wrap items-center gap-3">
        <?= csrf_field() ?>
        <input type="hidden" name="pending_key" value="<?= e($pendingKey) ?>">
        <button type="submit" class="btn-dark">Apply these changes</button>
        <a href="<?= e(url($base . '#add-employees')) ?>" class="btn-secondary">Cancel</a>
        <p class="help w-full">
            Apply within <?= e((string) $expiresInMinutes) ?> minutes. If anything in this month changes before then,
            nothing is applied and you upload the file again.
        </p>
    </form>
</div>
