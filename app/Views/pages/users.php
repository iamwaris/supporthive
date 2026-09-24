<?php

/**
 * User management — admin only, scoped to this branch.
 *
 * @var list<array<string,mixed>>  $users
 * @var list<string>               $roles
 * @var array<string,list<string>> $errors
 * @var array<string,mixed>|null   $authUser
 */

declare(strict_types=1);

$errors = $errors ?? [];
$myId = (int) ($authUser['id'] ?? 0);
$roleLabel = static fn (string $role): string => ucfirst(str_replace('_', ' ', $role));
?>

<div class="grid gap-5 xl:grid-cols-[1fr_340px]">

    <div class="card overflow-hidden">
        <div class="px-5 pt-4 pb-3">
            <h2 class="font-display text-sm font-semibold text-ink">Users in this branch</h2>
            <p class="mt-0.5 text-[11.5px] text-slate-500">
                A new login gets a one-time temporary password and must change it on first sign-in.
            </p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full border-collapse">
                <thead>
                    <tr>
                        <th scope="col" class="th">Name</th>
                        <th scope="col" class="th">Email</th>
                        <th scope="col" class="th">Role</th>
                        <th scope="col" class="th">Status</th>
                        <th scope="col" class="th"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody x-data="{ editId: null }">
                    <?php if ($users === []) : ?>
                        <tr>
                            <td class="td" colspan="5">
                                <p class="py-6 text-center text-sm text-slate-500">
                                    No users yet. Add the first one on the right.
                                </p>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($users as $user) : ?>
                        <?php
                        $id = (int) $user['id'];
                        $isSelf = $id === $myId;
                        $isActive = (string) $user['status'] === 'active';
                        ?>
                        <tr>
                            <td class="td">
                                <p class="font-medium text-ink">
                                    <?= e((string) $user['name']) ?>
                                    <?php if ($isSelf) : ?>
                                        <span class="text-[11px] font-normal text-slate-400">(you)</span>
                                    <?php endif; ?>
                                </p>
                                <?php if ((int) $user['must_change_password'] === 1) : ?>
                                    <p class="text-[11px] text-slate-400">Must change password on next sign-in</p>
                                <?php endif; ?>
                            </td>
                            <td class="td text-slate-600"><?= e((string) $user['email']) ?></td>
                            <td class="td text-slate-600"><?= e($roleLabel((string) $user['role'])) ?></td>
                            <td class="td">
                                <span class="<?= $isActive ? 'badge-ok' : 'badge-bad' ?>">
                                    <?= $isActive ? 'Active' : 'Suspended' ?>
                                </span>
                            </td>
                            <td class="td text-right whitespace-nowrap">
                                <div class="flex items-center justify-end gap-1.5">
                                    <button type="button"
                                            x-on:click="editId = editId === <?= $id ?> ? null : <?= $id ?>"
                                            class="rounded-md px-2 py-1 text-[11.5px] font-medium text-slate-600 hover:bg-slate-100">
                                        Edit
                                    </button>
                                    <?php if (!$isSelf) : ?>
                                        <form method="post" action="<?= e(url('/users/' . $id . '/toggle')) ?>" class="inline">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn-secondary">
                                                <?= $isActive ? 'Suspend' : 'Reactivate' ?>
                                            </button>
                                        </form>
                                        <form method="post" action="<?= e(url('/users/' . $id . '/reset-password')) ?>" class="inline">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn-secondary">Reset password</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>

                        <tr x-show="editId === <?= $id ?>" x-cloak>
                            <td class="border-b border-slate-100 bg-slate-50 px-3.5 py-4" colspan="5">
                                <form method="post" action="<?= e(url('/users/' . $id)) ?>"
                                      class="flex flex-wrap items-end gap-3">
                                    <?= csrf_field() ?>
                                    <div class="min-w-48">
                                        <label for="name-<?= $id ?>" class="label">Name</label>
                                        <input id="name-<?= $id ?>" name="name" type="text" required maxlength="120"
                                               value="<?= e((string) $user['name']) ?>" class="input">
                                    </div>
                                    <div class="min-w-40">
                                        <label for="role-<?= $id ?>" class="label">Role</label>
                                        <select id="role-<?= $id ?>" name="role" <?= $isSelf ? 'disabled' : 'required' ?> class="input">
                                            <?php foreach ($roles as $role) : ?>
                                                <option value="<?= e($role) ?>" <?= (string) $user['role'] === $role ? 'selected' : '' ?>>
                                                    <?= e($roleLabel($role)) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if ($isSelf) : ?>
                                            <input type="hidden" name="role" value="<?= e((string) $user['role']) ?>">
                                            <p class="help">You cannot change your own role.</p>
                                        <?php endif; ?>
                                    </div>
                                    <button type="submit" class="btn-primary">Save</button>
                                    <button type="button" x-on:click="editId = null" class="btn-secondary">Cancel</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card p-5">
        <h2 class="font-display text-sm font-semibold text-ink">Add a user</h2>
        <p class="mt-0.5 text-[11.5px] text-slate-500">
            A one-time temporary password is generated and shown once after creation.
        </p>

        <form method="post" action="<?= e(url('/users')) ?>" class="mt-4 flex flex-col gap-3">
            <?= csrf_field() ?>
            <div>
                <label for="new-u-name" class="label">Name</label>
                <input id="new-u-name" name="name" type="text" required maxlength="120"
                       value="<?= e(old('name')) ?>"
                       class="<?= isset($errors['name']) ? 'input-error' : 'input' ?>">
                <?php if (isset($errors['name'][0])) : ?>
                    <p class="error"><?= e($errors['name'][0]) ?></p>
                <?php endif; ?>
            </div>
            <div>
                <label for="new-u-email" class="label">Email</label>
                <input id="new-u-email" name="email" type="email" required maxlength="190"
                       value="<?= e(old('email')) ?>"
                       class="<?= isset($errors['email']) ? 'input-error' : 'input' ?>">
                <?php if (isset($errors['email'][0])) : ?>
                    <p class="error"><?= e($errors['email'][0]) ?></p>
                <?php endif; ?>
            </div>
            <div>
                <label for="new-u-role" class="label">Role</label>
                <select id="new-u-role" name="role" required class="input">
                    <?php foreach ($roles as $role) : ?>
                        <option value="<?= e($role) ?>" <?= old('role') === $role ? 'selected' : '' ?>>
                            <?= e($roleLabel($role)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn-dark">Add user</button>
        </form>
    </div>
</div>
