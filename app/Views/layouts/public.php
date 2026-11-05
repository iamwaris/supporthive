<?php

/**
 * Bare layout for the public pages opened by a secret link: the new-joiner
 * form (/join/...) and an employee's salary notice (/salary-notice/...).
 * Deliberately says nothing about the organisation, the branch or the
 * application behind it: the page is reachable by anyone holding the link. No navigation and
 * no scripts of its own — the form works as plain HTML (the form view adds
 * one optional script, see pages/join/form.php).
 *
 * @var string                    $content
 * @var array<string,string|null> $flash
 * @var string|null               $title
 */

declare(strict_types=1);

$title = $title ?? 'Joining form';
$flash = $flash ?? [];
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="referrer" content="no-referrer">
    <title><?= e($title) ?></title>
    <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
</head>
<body class="min-h-full bg-slate-50 font-sans text-ink antialiased">
<main class="mx-auto w-full max-w-xl px-4 py-8 sm:px-6 sm:py-12">
    <?php if (!empty($flash['error'])) : ?>
        <div role="alert" class="mb-5 rounded-xl border border-bad-line bg-bad-bg px-4 py-3 text-sm text-bad-text">
            <?= e((string) $flash['error']) ?>
        </div>
    <?php endif; ?>

    <?= $content /* Already-rendered, already-escaped view output. */ ?>
</main>
</body>
</html>
