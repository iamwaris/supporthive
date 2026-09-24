<?php

/**
 * @var list<array<string,mixed>>   $accounts
 * @var array<string,string>        $types
 * @var array<string,list<string>>  $errors
 * @var array<string,mixed>|null    $authUser
 */

declare(strict_types=1);

use App\Services\Access;
use App\Services\Settings;

$errors = $errors ?? [];
$canEdit = Access::canManageMasterData((string) ($authUser['role'] ?? ''));
$symbol = Settings::string('currency_symbol', 'Rs');
?>

<div class="grid gap-5 xl:grid-cols-[1fr_380px]">

    <div class="card overflow-hidden">
        <div class="px-5 pt-4 pb-3">
            <h2 class="font-display text-sm font-semibold text-ink">Accounts</h2>
            <p class="mt-0.5 text-[11.5px] text-slate-500">
                Opening balance is the starting point; the live balance is derived from the ledger
            </p>
        </div>

        <table class="w-full border-collapse">
            <thead>
                <tr>
                    <th scope="col" class="th">Account</th>
                    <th scope="col" class="th">Type</th>
                    <th scope="col" class="th text-right">Opening balance</th>
                    <th scope="col" class="th">From</th>
                    <th scope="col" class="th">Status</th>
                    <?php if ($canEdit) : ?>
                        <th scope="col" class="th"><span class="sr-only">Actions</span></th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody x-data="{ openId: null }">
                <?php if ($accounts === []) : ?>
                    <tr>
                        <td class="td" colspan="6">
                            <p class="py-6 text-center text-sm text-slate-500">
                                No accounts yet.<?= $canEdit ? ' Every transaction needs one, so add the first.' : '' ?>
                            </p>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($accounts as $account) : ?>
                    <?php
                    $id = (int) $account['id'];
                    $isActive = (int) $account['is_active'] === 1;
                    ?>
                    <tr>
                        <td class="td">
                            <p class="font-medium text-ink"><?= e((string) $account['name']) ?></p>
                            <?php if (!empty($account['institution'])) : ?>
                                <p class="text-[11px] text-slate-400"><?= e((string) $account['institution']) ?></p>
                            <?php endif; ?>
                        </td>
                        <td class="td text-slate-600"><?= e($types[(string) $account['type']] ?? (string) $account['type']) ?></td>
                        <td class="td money text-right font-medium text-ink">
                            <?= e($symbol) ?> <?= e(number_format((float) $account['opening_balance'], 2)) ?>
                        </td>
                        <td class="td money text-slate-500">
                            <?= e(date('j M Y', (int) strtotime((string) $account['opening_date']))) ?>
                        </td>
                        <td class="td">
                            <span class="<?= $isActive ? 'badge-ok' : 'badge-mute' ?>"><?= $isActive ? 'Active' : 'Closed' ?></span>
                        </td>
                        <?php if ($canEdit) : ?>
                            <td class="td text-right">
                                <button type="button" x-on:click="openId = openId === <?= $id ?> ? null : <?= $id ?>"
                                        class="rounded-md px-2 py-1 text-[11.5px] font-medium text-brand-700 hover:bg-brand-50">Edit</button>
                            </td>
                        <?php endif; ?>
                    </tr>

                    <?php if ($canEdit) : ?>
                        <tr x-show="openId === <?= $id ?>" x-cloak>
                            <td class="border-b border-slate-100 bg-slate-50 px-3.5 py-4" colspan="6">
                                <form method="post" action="<?= e(url('/accounts/' . $id)) ?>" class="grid gap-3 sm:grid-cols-2">
                                    <?= csrf_field() ?>
                                    <div>
                                        <label for="a-name-<?= $id ?>" class="label">Name</label>
                                        <input id="a-name-<?= $id ?>" name="name" type="text" required maxlength="120"
                                               value="<?= e((string) $account['name']) ?>" class="input">
                                    </div>
                                    <div>
                                        <label for="a-type-<?= $id ?>" class="label">Type</label>
                                        <select id="a-type-<?= $id ?>" name="type" class="input">
                                            <?php foreach ($types as $value => $label) : ?>
                                                <option value="<?= e($value) ?>" <?= (string) $account['type'] === $value ? 'selected' : '' ?>>
                                                    <?= e($label) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label for="a-bal-<?= $id ?>" class="label">Opening balance</label>
                                        <input id="a-bal-<?= $id ?>" name="opening_balance" type="text" inputmode="decimal" required
                                               value="<?= e((string) $account['opening_balance']) ?>" class="input money">
                                        <p class="help">Changing this shifts every derived balance.</p>
                                    </div>
                                    <div>
                                        <label for="a-date-<?= $id ?>" class="label">Opening date</label>
                                        <input id="a-date-<?= $id ?>" name="opening_date" type="date" required
                                               value="<?= e((string) $account['opening_date']) ?>" class="input">
                                    </div>
                                    <div>
                                        <label for="a-inst-<?= $id ?>" class="label">Institution</label>
                                        <input id="a-inst-<?= $id ?>" name="institution" type="text" maxlength="120"
                                               value="<?= e((string) ($account['institution'] ?? '')) ?>" class="input">
                                    </div>
                                    <div>
                                        <label for="a-ref-<?= $id ?>" class="label">Reference</label>
                                        <input id="a-ref-<?= $id ?>" name="reference" type="text" maxlength="80"
                                               value="<?= e((string) ($account['reference'] ?? '')) ?>" class="input">
                                    </div>
                                    <div>
                                        <label for="a-active-<?= $id ?>" class="label">Status</label>
                                        <select id="a-active-<?= $id ?>" name="is_active" class="input">
                                            <option value="1" <?= $isActive ? 'selected' : '' ?>>Active</option>
                                            <option value="0" <?= $isActive ? '' : 'selected' ?>>Closed</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label for="a-notes-<?= $id ?>" class="label">Notes</label>
                                        <input id="a-notes-<?= $id ?>" name="notes" type="text" maxlength="2000"
                                               value="<?= e((string) ($account['notes'] ?? '')) ?>" class="input">
                                    </div>
                                    <div class="flex items-center gap-2 sm:col-span-2">
                                        <button type="submit" class="btn-primary">Save changes</button>
                                        <button type="button" x-on:click="openId = null" class="btn-secondary">Cancel</button>
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
            <h2 class="font-display text-sm font-semibold text-ink">Add an account</h2>
            <p class="mt-0.5 text-[11.5px] text-slate-500">Cash, bank, card or petty cash</p>

            <form method="post" action="<?= e(url('/accounts')) ?>" class="mt-4 flex flex-col gap-3">
                <?= csrf_field() ?>
                <div>
                    <label for="new-a-name" class="label">Name</label>
                    <input id="new-a-name" name="name" type="text" required maxlength="120"
                           value="<?= e(old('name')) ?>"
                           class="<?= isset($errors['name']) ? 'input-error' : 'input' ?>">
                    <?php if (isset($errors['name'][0])) : ?>
                        <p class="error"><?= e($errors['name'][0]) ?></p>
                    <?php endif; ?>
                </div>
                <div>
                    <label for="new-a-type" class="label">Type</label>
                    <select id="new-a-type" name="type" class="input">
                        <?php foreach ($types as $value => $label) : ?>
                            <option value="<?= e($value) ?>" <?= old('type') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="new-a-bal" class="label">Opening balance (<?= e($symbol) ?>)</label>
                    <input id="new-a-bal" name="opening_balance" type="text" inputmode="decimal" required
                           value="<?= e(old('opening_balance', '0.00')) ?>"
                           class="money <?= isset($errors['opening_balance']) ? 'input-error' : 'input' ?>">
                    <?php if (isset($errors['opening_balance'][0])) : ?>
                        <p class="error"><?= e($errors['opening_balance'][0]) ?></p>
                    <?php endif; ?>
                </div>
                <div>
                    <label for="new-a-date" class="label">Balance as at</label>
                    <input id="new-a-date" name="opening_date" type="date" required
                           value="<?= e(old('opening_date', date('Y-m-d'))) ?>" class="input">
                </div>
                <div>
                    <label for="new-a-inst" class="label">Institution <span class="font-normal text-slate-400">optional</span></label>
                    <input id="new-a-inst" name="institution" type="text" maxlength="120"
                           value="<?= e(old('institution')) ?>" class="input">
                </div>
                <button type="submit" class="btn-dark">Add account</button>
            </form>
        </div>
    <?php endif; ?>
</div>
