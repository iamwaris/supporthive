<?php

/**
 * Base layout.
 *
 * @var string                    $content
 * @var array<string,mixed>|null  $authUser
 * @var array<string,string|null> $flash
 * @var string|null               $title
 */

declare(strict_types=1);

use App\Core\Config;

$title = $title ?? Config::get('app.name', 'SupportHive');
$authUser = $authUser ?? null;
$flash = $flash ?? [];
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <title><?= e($title) ?></title>
    <link rel="icon" href="<?= e(url('assets/img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
</head>
<body class="h-full bg-slate-50 text-slate-900 antialiased">

<a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-white focus:px-4 focus:py-2 focus:shadow">
    Skip to content
</a>

<div class="flex min-h-full flex-col">

    <header class="border-b border-slate-200 bg-white" x-data="{ open: false }">
        <div class="mx-auto flex w-full max-w-7xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
            <a href="<?= e(url('/')) ?>" class="flex items-center gap-2 font-semibold tracking-tight">
                <i data-lucide="life-buoy" class="h-6 w-6 text-indigo-600" aria-hidden="true"></i>
                <span><?= e((string) Config::get('app.name', 'SupportHive')) ?></span>
            </a>

            <button type="button"
                    class="inline-flex items-center rounded-md p-2 text-slate-600 hover:bg-slate-100 md:hidden"
                    x-on:click="open = !open"
                    x-bind:aria-expanded="open.toString()"
                    aria-controls="primary-nav"
                    aria-label="Toggle navigation">
                <i data-lucide="menu" class="h-5 w-5" aria-hidden="true"></i>
            </button>

            <nav id="primary-nav"
                 class="absolute inset-x-0 top-14 z-40 border-b border-slate-200 bg-white px-4 py-3 md:static md:flex md:items-center md:gap-1 md:border-0 md:px-0 md:py-0"
                 x-bind:class="open ? 'block' : 'hidden md:flex'">
                <?php if ($authUser !== null) : ?>
                    <a href="<?= e(url('/dashboard')) ?>" class="block rounded-md px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">Dashboard</a>
                    <form method="post" action="<?= e(url('/logout')) ?>" class="mt-2 md:mt-0 md:ml-2">
                        <?= csrf_field() ?>
                        <button type="submit" class="w-full rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 md:w-auto">
                            Sign out
                        </button>
                    </form>
                <?php else : ?>
                    <a href="<?= e(url('/login')) ?>" class="block rounded-md px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">Sign in</a>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <?php
    // Full class strings, never interpolated fragments: Tailwind's scanner only
    // sees classes that appear literally in the source.
    $flashTones = [
        'success' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
        'error'   => 'border-rose-200 bg-rose-50 text-rose-800',
    ];
    ?>
    <?php foreach ($flashTones as $key => $tone) : ?>
        <?php if (!empty($flash[$key])) : ?>
            <div role="status"
                 x-data="{ show: true }"
                 x-show="show"
                 class="mx-auto mt-4 w-full max-w-7xl px-4 sm:px-6">
                <div class="flex items-start gap-3 rounded-lg border px-4 py-3 text-sm <?= $tone ?>">
                    <i data-lucide="<?= $key === 'success' ? 'check-circle-2' : 'alert-circle' ?>" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true"></i>
                    <p class="flex-1"><?= e((string) $flash[$key]) ?></p>
                    <button type="button" x-on:click="show = false" class="text-current/60 hover:text-current" aria-label="Dismiss">
                        <i data-lucide="x" class="h-4 w-4" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>

    <main id="main" class="mx-auto w-full max-w-7xl flex-1 px-4 py-8 sm:px-6">
        <?= $content /* Already-rendered, already-escaped view output. */ ?>
    </main>

    <footer class="border-t border-slate-200 bg-white">
        <div class="mx-auto w-full max-w-7xl px-4 py-6 text-sm text-slate-500 sm:px-6">
            &copy; <?= date('Y') ?> <?= e((string) Config::get('app.name', 'SupportHive')) ?>
        </div>
    </footer>
</div>

<script src="<?= e(url('assets/vendor/alpine.min.js')) ?>" defer></script>
<script src="<?= e(url('assets/vendor/lucide.min.js')) ?>"></script>
<script src="<?= e(url('assets/js/app.js')) ?>" defer></script>
</body>
</html>
