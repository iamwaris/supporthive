<?php

/**
 * Employee portal: every document published to this branch, searchable.
 *
 * @var list<array<string,mixed>> $documents
 * @var string|null               $term
 * @var int                       $total
 * @var int                       $page
 * @var int                       $pages
 * @var int                       $perPage
 */

declare(strict_types=1);

$pageUrl = static function (int $target) use ($term): string {
    $query = array_filter(
        ['q' => $term, 'page' => $target > 1 ? $target : null],
        static fn (mixed $v): bool => $v !== null && $v !== ''
    );

    return url('/portal/documents') . ($query === [] ? '' : '?' . http_build_query($query));
};
?>

<div class="flex flex-col gap-4">
    <form method="get" action="<?= e(url('/portal/documents')) ?>" class="card flex flex-col gap-3 p-4 sm:flex-row sm:items-end" role="search">
        <div class="flex-1">
            <label for="portal-doc-q" class="label">Search documents</label>
            <input id="portal-doc-q" name="q" type="search" maxlength="100" placeholder="Title or description"
                   value="<?= e((string) $term) ?>" class="input">
        </div>
        <div class="flex gap-2">
            <button type="submit" class="btn-dark">Search</button>
            <?php if ($term !== null) : ?>
                <a href="<?= e(url('/portal/documents')) ?>" class="btn-secondary">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <section class="card overflow-hidden" aria-label="Documents">
        <?php
        $canDelete = false;
        $emptyMessage = $term !== null
            ? 'No documents match "' . $term . '".'
            : 'No documents have been shared with you yet.';
        require APP_PATH . '/Views/partials/employee-document-list.php';
        ?>

        <?php require APP_PATH . '/Views/partials/pagination.php'; ?>
    </section>
</div>
