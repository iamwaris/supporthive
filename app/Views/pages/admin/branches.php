<?php

/**
 * Branch administration — super admin only.
 *
 * @var list<array<string,mixed>>  $branches
 * @var array<string,list<string>> $errors
 * @var array<string,mixed>|null   $authUser
 */

declare(strict_types=1);

$errors = $errors ?? [];
$money = static fn (mixed $v): string => number_format((float) $v, 0);
?>

<div class="grid gap-5 xl:grid-cols-[1fr_380px]">

    <div class="card overflow-hidden">
        <div class="px-5 pt-4 pb-3">
            <h2 class="font-display text-sm font-semibold text-ink">Branches</h2>
            <p class="mt-0.5 text-[11.5px] text-slate-500">
                Each branch has its own accounts, categories, partners and ledger — nothing is shared between them.
                Signed into directly by each branch's own admin login, not by switching in from here.
            </p>
        </div>

        <table class="w-full border-collapse">
            <thead>
                <tr>
                    <th scope="col" class="th">Branch</th>
                    <th scope="col" class="th">Users</th>
                    <th scope="col" class="th">Status</th>
                    <th scope="col" class="th"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody x-data="{ openId: null }">
                <?php if ($branches === []) : ?>
                    <tr>
                        <td class="td" colspan="4">
                            <p class="py-6 text-center text-sm text-slate-500">
                                No branches yet. Create the first one on the right.
                            </p>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($branches as $branch) : ?>
                    <?php
                    $id = (int) $branch['id'];
                    $isActive = (int) $branch['is_active'] === 1;
                    ?>
                    <tr>
                        <td class="td">
                            <p class="font-medium text-ink"><?= e((string) $branch['name']) ?></p>
                            <?php if (!empty($branch['created_by_name'])) : ?>
                                <p class="text-[11px] text-slate-400">Created by <?= e((string) $branch['created_by_name']) ?></p>
                            <?php endif; ?>
                        </td>
                        <td class="td money text-slate-600"><?= e($money($branch['user_count'])) ?></td>
                        <td class="td">
                            <span class="<?= $isActive ? 'badge-ok' : 'badge-bad' ?>"><?= $isActive ? 'Active' : 'Locked' ?></span>
                        </td>
                        <td class="td text-right">
                            <div class="flex items-center justify-end gap-2">
                                <form method="post" action="<?= e(url('/admin/branches/' . $id . '/toggle')) ?>" class="inline">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn-secondary">
                                        <?= $isActive ? 'Lock' : 'Unlock' ?>
                                    </button>
                                </form>
                                <button type="button" x-on:click="openId = openId === <?= $id ?> ? null : <?= $id ?>"
                                        class="rounded-md px-2 py-1 text-[11.5px] font-medium text-bad-text hover:bg-bad-bg">
                                    Delete
                                </button>
                            </div>
                        </td>
                    </tr>

                    <tr x-show="openId === <?= $id ?>" x-cloak>
                        <td class="border-b border-slate-100 bg-bad-bg px-3.5 py-4" colspan="4">
                            <form method="post" action="<?= e(url('/admin/branches/' . $id . '/delete')) ?>"
                                  class="flex flex-col gap-3">
                                <?= csrf_field() ?>
                                <p class="text-xs font-medium text-bad-text">
                                    This permanently deletes <?= e((string) $branch['name']) ?> and everything in
                                    it — every account, category, partner, transaction, budget and login. This
                                    cannot be undone.
                                </p>
                                <div class="flex flex-wrap items-end gap-3">
                                    <div class="min-w-56">
                                        <label for="confirm-name-<?= $id ?>" class="label">
                                            Type "<?= e((string) $branch['name']) ?>" to confirm
                                        </label>
                                        <input id="confirm-name-<?= $id ?>" name="confirm_name" type="text" required
                                               autocomplete="off" class="input">
                                    </div>
                                    <div class="min-w-56">
                                        <label for="confirm-password-<?= $id ?>" class="label">Your password</label>
                                        <input id="confirm-password-<?= $id ?>" name="confirm_password" type="password"
                                               required autocomplete="current-password" class="input">
                                    </div>
                                    <button type="submit" class="btn-primary bg-bad-text border-bad-text hover:bg-bad-text/90">
                                        Delete permanently
                                    </button>
                                    <button type="button" x-on:click="openId = null" class="btn-secondary">Cancel</button>
                                </div>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card p-5">
        <h2 class="font-display text-sm font-semibold text-ink">Create a branch</h2>
        <p class="mt-0.5 text-[11.5px] text-slate-500">
            Starts with the same starter category set every new branch gets, plus its own admin login —
            the credentials are shown once after creation.
        </p>

        <form method="post" action="<?= e(url('/admin/branches')) ?>" class="mt-4 flex flex-col gap-3">
            <?= csrf_field() ?>
            <div>
                <label for="new-b-name" class="label">Company / branch name</label>
                <input id="new-b-name" name="name" type="text" required maxlength="120"
                       value="<?= e(old('name')) ?>"
                       class="<?= isset($errors['name']) ? 'input-error' : 'input' ?>">
                <?php if (isset($errors['name'][0])) : ?>
                    <p class="error"><?= e($errors['name'][0]) ?></p>
                <?php endif; ?>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="new-b-currency-code" class="label">Currency code</label>
                    <input id="new-b-currency-code" name="currency_code" type="text" maxlength="10"
                           placeholder="PKR" value="<?= e(old('currency_code')) ?>" class="input">
                </div>
                <div>
                    <label for="new-b-currency-symbol" class="label">Symbol</label>
                    <input id="new-b-currency-symbol" name="currency_symbol" type="text" maxlength="10"
                           placeholder="Rs" value="<?= e(old('currency_symbol')) ?>" class="input">
                </div>
            </div>
            <div class="mt-2 border-t border-slate-100 pt-3">
                <p class="label mb-2">First admin login</p>
                <div class="flex flex-col gap-3">
                    <div>
                        <label for="new-b-admin-name" class="label">Name</label>
                        <input id="new-b-admin-name" name="admin_name" type="text" required maxlength="120"
                               value="<?= e(old('admin_name')) ?>"
                               class="<?= isset($errors['admin_name']) ? 'input-error' : 'input' ?>">
                        <?php if (isset($errors['admin_name'][0])) : ?>
                            <p class="error"><?= e($errors['admin_name'][0]) ?></p>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label for="new-b-admin-email" class="label">Email</label>
                        <input id="new-b-admin-email" name="admin_email" type="email" required maxlength="190"
                               value="<?= e(old('admin_email')) ?>"
                               class="<?= isset($errors['admin_email']) ? 'input-error' : 'input' ?>">
                        <?php if (isset($errors['admin_email'][0])) : ?>
                            <p class="error"><?= e($errors['admin_email'][0]) ?></p>
                        <?php endif; ?>
                        <p class="help">A one-time temporary password is generated and shown once after creation.</p>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn-dark">Create branch</button>
        </form>
    </div>
</div>
