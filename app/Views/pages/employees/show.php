<?php

/**
 * One employee — admin only. Profile plus the account actions.
 *
 * @var array<string,mixed> $employee  EmployeeProfile::findWithUser() row
 */

declare(strict_types=1);

$id = (int) $employee['id'];
$isActive = (string) $employee['status'] === 'active';
$profile = $employee;
?>

<div class="flex flex-col gap-5">
    <p>
        <a href="<?= e(url('/employees')) ?>" class="text-xs font-medium text-slate-600 hover:text-ink hover:underline">&larr; All employees</a>
    </p>

    <div class="grid gap-5 lg:grid-cols-[1fr_320px]">
        <section class="card p-5 sm:p-6" aria-labelledby="profile-heading">
            <div class="mb-5 flex flex-wrap items-center gap-3">
                <h2 id="profile-heading" class="flex-1 font-display text-sm font-semibold text-ink">Profile</h2>
                <a href="<?= e(url('/employees/' . $id . '/edit')) ?>" class="btn-secondary">Edit</a>
            </div>
            <?php require APP_PATH . '/Views/partials/employee-profile.php'; ?>

            <?php if ((int) $employee['must_change_password'] === 1) : ?>
                <p class="help mt-5">Must change their password at next sign-in.</p>
            <?php endif; ?>
        </section>

        <section class="card flex flex-col gap-5 p-5" aria-labelledby="account-heading">
            <h2 id="account-heading" class="font-display text-sm font-semibold text-ink">Account</h2>

            <div>
                <p class="text-xs text-slate-600">
                    <?= e($isActive
                        ? 'Deactivating signs the employee out immediately and blocks sign-in until reactivated. Nothing is deleted.'
                        : 'This employee cannot sign in. Reactivate to restore access.') ?>
                </p>
                <form method="post" action="<?= e(url('/employees/' . $id . '/toggle')) ?>" class="mt-2">
                    <?= csrf_field() ?>
                    <button type="submit" class="<?= $isActive ? 'btn-secondary text-bad-text' : 'btn-dark' ?>">
                        <?= e($isActive ? 'Deactivate' : 'Reactivate') ?>
                    </button>
                </form>
            </div>

            <div class="border-t border-slate-200 pt-5">
                <p class="text-xs text-slate-600">
                    Issues a new one-time password, shown to you once. The employee must change it at next sign-in.
                </p>
                <details class="mt-2">
                    <summary class="btn-secondary cursor-pointer list-none [&::-webkit-details-marker]:hidden">Reset password</summary>
                    <div class="mt-2 rounded-lg border border-slate-200 bg-slate-50 p-3">
                        <p class="text-xs text-slate-700">Their current password stops working immediately.</p>
                        <form method="post" action="<?= e(url('/employees/' . $id . '/reset-password')) ?>" class="mt-2">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn-dark">Yes, reset password</button>
                        </form>
                    </div>
                </details>
            </div>
        </section>
    </div>
</div>
