<?php

/**
 * Employee documents as a stacked list — the admin screen, the portal home
 * and the portal documents page all render the same rows. A list rather than
 * a table so each row reads at 375 px without sideways scrolling.
 *
 * Preview opens the PDF as a top-level page in a new tab: the app's CSP
 * (frame-ancestors 'none') forbids embedding it, deliberately.
 *
 * @var list<array<string,mixed>> $documents
 * @var bool                      $canDelete     admin screen only; the route is gated server-side regardless
 * @var string                    $emptyMessage
 */

declare(strict_types=1);

$canDelete = $canDelete ?? false;

$formatSize = static function (int $bytes): string {
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }

    return max(1, (int) round($bytes / 1024)) . ' KB';
};
?>
<?php if ($documents === []) : ?>
    <div class="px-5 py-10 text-center">
        <svg class="mx-auto h-8 w-8 text-slate-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M5 3h9l5 5v13H5z"></path><path d="M14 3v5h5"></path>
        </svg>
        <p class="mt-2 text-sm text-slate-600"><?= e($emptyMessage) ?></p>
    </div>
<?php else : ?>
    <ul class="divide-y divide-slate-100" role="list">
        <?php foreach ($documents as $document) : ?>
            <?php
            $id = (int) $document['id'];
            $title = (string) $document['title'];
            $description = (string) ($document['description'] ?? '');
            ?>
            <li class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-start">
                <div class="min-w-0 flex-1">
                    <h3 class="font-display text-sm font-semibold break-words text-ink"><?= e($title) ?></h3>
                    <?php if ($description !== '') : ?>
                        <p class="mt-1 text-[12.5px] break-words text-slate-600">
                            <?= nl2br(e($description)) /* nl2br only adds <br> to already-escaped text. */ ?>
                        </p>
                    <?php endif; ?>
                    <p class="mt-1.5 text-[11.5px] text-slate-500">
                        PDF &middot; <span class="money"><?= e($formatSize((int) $document['size'])) ?></span>
                        &middot; <?= e(date('j M Y', (int) strtotime((string) $document['created_at']))) ?>
                        <?php if (($document['uploaded_by_name'] ?? null) !== null) : ?>
                            &middot; by <?= e((string) $document['uploaded_by_name']) ?>
                        <?php endif; ?>
                    </p>
                </div>

                <div class="flex flex-wrap items-start gap-2">
                    <a href="<?= e(url('/employee-documents/' . $id . '/view')) ?>" target="_blank" rel="noopener"
                       class="btn-secondary" aria-label="<?= e('Preview ' . $title . ' (opens in new tab)') ?>">
                        Preview
                    </a>
                    <a href="<?= e(url('/employee-documents/' . $id . '/download')) ?>"
                       class="btn-secondary" aria-label="<?= e('Download ' . $title) ?>">
                        Download
                    </a>
                    <?php if ($canDelete) : ?>
                        <details class="group">
                            <summary class="btn-secondary cursor-pointer list-none text-bad-text [&::-webkit-details-marker]:hidden"
                                     aria-label="<?= e('Delete ' . $title) ?>">
                                Delete
                            </summary>
                            <div class="mt-2 w-64 max-w-full rounded-lg border border-bad-line bg-bad-bg p-3">
                                <p class="text-xs text-bad-text">
                                    Delete &ldquo;<?= e($title) ?>&rdquo;? Employees will no longer be able to open it. This cannot be undone.
                                </p>
                                <form method="post" action="<?= e(url('/employee-documents/' . $id . '/delete')) ?>" class="mt-2">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn-dark">Yes, delete</button>
                                </form>
                            </div>
                        </details>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
