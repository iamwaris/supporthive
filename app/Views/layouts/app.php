<?php

/**
 * Authenticated application shell.
 *
 * @var string                    $content
 * @var array<string,mixed>|null  $authUser
 * @var array<string,string|null> $flash
 * @var string|null               $title
 * @var string|null               $nav
 * @var string|null               $pageTitle
 * @var string|null               $pageMeta
 * @var string|null               $pageActions  pre-rendered, escaped markup
 * @var string|null               $pageScripts  pre-rendered <script> tags, for a page that needs a vendor library
 */

declare(strict_types=1);

use App\Services\Settings;

$company = Settings::string('company_name', 'LedgerHive');
$title = $title ?? $company;
$authUser = $authUser ?? null;
$flash = $flash ?? [];
$nav = $nav ?? '';
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <title><?= e($title) ?> &middot; <?= e($company) ?></title>
    <link rel="icon" href="<?= e(asset('assets/img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="preload" href="<?= e(asset('assets/fonts/ibm-plex-sans-latin-400-normal.woff2')) ?>" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
</head>
<body class="h-full bg-slate-50 font-sans text-ink antialiased">

<a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-white focus:px-4 focus:py-2 focus:shadow">
    Skip to content
</a>

<div class="flex h-full" x-data="{ mobileNav: false }">

    <!-- desktop navigation -->
    <div class="hidden md:flex">
        <?php require APP_PATH . '/Views/partials/sidebar.php'; ?>
    </div>

    <!-- mobile navigation: same markup, off-canvas -->
    <div x-show="mobileNav" x-cloak class="fixed inset-0 z-40 flex md:hidden">
        <div class="fixed inset-0 bg-ink/60" x-on:click="mobileNav = false" aria-hidden="true"></div>
        <div class="relative z-10 flex" x-on:keydown.escape.window="mobileNav = false">
            <?php require APP_PATH . '/Views/partials/sidebar.php'; ?>
        </div>
    </div>

    <div class="flex min-w-0 flex-1 flex-col">

        <header class="flex h-18 shrink-0 items-center gap-3 border-b border-slate-200 bg-white px-4 sm:px-7">
            <button type="button" class="-ml-1 flex h-10 w-10 items-center justify-center rounded-lg text-slate-600 hover:bg-slate-100 md:hidden"
                    x-on:click="mobileNav = true" x-bind:aria-expanded="mobileNav.toString()" aria-label="Open navigation">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" aria-hidden="true">
                    <path d="M4 6h16"></path><path d="M4 12h16"></path><path d="M4 18h16"></path>
                </svg>
            </button>

            <div class="min-w-0 flex-1">
                <h1 class="truncate font-display text-lg font-semibold tracking-tight text-ink">
                    <?= e($pageTitle ?? $title) ?>
                </h1>
                <?php if (!empty($pageMeta)) : ?>
                    <p class="mt-0.5 truncate text-xs text-slate-500"><?= e((string) $pageMeta) ?></p>
                <?php endif; ?>
            </div>

            <?php if (!empty($pageActions)) : ?>
                <?= $pageActions /* Rendered by the view; already escaped there. */ ?>
            <?php endif; ?>
        </header>

        <main id="main" class="min-h-0 flex-1 overflow-y-auto px-4 py-5 sm:px-7 sm:py-6">

            <?php
            $tones = [
                'success' => 'border-ok-line bg-ok-bg text-ok-text',
                'error'   => 'border-bad-line bg-bad-bg text-bad-text',
            ];
            ?>
            <?php foreach ($tones as $key => $tone) : ?>
                <?php if (!empty($flash[$key])) : ?>
                    <div role="status" x-data="{ show: true }" x-show="show"
                         class="mb-5 flex items-start gap-3 rounded-xl border px-4 py-3 text-sm <?= $tone ?>">
                        <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <?php if ($key === 'success') : ?>
                                <circle cx="12" cy="12" r="9"></circle><path d="M8.5 12.5l2.5 2.5 4.5-5"></path>
                            <?php else : ?>
                                <circle cx="12" cy="12" r="9"></circle><path d="M12 7.5v5"></path><path d="M12 16h.01"></path>
                            <?php endif; ?>
                        </svg>
                        <p class="flex-1"><?= e((string) $flash[$key]) ?></p>
                        <button type="button" x-on:click="show = false" aria-label="Dismiss" class="opacity-60 hover:opacity-100">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
                                <path d="M6 6l12 12"></path><path d="M18 6L6 18"></path>
                            </svg>
                        </button>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>

            <?= $content /* Already-rendered, already-escaped view output. */ ?>
        </main>
    </div>
</div>

<script src="<?= e(asset('assets/vendor/alpine.min.js')) ?>" defer></script>
<script src="<?= e(asset('assets/js/app.js')) ?>" defer></script>
<?php if (!empty($pageScripts)) : ?>
    <?= $pageScripts /* Rendered by the view; fixed vendor <script src> tags, not user input. */ ?>
<?php endif; ?>
</body>
</html>
