<?php

declare(strict_types=1);

/** @var string $safeMessage */
$safeMessage = $safeMessage ?? '';
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>You don't have access to this</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="grid h-full place-items-center bg-slate-50 p-6 text-slate-900">
    <div class="max-w-md text-center">
        <p class="text-sm font-semibold uppercase tracking-widest text-indigo-600">403</p>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight">You don't have access to this</h1>
        <p class="mt-3 text-slate-600">
            <?= $safeMessage !== '' ? e($safeMessage) : 'Your account is not permitted to view this page.' ?>
        </p>
        <a href="/" class="mt-6 inline-flex rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
            Back to home
        </a>
    </div>
</body>
</html>
