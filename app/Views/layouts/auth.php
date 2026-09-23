<?php

/**
 * Bare layout for unauthenticated screens — no navigation to offer.
 *
 * @var string                    $content
 * @var array<string,string|null> $flash
 * @var string|null               $title
 */

declare(strict_types=1);

use App\Services\Settings;

$company = Settings::string('company_name', 'LedgerHive');
$title = $title ?? 'Sign in';
$flash = $flash ?? [];
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <title><?= e($title) ?> &middot; <?= e($company) ?></title>
    <link rel="icon" href="<?= e(url('assets/img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
</head>
<body class="h-full bg-slate-50 font-sans text-ink antialiased">

<div class="flex min-h-full flex-col lg:flex-row">

    <!-- brand panel: decorative on mobile, so it collapses to a header -->
    <div class="flex shrink-0 flex-col bg-ink px-7 py-8 lg:w-[44%] lg:px-13 lg:py-14">
        <div class="flex items-center gap-3">
            <svg class="h-8 w-8 text-brand-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M4 19.5V5a2 2 0 0 1 2-2h11a2 2 0 0 1 2 2v14.5"></path>
                <path d="M4 19.5A1.5 1.5 0 0 0 5.5 21H19"></path>
                <path d="M8 13l3-3 2.5 2.5L17 8"></path>
            </svg>
            <span class="font-display text-xl font-bold tracking-tight text-white"><?= e($company) ?></span>
        </div>

        <div class="mt-10 lg:mt-auto">
            <p class="font-display text-2xl font-semibold leading-tight tracking-tight text-white lg:text-[2.6rem]">
                Know where<br class="hidden lg:inline">your money goes.
            </p>
            <p class="mt-4 max-w-md text-sm text-slate-400 lg:mt-5 lg:text-base">
                Expenses, revenue, partner ownership and profit &mdash; one ledger, reconciled
                by design rather than by spreadsheet.
            </p>
        </div>

        <div class="mt-8 hidden gap-9 lg:mt-auto lg:flex">
            <div>
                <p class="money font-display text-xl font-semibold text-brand-400">100%</p>
                <p class="mt-1 text-xs leading-relaxed text-slate-500">share validation<br>before any payout</p>
            </div>
            <div>
                <p class="money font-display text-xl font-semibold text-brand-400">0</p>
                <p class="mt-1 text-xs leading-relaxed text-slate-500">deleted records &mdash;<br>every void is audited</p>
            </div>
        </div>
    </div>

    <!-- form panel -->
    <div class="flex flex-1 items-center justify-center px-5 py-10 sm:px-8">
        <div class="w-full max-w-sm">

            <?php
            $tones = [
                'success' => 'border-ok-line bg-ok-bg text-ok-text',
                'error'   => 'border-bad-line bg-bad-bg text-bad-text',
            ];
            ?>
            <?php foreach ($tones as $key => $tone) : ?>
                <?php if (!empty($flash[$key])) : ?>
                    <div role="alert" class="mb-5 flex items-start gap-3 rounded-xl border px-4 py-3 text-sm <?= $tone ?>">
                        <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="12" cy="12" r="9"></circle><path d="M12 7.5v5"></path><path d="M12 16h.01"></path>
                        </svg>
                        <p class="flex-1"><?= e((string) $flash[$key]) ?></p>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>

            <?= $content /* Already-rendered, already-escaped view output. */ ?>
        </div>
    </div>
</div>
</body>
</html>
