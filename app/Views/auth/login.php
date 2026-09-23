<?php

/**
 * @var array<string,list<string>> $errors
 */

declare(strict_types=1);

use App\Controllers\DevAuthController;
use App\Core\Config;

$errors = $errors ?? [];
$emailError = $errors['email'][0] ?? null;
$passwordError = $errors['password'][0] ?? null;
?>
<form method="post" action="<?= e(url('/login')) ?>" novalidate>
    <?= csrf_field() ?>

    <h1 class="font-display text-2xl font-semibold tracking-tight text-ink">Sign in</h1>
    <p class="mt-2 text-sm text-slate-500">Use the account your administrator created.</p>

    <div class="mt-7">
        <label for="email" class="label">Email</label>
        <input id="email" name="email" type="email" autocomplete="username" required
               value="<?= e(old('email')) ?>"
               class="<?= $emailError !== null ? 'input-error' : 'input' ?>"
               <?= $emailError !== null ? 'aria-invalid="true" aria-describedby="email-error"' : '' ?>>
        <?php if ($emailError !== null) : ?>
            <p id="email-error" class="error">
                <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="9"></circle><path d="M12 8v4.5"></path><path d="M12 16h.01"></path>
                </svg>
                <?= e($emailError) ?>
            </p>
        <?php endif; ?>
    </div>

    <div class="mt-4" x-data="{ show: false }">
        <label for="password" class="label">Password</label>
        <div class="relative">
            <input id="password" name="password" x-bind:type="show ? 'text' : 'password'" type="password"
                   autocomplete="current-password" required
                   class="<?= $passwordError !== null ? 'input-error' : 'input' ?> pr-11"
                   <?= $passwordError !== null ? 'aria-invalid="true" aria-describedby="password-error"' : '' ?>>
            <button type="button" x-on:click="show = !show"
                    x-bind:aria-label="show ? 'Hide password' : 'Show password'"
                    aria-label="Show password"
                    class="absolute right-1.5 top-1.5 flex h-7.5 w-8 items-center justify-center rounded-md text-slate-400 hover:text-slate-600">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M2 12s3.6-6.5 10-6.5S22 12 22 12s-3.6 6.5-10 6.5S2 12 2 12z"></path>
                    <circle cx="12" cy="12" r="2.6"></circle>
                </svg>
            </button>
        </div>
        <?php if ($passwordError !== null) : ?>
            <p id="password-error" class="error">
                <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="9"></circle><path d="M12 8v4.5"></path><path d="M12 16h.01"></path>
                </svg>
                <?= e($passwordError) ?>
            </p>
        <?php endif; ?>
    </div>

    <button type="submit" class="btn-primary mt-7 w-full">Sign in</button>

    <div class="mt-6 flex items-start gap-2.5 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
        <svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="4" y="10" width="16" height="10" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path>
        </svg>
        <p class="text-[11.5px] leading-relaxed text-slate-500">
            <?= e((string) Config::get('security.login_max_attempts', 5)) ?> failed attempts locks sign-in for
            <?= e((string) (int) round((int) Config::get('security.login_lockout', 900) / 60)) ?> minutes.
            Every attempt is recorded with its time and IP address.
        </p>
    </div>
</form>

    <?php $testAccounts = DevAuthController::testAccounts(); ?>
    <?php if ($testAccounts !== []) : ?>
        <!-- Development only. Rendered solely when APP_ENV is local, and the
             route it posts to is not registered outside local either. -->
        <div class="mt-6 rounded-xl border border-dashed border-brand-500/60 bg-brand-50 px-4 py-3.5">
            <div class="flex items-center gap-2">
                <svg class="h-3.5 w-3.5 shrink-0 text-brand-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M12 4l8 14H4z"></path><path d="M12 10v3"></path><path d="M12 16h.01"></path>
                </svg>
                <p class="flex-1 text-[11px] font-semibold uppercase tracking-wider text-brand-900">
                    Development shortcut
                </p>
                <span class="money text-[10.5px] text-brand-700"><?= e((string) Config::get('app.env')) ?></span>
            </div>
            <p class="mt-1.5 text-[11.5px] leading-relaxed text-brand-900/80">
                Signs in without a password. Inert in production &mdash; the route is not
                registered there, so it returns 404 even if this markup ships.
            </p>

            <div class="mt-3 flex flex-col gap-2">
                <?php foreach ($testAccounts as $account) : ?>
                    <form method="post" action="<?= e(url('/dev-login')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="user_id" value="<?= e((string) $account['id']) ?>">
                        <button type="submit"
                                class="flex min-h-11 w-full items-center gap-2.5 rounded-lg border border-brand-500/40 bg-white px-3 py-2 text-left hover:border-brand-500 hover:bg-brand-50/60">
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-ink font-display text-[10.5px] font-bold text-brand-400">
                                <?= e(strtoupper(mb_substr((string) $account['name'], 0, 2))) ?>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-xs font-medium text-ink"><?= e((string) $account['name']) ?></span>
                                <span class="block truncate text-[10.5px] text-slate-500"><?= e((string) $account['email']) ?></span>
                            </span>
                            <span class="badge-mute shrink-0"><?= e(ucfirst(str_replace('_', ' ', (string) $account['role']))) ?></span>
                        </button>
                    </form>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
