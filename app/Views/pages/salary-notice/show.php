<?php

/**
 * An employee's own salary notice, opened from the link in their email.
 * Shows only this line: their figures, their attendance screenshot and the
 * month's note. While a response is awaited it offers the two answers —
 * Confirm (typing their full name) or a query — as plain forms; afterwards
 * it is read-only. Says nothing about the organisation (public layout).
 *
 * The admin's "Preview notice page" (PAY-2) renders this same view with
 * $preview set: a banner says whose page it is, and the answer forms are
 * replaced by a note, so the preview holds no form that posts anywhere.
 *
 * @var array<string,mixed>        $line          PayrollRunLine::findByToken(), or a line plus its run's note
 * @var PayrollLineStatus          $status
 * @var string                     $monthLabel    "October 2026"
 * @var string                     $base          /salary-notice/{token}; unused in a preview
 * @var string|null                $screenshotUrl null when no screenshot is attached (preview only)
 * @var array{name:string,back:string,email:string}|null $preview
 * @var array<string,string|null>  $flash
 * @var array<string,list<string>> $errors
 */

declare(strict_types=1);

use App\Domain\PayrollLineStatus;

$errors = $errors ?? [];
$preview = $preview ?? null;
$when = static fn (mixed $value): string => date('j F Y, H:i', (int) strtotime((string) $value));
$days = static fn (mixed $value): string => rtrim(rtrim(number_format((float) $value, 1), '0'), '.');
$note = trim((string) ($line['note'] ?? ''));
$nameError = $errors['signed_name'][0] ?? null;
$queryError = $errors['query_text'][0] ?? null;
?>
<?php if ($preview !== null) : ?>
    <aside class="mb-6 rounded-xl border-2 border-dashed border-brand-400 bg-brand-50 px-4 py-3 text-sm text-ink" aria-labelledby="preview-heading">
        <p id="preview-heading" class="font-semibold">Preview — this is what <?= e($preview['name']) ?> will see</p>
        <p class="mt-1 text-xs text-slate-700">Nothing here is sent or saved, and the answer buttons are switched off.</p>
        <p class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs">
            <a href="<?= e(url($preview['back'])) ?>" class="font-medium text-brand-900 underline">Back to the month</a>
            <a href="<?= e(url($preview['email'])) ?>" class="font-medium text-brand-900 underline">Preview the email</a>
        </p>
    </aside>
<?php endif; ?>
<header class="mb-6">
    <p class="text-xs font-medium tracking-wide text-slate-500 uppercase">Salary notice</p>
    <h1 class="mt-1 font-display text-2xl font-semibold tracking-tight text-ink"><?= e($monthLabel) ?></h1>
    <p class="mt-1 text-sm text-slate-600">
        <?= e((string) $line['name']) ?> · Employee ID <span class="money"><?= e((string) $line['employee_code']) ?></span>
    </p>
</header>

<?php if (!empty($flash['success'])) : ?>
    <div role="status" aria-live="polite" class="mb-5 rounded-xl border border-ok-line bg-ok-bg px-4 py-3 text-sm text-ok-text">
        <?= e((string) $flash['success']) ?>
    </div>
<?php endif; ?>

<section class="card p-5 sm:p-6" aria-labelledby="figures-heading">
    <h2 id="figures-heading" class="sr-only">Your figures</h2>
    <dl class="grid grid-cols-2 gap-4">
        <div class="col-span-2">
            <dt class="kpi-label">Salary to be paid</dt>
            <dd class="kpi-value money"><?= e(number_format((float) $line['salary'], 2)) ?></dd>
        </div>
        <div>
            <dt class="kpi-label">Absent days</dt>
            <dd class="mt-1 money text-lg font-semibold text-ink"><?= e($days($line['absent_days'])) ?></dd>
        </div>
        <div>
            <dt class="kpi-label">Working days</dt>
            <dd class="mt-1 money text-lg font-semibold text-ink"><?= e($days($line['working_days'])) ?></dd>
        </div>
    </dl>

    <?php if ($note !== '') : ?>
        <div class="mt-5 border-t border-slate-200 pt-4">
            <h3 class="kpi-label">Note for <?= e($monthLabel) ?></h3>
            <p class="mt-1 text-sm whitespace-pre-line text-slate-700"><?= e($note) ?></p>
        </div>
    <?php endif; ?>
</section>

