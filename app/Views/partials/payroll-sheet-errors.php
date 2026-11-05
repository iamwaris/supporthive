<?php

/**
 * The row-by-row problems of a rejected salary sheet, shared by the import
 * form and the run page's "add or update" upload.
 *
 * @var list<array{row:int,employee_code:string,message:string}> $sheetErrors
 * @var string                                                   $sheetErrorsAction "imported" or "changed"
 */

declare(strict_types=1);

?>
<section class="card border-bad-line p-5 sm:p-6" role="alert" aria-labelledby="sheet-errors-heading">
    <h2 id="sheet-errors-heading" class="font-display text-sm font-semibold text-bad-text">
        Nothing was <?= e($sheetErrorsAction) ?> — <?= e(count($sheetErrors) === 1 ? '1 problem' : count($sheetErrors) . ' problems') ?> in the file
    </h2>
    <p class="mt-1 text-xs text-slate-600">Correct these in the sheet, then choose the file again and upload it.</p>
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
                <?php foreach ($sheetErrors as $problem) : ?>
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
