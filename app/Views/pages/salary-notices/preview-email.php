<?php

/**
 * The salary notice email for one line, exactly as PayrollNoticeSender
 * would build it, with a placeholder where the private link goes. Read-only:
 * no form, no token, nothing sent.
 *
 * @var array<string,mixed>                  $run
 * @var array<string,mixed>                  $line       PayrollRunLine::findInRun()
 * @var \App\Services\Mail\MailMessage|null  $message    null when no valid recipient or sender address
 * @var string|null                          $skipReason why a send would skip this line now, if it would
 */

declare(strict_types=1);

$runId = (int) $run['id'];
$lineBase = '/salary-notices/' . $runId . '/lines/' . (int) $line['id'];
?>
<div class="flex max-w-3xl flex-col gap-5">

    <p class="text-sm">
        <a href="<?= e(url('/salary-notices/' . $runId . '#line-' . (int) $line['id'])) ?>" class="font-medium text-brand-900 underline">Back to the month</a>
    </p>

    <?php if ($skipReason !== null) : ?>
        <section class="card border-bad-line p-5" role="status" aria-labelledby="preview-skip-heading">
            <h2 id="preview-skip-heading" class="font-display text-sm font-semibold text-bad-text">Warning: this notice would not be sent right now</h2>
            <p class="mt-1 text-sm text-slate-700">Reason: <?= e($skipReason) ?>.</p>
        </section>
    <?php endif; ?>

    <?php if ($message === null) : ?>
        <section class="card p-5 sm:p-6" aria-labelledby="preview-none-heading">
            <h2 id="preview-none-heading" class="font-display text-sm font-semibold text-ink">No email can be built for this line</h2>
            <p class="mt-1 text-sm text-slate-700">
                The employee's email address or the sender address (MAIL_FROM) is missing or not valid.
            </p>
        </section>
    <?php else : ?>
        <section class="card overflow-hidden" aria-labelledby="preview-email-heading">
            <h2 id="preview-email-heading" class="sr-only">The email</h2>
            <dl class="grid grid-cols-1 gap-x-4 gap-y-2 border-b border-slate-200 p-4 text-sm sm:grid-cols-[auto_1fr] sm:p-5">
                <dt class="font-medium text-slate-600">From</dt>
                <dd class="break-all text-ink"><?= e($message->fromName !== '' ? $message->fromName . ' <' . $message->fromAddress . '>' : $message->fromAddress) ?></dd>
                <dt class="font-medium text-slate-600">To</dt>
                <dd class="break-all text-ink"><?= e(implode(', ', $message->to)) ?></dd>
                <dt class="font-medium text-slate-600">Subject</dt>
                <dd class="text-ink"><?= e($message->subject) ?></dd>
            </dl>
            <pre class="overflow-x-auto p-4 font-mono text-sm whitespace-pre-wrap text-ink sm:p-5"><?= e($message->textBody) ?></pre>
        </section>
        <p class="help -mt-2">
            The link shown is a placeholder. Each employee's real, private link is created only when their notice is sent.
        </p>
    <?php endif; ?>

    <div class="flex flex-wrap gap-2">
        <a href="<?= e(url($lineBase . '/preview')) ?>" class="btn-secondary">Preview notice page</a>
    </div>
</div>