<section class="card mt-5 p-5 sm:p-6" aria-labelledby="attendance-heading">
    <h2 id="attendance-heading" class="font-display text-sm font-semibold text-ink">Your attendance record</h2>
    <?php if ($screenshotUrl !== null) : ?>
        <a href="<?= e(url($screenshotUrl)) ?>" target="_blank" rel="noopener" class="mt-3 block rounded-lg border border-slate-200 hover:border-slate-400">
            <img src="<?= e(url($screenshotUrl)) ?>" alt="<?= e('Your attendance record for ' . $monthLabel) ?>" class="w-full rounded-lg">
        </a>
        <p class="help">Opens full size in a new tab.</p>
    <?php else : ?>
        <p class="mt-3 rounded-lg border border-dashed border-slate-300 p-4 text-sm text-slate-700">
            No screenshot attached yet. This notice cannot be sent until one is.
        </p>
    <?php endif; ?>
</section>

<?php if ($preview !== null && ($status === PayrollLineStatus::Draft || $status === PayrollLineStatus::Sent)) : ?>
    <section class="card mt-5 border-dashed p-5 sm:p-6" aria-labelledby="preview-answer-heading">
        <h2 id="preview-answer-heading" class="font-display text-base font-semibold text-ink">Is everything correct?</h2>
        <p class="mt-1 text-sm text-slate-700">
            Here <?= e($preview['name']) ?> either confirms by typing their full name, or raises a query with a comment.
            They can answer only once per email.
        </p>
        <p class="mt-3 text-xs font-medium text-slate-600">Preview — answering is switched off.</p>
    </section>
<?php elseif ($status === PayrollLineStatus::Confirmed) : ?>
    <section class="card mt-5 p-5 sm:p-6" aria-labelledby="done-heading">
        <h2 id="done-heading" class="font-display text-base font-semibold text-ink">Thank you — confirmed</h2>
        <p class="mt-2 text-sm text-slate-700">
            You confirmed this notice on <?= e($when($line['responded_at'])) ?> as
            “<?= e((string) $line['signed_name']) ?>”. There is nothing more to do.
        </p>
    </section>
<?php elseif ($status === PayrollLineStatus::Query) : ?>
    <section class="card mt-5 p-5 sm:p-6" aria-labelledby="done-heading">
        <h2 id="done-heading" class="font-display text-base font-semibold text-ink">Thank you — your query was sent</h2>
        <p class="mt-2 text-sm text-slate-700">Sent on <?= e($when($line['responded_at'])) ?>. Someone will get back to you. You wrote:</p>
        <p class="mt-2 rounded-lg bg-slate-50 p-3 text-sm whitespace-pre-line text-slate-700"><?= e((string) $line['query_text']) ?></p>
    </section>
<?php elseif ($preview === null) : ?>
    <section id="confirm" class="card mt-5 p-5 sm:p-6" aria-labelledby="confirm-heading">
        <h2 id="confirm-heading" class="font-display text-base font-semibold text-ink">Is everything correct?</h2>
        <p class="mt-1 text-sm text-slate-600">Type your full name and press Confirm. You can answer only once.</p>
        <form method="post" action="<?= e(url($base . '/confirm')) ?>" class="mt-4 flex flex-col gap-3" novalidate>
            <?= csrf_field() ?>
            <div>
                <label for="notice-signed-name" class="label">Your full name</label>
                <input id="notice-signed-name" name="signed_name" type="text" required maxlength="120" autocomplete="name"
                       value="<?= e(old('signed_name')) ?>"
                       class="<?= $nameError !== null ? 'input-error' : 'input' ?>"
                       <?= $nameError !== null ? 'aria-invalid="true" aria-describedby="notice-signed-name-error"' : '' ?>>
                <?php if ($nameError !== null) : ?>
                    <p id="notice-signed-name-error" class="error"><?= e($nameError) ?></p>
                <?php endif; ?>
            </div>
            <div>
                <button type="submit" class="btn-primary w-full sm:w-auto">Confirm</button>
            </div>
        </form>
    </section>

    <section id="query" class="card mt-5 p-5 sm:p-6" aria-labelledby="query-heading">
        <h2 id="query-heading" class="font-display text-base font-semibold text-ink">Something not right?</h2>
        <p class="mt-1 text-sm text-slate-600">Tell us what looks wrong instead of confirming. This also counts as your answer.</p>
        <form method="post" action="<?= e(url($base . '/query')) ?>" class="mt-4 flex flex-col gap-3" novalidate>
            <?= csrf_field() ?>
            <div>
                <label for="notice-query" class="label">I have a query</label>
                <textarea id="notice-query" name="query_text" rows="4" required maxlength="2000"
                          class="<?= $queryError !== null ? 'input-error' : 'input' ?>"
                          <?= $queryError !== null ? 'aria-invalid="true" aria-describedby="notice-query-error"' : '' ?>><?= e(old('query_text')) ?></textarea>
                <?php if ($queryError !== null) : ?>
                    <p id="notice-query-error" class="error"><?= e($queryError) ?></p>
                <?php endif; ?>
            </div>
            <div>
                <button type="submit" class="btn-secondary w-full sm:w-auto">Submit query</button>
            </div>
        </form>
    </section>
<?php endif; ?>
