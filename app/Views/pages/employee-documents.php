<?php

/**
 * Employee documents — admin only. Upload a PDF for every employee of this
 * branch, and manage what has been published.
 *
 * @var list<array<string,mixed>>  $documents
 * @var string|null                $term
 * @var int                        $total
 * @var int                        $page
 * @var int                        $pages
 * @var int                        $perPage
 * @var array<string,list<string>> $errors
 */

declare(strict_types=1);

use App\Core\Config;

$errors = $errors ?? [];
$maxMb = round((int) Config::get('uploads.pdf_max_bytes', 10485760) / 1048576, 1);
$maxLabel = rtrim(rtrim(number_format($maxMb, 1), '0'), '.') . ' MB';

$pageUrl = static function (int $target) use ($term): string {
    $query = array_filter(
        ['q' => $term, 'page' => $target > 1 ? $target : null],
        static fn (mixed $v): bool => $v !== null && $v !== ''
    );

    return url('/employee-documents') . ($query === [] ? '' : '?' . http_build_query($query));
};
?>

<div class="grid gap-5 xl:grid-cols-[1fr_360px]">

    <section class="card order-2 overflow-hidden xl:order-1" aria-labelledby="published-heading">
        <div class="flex flex-col gap-3 px-5 pt-4 pb-3 sm:flex-row sm:items-end">
            <div class="flex-1">
                <h2 id="published-heading" class="font-display text-sm font-semibold text-ink">Published documents</h2>
                <p class="mt-0.5 text-[11.5px] text-slate-500">
                    Every employee of this branch can preview and download these.
                </p>
            </div>
            <form method="get" action="<?= e(url('/employee-documents')) ?>" class="flex gap-2" role="search">
                <label for="doc-q" class="sr-only">Search documents</label>
                <input id="doc-q" name="q" type="search" maxlength="100" placeholder="Search title or description"
                       value="<?= e((string) $term) ?>" class="input sm:w-64">
                <button type="submit" class="btn-dark">Search</button>
            </form>
        </div>

        <div class="border-t border-slate-200">
            <?php
            $canDelete = true;
            $emptyMessage = $term !== null
                ? 'No documents match "' . $term . '".'
                : 'Nothing published yet. Upload the first PDF with the form.';
            require APP_PATH . '/Views/partials/employee-document-list.php';
            ?>
        </div>

        <?php require APP_PATH . '/Views/partials/pagination.php'; ?>
    </section>

    <section class="card order-1 p-5 xl:order-2" aria-labelledby="upload-heading">
        <h2 id="upload-heading" class="font-display text-sm font-semibold text-ink">Upload a document</h2>
        <p class="mt-0.5 text-[11.5px] text-slate-500">
            Visible to every employee of this branch as soon as it is uploaded.
        </p>

        <form method="post" action="<?= e(url('/employee-documents')) ?>" enctype="multipart/form-data"
              class="mt-4 flex flex-col gap-3" x-data="{ uploading: false }" x-on:submit="uploading = true">
            <?= csrf_field() ?>
            <div>
                <label for="doc-title" class="label">Title</label>
                <input id="doc-title" name="title" type="text" required maxlength="150"
                       value="<?= e(old('title')) ?>"
                       class="<?= isset($errors['title']) ? 'input-error' : 'input' ?>"
                       <?= isset($errors['title']) ? 'aria-invalid="true" aria-describedby="doc-title-error"' : '' ?>>
                <?php if (isset($errors['title'][0])) : ?>
                    <p id="doc-title-error" class="error"><?= e($errors['title'][0]) ?></p>
                <?php endif; ?>
            </div>
            <div>
                <label for="doc-description" class="label">Description <span class="font-normal text-slate-400">(optional)</span></label>
                <textarea id="doc-description" name="description" rows="3" maxlength="1000"
                          class="<?= isset($errors['description']) ? 'input-error' : 'input' ?> h-auto py-2"
                          <?= isset($errors['description']) ? 'aria-invalid="true" aria-describedby="doc-description-error"' : '' ?>><?= e(old('description')) ?></textarea>
                <?php if (isset($errors['description'][0])) : ?>
                    <p id="doc-description-error" class="error"><?= e($errors['description'][0]) ?></p>
                <?php endif; ?>
            </div>
            <div>
                <label for="doc-file" class="label">PDF file</label>
                <input id="doc-file" name="document" type="file" required accept="application/pdf,.pdf"
                       aria-describedby="doc-file-help<?= isset($errors['document']) ? ' doc-file-error' : '' ?>"
                       <?= isset($errors['document']) ? 'aria-invalid="true"' : '' ?>
                       class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border file:border-slate-300 file:bg-white file:px-3 file:py-2 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500">
                <p id="doc-file-help" class="help">PDF only, up to <?= e($maxLabel) ?>.</p>
                <?php if (isset($errors['document'][0])) : ?>
                    <p id="doc-file-error" class="error"><?= e($errors['document'][0]) ?></p>
                <?php endif; ?>
            </div>
            <button type="submit" class="btn-dark" x-bind:disabled="uploading">
                <span x-show="!uploading">Upload document</span>
                <span x-show="uploading" x-cloak>Uploading&hellip;</span>
            </button>
            <p class="sr-only" aria-live="polite" x-text="uploading ? 'Uploading document, please wait.' : ''"></p>
        </form>
    </section>
</div>
