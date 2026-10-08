<?php

/**
 * Employee portal home: the signed-in employee's own profile (read-only) and
 * the most recent documents published to their branch.
 *
 * @var array<string,mixed>|null  $profile
 * @var list<array<string,mixed>> $documents
 */

declare(strict_types=1);

?>

<div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">

    <section class="card p-5 sm:p-6" aria-labelledby="my-profile-heading">
        <h2 id="my-profile-heading" class="mb-5 font-display text-sm font-semibold text-ink">My profile</h2>

        <?php if ($profile === null) : ?>
            <p class="text-sm text-slate-600">
                Your profile has not been set up yet. Ask your administrator to complete it.
            </p>
        <?php else : ?>
            <?php require APP_PATH . '/Views/partials/employee-profile.php'; ?>
            <p class="help mt-5">
                Something wrong here? Ask your administrator to update it.
                <a href="<?= e(url('/account/password')) ?>" class="font-medium text-ink underline">Change your password</a>
            </p>
        <?php endif; ?>
    </section>

    <section class="card overflow-hidden" aria-labelledby="recent-docs-heading">
        <div class="flex items-center gap-3 px-5 pt-4 pb-3">
            <h2 id="recent-docs-heading" class="flex-1 font-display text-sm font-semibold text-ink">Recent documents</h2>
            <a href="<?= e(url('/portal/documents')) ?>" class="text-xs font-medium text-ink underline">All documents</a>
        </div>
        <div class="border-t border-slate-200">
            <?php
            $canDelete = false;
            $emptyMessage = 'No documents have been shared with you yet.';
            require APP_PATH . '/Views/partials/employee-document-list.php';
            ?>
        </div>
    </section>
</div>
