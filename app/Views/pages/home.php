<?php

declare(strict_types=1);

/** @var array<string,mixed>|null $authUser */
?>
<section class="rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
    <h1 class="text-3xl font-semibold tracking-tight">SupportHive</h1>
    <p class="mt-3 max-w-2xl text-slate-600">
        The scaffold is running. Routes live in <code class="rounded bg-slate-100 px-1.5 py-0.5 text-sm">routes/web.php</code>,
        controllers in <code class="rounded bg-slate-100 px-1.5 py-0.5 text-sm">app/Controllers</code>,
        and views in <code class="rounded bg-slate-100 px-1.5 py-0.5 text-sm">app/Views</code>.
    </p>

    <dl class="mt-8 grid gap-4 sm:grid-cols-3">
        <?php
        $checks = [
            'PHP'      => PHP_VERSION,
            'Env'      => (string) config('app.env'),
            'Debug'    => config('app.debug') ? 'on' : 'off',
        ];
        ?>
        <?php foreach ($checks as $label => $value) : ?>
            <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                <dt class="text-xs font-medium uppercase tracking-wide text-slate-500"><?= e($label) ?></dt>
                <dd class="mt-1 text-lg font-semibold tabular-nums"><?= e($value) ?></dd>
            </div>
        <?php endforeach; ?>
    </dl>
</section>
