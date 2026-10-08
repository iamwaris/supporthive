<?php

/**
 * Previous/next pager footer for a server-paginated card list, the same
 * shape as the audit log's. Plain links, so it works without JavaScript.
 *
 * @var int                   $page
 * @var int                   $pages
 * @var int                   $total
 * @var int                   $perPage
 * @var callable(int):string  $pageUrl  builds the URL for a page, keeping the list's filters
 */

declare(strict_types=1);

?>
<?php if ($total > 0) : ?>
    <nav aria-label="Pagination" class="flex flex-col gap-3 border-t border-slate-200 bg-slate-50 px-4 py-3 sm:flex-row sm:items-center">
        <p class="flex-1 text-[11.5px] text-slate-500">
            Showing <span class="money"><?= e((string) (($page - 1) * $perPage + 1)) ?></span>&ndash;<span
                class="money"><?= e((string) min($page * $perPage, $total)) ?></span>
            of <span class="money"><?= e(number_format($total)) ?></span>.
        </p>

        <div class="flex flex-wrap items-center gap-3">
            <?php if ($page > 1) : ?>
                <a href="<?= e($pageUrl($page - 1)) ?>" class="btn-secondary">Previous</a>
            <?php else : ?>
                <span class="btn-secondary pointer-events-none opacity-40" aria-disabled="true">Previous</span>
            <?php endif; ?>

            <span class="money text-xs text-slate-600">Page <?= e((string) $page) ?> of <?= e((string) $pages) ?></span>

            <?php if ($page < $pages) : ?>
                <a href="<?= e($pageUrl($page + 1)) ?>" class="btn-secondary">Next</a>
            <?php else : ?>
                <span class="btn-secondary pointer-events-none opacity-40" aria-disabled="true">Next</span>
            <?php endif; ?>
        </div>
    </nav>
<?php endif; ?>
