<?php

/**
 * @var bool                      $forced
 * @var int                       $minLength
 * @var array<string,list<string>> $errors
 * @var array<string,mixed>|null  $authUser
 */

declare(strict_types=1);

$errors = $errors ?? [];
?>

<div class="mx-auto max-w-md">
    <div class="card p-6">
        <?php if ($forced) : ?>
            <p class="mb-4 flex items-start gap-2 rounded-lg bg-brand-50 px-3 py-3 text-xs text-brand-900">
                <svg class="mt-0.5 h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="9"></circle><path d="M12 11v5"></path><path d="M12 8h.01"></path>
                </svg>
                This account was created with a temporary password. Set your own before continuing.
            </p>
        <?php endif; ?>

        <form method="post" action="<?= e(url('/account/password')) ?>" class="flex flex-col gap-3">
            <?= csrf_field() ?>
            <div>
                <label for="current-password" class="label">Current password</label>
                <input id="current-password" name="current_password" type="password" required
                       class="<?= isset($errors['current_password']) ? 'input-error' : 'input' ?>">
                <?php if (isset($errors['current_password'][0])) : ?>
                    <p class="error"><?= e($errors['current_password'][0]) ?></p>
                <?php endif; ?>
            </div>
            <div>
                <label for="new-password" class="label">New password</label>
                <input id="new-password" name="password" type="password" required
                       class="<?= isset($errors['password']) ? 'input-error' : 'input' ?>">
                <?php if (isset($errors['password'][0])) : ?>
                    <p class="error"><?= e($errors['password'][0]) ?></p>
                <?php else : ?>
                    <p class="help">At least <?= e((string) $minLength) ?> characters.</p>
                <?php endif; ?>
            </div>
            <div>
                <label for="new-password-confirmation" class="label">Confirm new password</label>
                <input id="new-password-confirmation" name="password_confirmation" type="password" required
                       class="<?= isset($errors['password_confirmation']) ? 'input-error' : 'input' ?>">
                <?php if (isset($errors['password_confirmation'][0])) : ?>
                    <p class="error"><?= e($errors['password_confirmation'][0]) ?></p>
                <?php endif; ?>
            </div>
            <button type="submit" class="btn-dark">Change password</button>
        </form>
    </div>
</div>
