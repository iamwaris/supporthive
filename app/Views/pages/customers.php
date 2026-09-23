<?php

/**
 * @var list<array<string,mixed>>  $customers
 * @var array<string,list<string>> $errors
 * @var array<string,mixed>|null   $authUser
 */

declare(strict_types=1);

use App\Services\Access;

$errors = $errors ?? [];
$canEdit = Access::canManageMasterData((string) ($authUser['role'] ?? ''));
?>

<div class="grid gap-5 xl:grid-cols-[1fr_360px]">

    <div class="card overflow-hidden">
        <div class="px-5 pt-4 pb-3">
            <h2 class="font-display text-sm font-semibold text-ink">Customers</h2>
            <p class="mt-0.5 text-[11.5px] text-slate-500">
                Selected when recording income. Suppliers are typed straight onto the expense instead.
            </p>
        </div>

        <table class="w-full border-collapse">
            <thead>
                <tr>
                    <th scope="col" class="th">Customer</th>
                    <th scope="col" class="th">Contact</th>
                    <th scope="col" class="th">Status</th>
                    <?php if ($canEdit) : ?>
                        <th scope="col" class="th"><span class="sr-only">Actions</span></th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if ($customers === []) : ?>
                    <tr>
                        <td class="td" colspan="4">
                            <p class="py-6 text-center text-sm text-slate-500">
                                No customers yet.<?= $canEdit ? ' Add them as invoices come in.' : '' ?>
                            </p>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($customers as $customer) : ?>
                    <?php
                    $id = (int) $customer['id'];
                    $isActive = (int) $customer['is_active'] === 1;
                    ?>
                    <tr x-data="{ editing: false }">
                        <td class="td">
                            <p class="font-medium text-ink"><?= e((string) $customer['name']) ?></p>
                            <?php if (!empty($customer['email'])) : ?>
                                <p class="text-[11px] text-slate-400"><?= e((string) $customer['email']) ?></p>
                            <?php endif; ?>
                        </td>
                        <td class="td text-slate-600">
                            <?= e((string) ($customer['contact_name'] ?? '')) ?>
                            <?php if (!empty($customer['phone'])) : ?>
                                <span class="money block text-[11px] text-slate-400"><?= e((string) $customer['phone']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="td">
                            <span class="<?= $isActive ? 'badge-ok' : 'badge-mute' ?>"><?= $isActive ? 'Active' : 'Inactive' ?></span>
                        </td>
                        <?php if ($canEdit) : ?>
                            <td class="td text-right">
                                <button type="button" x-on:click="editing = !editing"
                                        class="rounded-md px-2 py-1 text-[11.5px] font-medium text-brand-700 hover:bg-brand-50">Edit</button>
                            </td>
                        <?php endif; ?>
                    </tr>

                    <?php if ($canEdit) : ?>
                        <tr x-show="editing" x-cloak>
                            <td class="border-b border-slate-100 bg-slate-50 px-3.5 py-4" colspan="4">
                                <form method="post" action="<?= e(url('/customers/' . $id)) ?>" class="grid gap-3 sm:grid-cols-2">
                                    <?= csrf_field() ?>
                                    <div>
                                        <label for="cu-name-<?= $id ?>" class="label">Name</label>
                                        <input id="cu-name-<?= $id ?>" name="name" type="text" required maxlength="160"
                                               value="<?= e((string) $customer['name']) ?>" class="input">
                                    </div>
                                    <div>
                                        <label for="cu-contact-<?= $id ?>" class="label">Contact person</label>
                                        <input id="cu-contact-<?= $id ?>" name="contact_name" type="text" maxlength="120"
                                               value="<?= e((string) ($customer['contact_name'] ?? '')) ?>" class="input">
                                    </div>
                                    <div>
                                        <label for="cu-email-<?= $id ?>" class="label">Email</label>
                                        <input id="cu-email-<?= $id ?>" name="email" type="email" maxlength="190"
                                               value="<?= e((string) ($customer['email'] ?? '')) ?>" class="input">
                                    </div>
                                    <div>
                                        <label for="cu-phone-<?= $id ?>" class="label">Phone</label>
                                        <input id="cu-phone-<?= $id ?>" name="phone" type="text" maxlength="40"
                                               value="<?= e((string) ($customer['phone'] ?? '')) ?>" class="input">
                                    </div>
                                    <div>
                                        <label for="cu-active-<?= $id ?>" class="label">Status</label>
                                        <select id="cu-active-<?= $id ?>" name="is_active" class="input">
                                            <option value="1" <?= $isActive ? 'selected' : '' ?>>Active</option>
                                            <option value="0" <?= $isActive ? '' : 'selected' ?>>Inactive</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label for="cu-notes-<?= $id ?>" class="label">Notes</label>
                                        <input id="cu-notes-<?= $id ?>" name="notes" type="text" maxlength="2000"
                                               value="<?= e((string) ($customer['notes'] ?? '')) ?>" class="input">
                                    </div>
                                    <div class="flex items-center gap-2 sm:col-span-2">
                                        <button type="submit" class="btn-primary">Save changes</button>
                                        <button type="button" x-on:click="editing = false" class="btn-secondary">Cancel</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($canEdit) : ?>
        <div class="card p-5">
            <h2 class="font-display text-sm font-semibold text-ink">Add a customer</h2>

            <form method="post" action="<?= e(url('/customers')) ?>" class="mt-4 flex flex-col gap-3">
                <?= csrf_field() ?>
                <div>
                    <label for="new-cu-name" class="label">Name</label>
                    <input id="new-cu-name" name="name" type="text" required maxlength="160"
                           value="<?= e(old('name')) ?>"
                           class="<?= isset($errors['name']) ? 'input-error' : 'input' ?>">
                    <?php if (isset($errors['name'][0])) : ?>
                        <p class="error"><?= e($errors['name'][0]) ?></p>
                    <?php endif; ?>
                </div>
                <div>
                    <label for="new-cu-contact" class="label">Contact person <span class="font-normal text-slate-400">optional</span></label>
                    <input id="new-cu-contact" name="contact_name" type="text" maxlength="120"
                           value="<?= e(old('contact_name')) ?>" class="input">
                </div>
                <div>
                    <label for="new-cu-email" class="label">Email <span class="font-normal text-slate-400">optional</span></label>
                    <input id="new-cu-email" name="email" type="email" maxlength="190"
                           value="<?= e(old('email')) ?>"
                           class="<?= isset($errors['email']) ? 'input-error' : 'input' ?>">
                    <?php if (isset($errors['email'][0])) : ?>
                        <p class="error"><?= e($errors['email'][0]) ?></p>
                    <?php endif; ?>
                </div>
                <div>
                    <label for="new-cu-phone" class="label">Phone <span class="font-normal text-slate-400">optional</span></label>
                    <input id="new-cu-phone" name="phone" type="text" maxlength="40"
                           value="<?= e(old('phone')) ?>" class="input">
                </div>
                <button type="submit" class="btn-dark">Add customer</button>
            </form>
        </div>
    <?php endif; ?>
</div>
