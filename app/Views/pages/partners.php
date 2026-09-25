<?php

/**
 * Partners and ownership.
 *
 * @var list<array<string,mixed>>          $partners
 * @var list<array<string,mixed>>          $activePartners
 * @var array<int,int>                     $currentSplit   partner id => basis points
 * @var int                                $currentTotalBp
 * @var bool                               $splitIsValid
 * @var list<array<string,mixed>>          $history
 * @var array<string,list<string>>         $errors
 * @var array<string,mixed>|null           $authUser
 */

declare(strict_types=1);

use App\Services\Access;
use App\Services\ShareService;

$errors = $errors ?? [];
$canEdit = Access::canManageMasterData((string) ($authUser['role'] ?? ''));
$hasSplit = $currentTotalBp > 0;
?>

<div class="grid gap-5 xl:grid-cols-[1fr_400px]">

    <!-- ================= ownership ================= -->
    <div class="card overflow-hidden">
        <div class="px-5 pt-4 pb-3">
            <h2 class="font-display text-sm font-semibold text-ink">Ownership</h2>
            <p class="mt-0.5 text-[11.5px] text-slate-500">
                Used for profit allocation only &mdash; never to split ordinary expenses
            </p>
        </div>

        <!-- the 100% gate, showing its actual state -->
        <?php if (!$hasSplit) : ?>
            <div class="mx-5 mb-4 flex items-center gap-2.5 rounded-lg border border-slate-200 bg-slate-50 px-3.5 py-2.5">
                <svg class="h-4 w-4 shrink-0 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="9"></circle><path d="M12 11v5"></path><path d="M12 8h.01"></path>
                </svg>
                <p class="flex-1 text-xs text-slate-600">
                    No ownership split yet. Profit distribution stays blocked until active shares total exactly 100%.
                </p>
            </div>
        <?php elseif ($splitIsValid) : ?>
            <div class="mx-5 mb-4 flex items-center gap-2.5 rounded-lg border border-ok-line bg-ok-bg px-3.5 py-2.5">
                <svg class="h-4 w-4 shrink-0 text-ok-text" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="9"></circle><path d="M8.5 12.5l2.5 2.5 4.5-5"></path>
                </svg>
                <p class="flex-1 text-xs text-ok-text">
                    Active shares total <strong class="font-semibold">100.0000%</strong>
                </p>
                <span class="money text-[11px] text-ok-text">validated on save</span>
            </div>
        <?php else : ?>
            <div class="mx-5 mb-4 flex items-start gap-2.5 rounded-lg border border-bad-line bg-bad-bg px-3.5 py-2.5">
                <svg class="mt-0.5 h-4 w-4 shrink-0 text-bad-text" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="9"></circle><path d="M12 7.5v5"></path><path d="M12 16h.01"></path>
                </svg>
                <div class="flex-1">
                    <p class="text-xs font-medium text-bad-strong">
                        Active shares total <?= e(ShareService::formatPercent($currentTotalBp)) ?>% &mdash; not 100%
                    </p>
                    <p class="mt-0.5 text-[11.5px] text-bad-text">
                        Profit distribution is blocked until this is corrected.
                    </p>
                </div>
            </div>
        <?php endif; ?>

        <div class="overflow-x-auto">
        <table class="w-full border-collapse">
            <thead>
                <tr>
                    <th scope="col" class="th">Partner</th>
                    <th scope="col" class="th">Joined</th>
                    <th scope="col" class="th text-right">Current share</th>
                    <th scope="col" class="th">Status</th>
                    <?php if ($canEdit) : ?>
                        <th scope="col" class="th"><span class="sr-only">Actions</span></th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody x-data="{ openId: null }">
                <?php if ($partners === []) : ?>
                    <tr>
                        <td class="td" colspan="<?= $canEdit ? 5 : 4 ?>">
                            <p class="py-6 text-center text-sm text-slate-500">
                                No partners yet.<?= $canEdit ? ' Add the first one on the right.' : '' ?>
                            </p>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($partners as $partner) : ?>
                    <?php
                    $id = (int) $partner['id'];
                    $shareBp = $currentSplit[$id] ?? null;
                    $isActive = (string) $partner['status'] === 'active';
                    $initials = strtoupper(mb_substr((string) $partner['name'], 0, 2));
                    ?>
                    <tr>
                        <td class="td">
                            <div class="flex items-center gap-2.5">
                                <span class="flex h-7.5 w-7.5 shrink-0 items-center justify-center rounded-lg font-display text-[11px] font-bold <?= $isActive ? 'bg-ink text-brand-400' : 'bg-slate-200 text-slate-500' ?>">
                                    <?= e($initials) ?>
                                </span>
                                <div class="min-w-0">
                                    <p class="truncate font-medium text-ink"><?= e((string) $partner['name']) ?></p>
                                    <?php if (!empty($partner['email'])) : ?>
                                        <p class="truncate text-[11px] text-slate-400"><?= e((string) $partner['email']) ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td class="td money text-slate-500">
                            <?= e(date('j M Y', (int) strtotime((string) $partner['join_date']))) ?>
                        </td>
                        <td class="td text-right">
                            <?php if ($shareBp !== null) : ?>
                                <span class="money text-sm font-semibold text-ink"><?= e(ShareService::formatPercent($shareBp)) ?>%</span>
                            <?php else : ?>
                                <span class="money text-slate-300">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td class="td">
                            <span class="<?= $isActive ? 'badge-ok' : 'badge-mute' ?>"><?= $isActive ? 'Active' : 'Inactive' ?></span>
                        </td>
                        <?php if ($canEdit) : ?>
                            <td class="td text-right">
                                <button type="button" x-on:click="openId = openId === <?= $id ?> ? null : <?= $id ?>"
                                        class="rounded-md px-2 py-1 text-[11.5px] font-medium text-brand-700 hover:bg-brand-50">
                                    Edit
                                </button>
                            </td>
                        <?php endif; ?>
                    </tr>

                    <?php if ($canEdit) : ?>
                        <tr x-show="openId === <?= $id ?>" x-cloak>
                            <td class="border-b border-slate-100 bg-slate-50 px-3.5 py-4" colspan="5">
                                <form method="post" action="<?= e(url('/partners/' . $id)) ?>" class="grid gap-3 sm:grid-cols-2">
                                    <?= csrf_field() ?>
                                    <div>
                                        <label for="name-<?= $id ?>" class="label">Name</label>
                                        <input id="name-<?= $id ?>" name="name" type="text" required maxlength="120"
                                               value="<?= e((string) $partner['name']) ?>" class="input">
                                    </div>
                                    <div>
                                        <label for="email-<?= $id ?>" class="label">Email</label>
                                        <input id="email-<?= $id ?>" name="email" type="email" maxlength="190"
                                               value="<?= e((string) ($partner['email'] ?? '')) ?>" class="input">
                                    </div>
                                    <div>
                                        <label for="phone-<?= $id ?>" class="label">Phone</label>
                                        <input id="phone-<?= $id ?>" name="phone" type="text" maxlength="40"
                                               value="<?= e((string) ($partner['phone'] ?? '')) ?>" class="input">
                                    </div>
                                    <div>
                                        <label for="join-<?= $id ?>" class="label">Join date</label>
                                        <input id="join-<?= $id ?>" name="join_date" type="date" required
                                               value="<?= e((string) $partner['join_date']) ?>" class="input">
                                    </div>
                                    <div>
                                        <label for="status-<?= $id ?>" class="label">Status</label>
                                        <select id="status-<?= $id ?>" name="status" class="input">
                                            <option value="active" <?= $isActive ? 'selected' : '' ?>>Active</option>
                                            <option value="inactive" <?= $isActive ? '' : 'selected' ?>>Inactive</option>
                                        </select>
                                        <?php if ($shareBp !== null) : ?>
                                            <p class="help">Holds a current share &mdash; activate a new split before deactivating.</p>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <label for="notes-<?= $id ?>" class="label">Notes</label>
                                        <input id="notes-<?= $id ?>" name="notes" type="text" maxlength="2000"
                                               value="<?= e((string) ($partner['notes'] ?? '')) ?>" class="input">
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

        <?php if ($history !== []) : ?>
            <div class="border-t border-slate-200 bg-slate-50 px-5 py-4">
                <h3 class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Ownership history</h3>
                <p class="mt-1 text-[11.5px] text-slate-500">
                    Kept so a distribution for an earlier period still uses the split that applied then.
                </p>
                <div class="mt-3 flex flex-col gap-2">
                    <?php foreach ($history as $split) : ?>
                        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 rounded-lg border border-slate-200 bg-white px-3 py-2">
                            <span class="money text-[11.5px] font-medium text-ink">
                                <?= e(date('j M Y', (int) strtotime((string) $split['effective_from']))) ?>
                            </span>
                            <span class="text-[11px] text-slate-400">
                                <?= $split['effective_to'] === null
                                        ? 'current'
                                        : 'to ' . e(date('j M Y', (int) strtotime((string) $split['effective_to']))) ?>
                            </span>
                            <span class="flex-1 text-[11.5px] text-slate-600">
                                <?php
                                $parts = [];
                                foreach ($split['rows'] as $row) {
                                    $parts[] = $row['partner_name'] . ' ' . ShareService::toPercent((int) $row['share_bp']) . '%';
                                }
                                echo e(implode(' · ', $parts));
                                ?>
                            </span>
                            <span class="money text-[11px] <?= (int) $split['total_bp'] === ShareService::TOTAL_BP ? 'text-ok-text' : 'text-bad-text' ?>">
                                <?= e(ShareService::toPercent((int) $split['total_bp'])) ?>%
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- ================= side forms ================= -->
    <?php if ($canEdit) : ?>
        <div class="flex flex-col gap-5">

            <!-- activate a split -->
            <div class="card p-5">
                <h2 class="font-display text-sm font-semibold text-ink">Activate a split</h2>
                <p class="mt-0.5 text-[11.5px] text-slate-500">
                    Leave a partner blank to exclude them. Must total exactly 100%.
                </p>

                <?php if ($activePartners === []) : ?>
                    <p class="mt-4 rounded-lg bg-slate-50 px-3 py-3 text-xs text-slate-500">
                        Add an active partner first.
                    </p>
                <?php else : ?>
                    <form method="post" action="<?= e(url('/partners/shares')) ?>" class="mt-4">
                        <?= csrf_field() ?>

                        <div class="flex flex-col gap-2.5">
                            <?php foreach ($activePartners as $partner) : ?>
                                <?php
                                $pid = (int) $partner['id'];
                                $fieldError = $errors['shares.' . $pid][0] ?? null;
                                $existing = $currentSplit[$pid] ?? null;
                                $oldShares = $oldShares ?? old_array('shares');
                                $oldValue = $oldShares[$pid] ?? null;
                                $value = $oldValue !== null
                                    ? (string) $oldValue
                                    : ($existing !== null ? ShareService::toPercent($existing) : '');
                                ?>
                                <div>
                                    <div class="flex items-center gap-2.5">
                                        <label for="share-<?= $pid ?>" class="flex-1 truncate text-xs text-slate-700">
                                            <?= e((string) $partner['name']) ?>
                                        </label>
                                        <div class="relative w-28">
                                            <input id="share-<?= $pid ?>" name="shares[<?= $pid ?>]" type="text"
                                                   inputmode="decimal" placeholder="0.0000"
                                                   value="<?= e($value) ?>"
                                                   class="money pr-7 text-right <?= $fieldError !== null ? 'input-error' : 'input' ?>">
                                            <span class="absolute right-3 top-0 flex h-10.5 items-center text-xs text-slate-400">%</span>
                                        </div>
                                    </div>
                                    <?php if ($fieldError !== null) : ?>
                                        <p class="error justify-end"><?= e($fieldError) ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="mt-4">
                            <label for="effective_from" class="label">Effective from</label>
                            <input id="effective_from" name="effective_from" type="date" required
                                   value="<?= e(old('effective_from', date('Y-m-d'))) ?>" class="input">
                            <p class="help">The previous split is closed the day before.</p>
                        </div>

                        <button type="submit" class="btn-primary mt-4 w-full">Activate split</button>
                    </form>
                <?php endif; ?>
            </div>

            <!-- add a partner -->
            <div class="card p-5" x-data="{ open: <?= isset($errors['name']) ? 'true' : 'false' ?> }">
                <button type="button" x-on:click="open = !open"
                        class="flex w-full items-center gap-2 text-left">
                    <span class="flex-1 font-display text-sm font-semibold text-ink">Add a partner</span>
                    <svg class="h-4 w-4 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"
                         x-bind:class="open ? 'rotate-45' : ''">
                        <path d="M12 5v14"></path><path d="M5 12h14"></path>
                    </svg>
                </button>

                <form method="post" action="<?= e(url('/partners')) ?>" class="mt-4 flex flex-col gap-3" x-show="open" x-cloak>
                    <?= csrf_field() ?>
                    <div>
                        <label for="new-name" class="label">Name</label>
                        <input id="new-name" name="name" type="text" required maxlength="120"
                               value="<?= e(old('name')) ?>"
                               class="<?= isset($errors['name']) ? 'input-error' : 'input' ?>">
                        <?php if (isset($errors['name'][0])) : ?>
                            <p class="error"><?= e($errors['name'][0]) ?></p>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label for="new-email" class="label">Email <span class="font-normal text-slate-400">optional</span></label>
                        <input id="new-email" name="email" type="email" maxlength="190" value="<?= e(old('email')) ?>" class="input">
                    </div>
                    <div>
                        <label for="new-phone" class="label">Phone <span class="font-normal text-slate-400">optional</span></label>
                        <input id="new-phone" name="phone" type="text" maxlength="40" value="<?= e(old('phone')) ?>" class="input">
                    </div>
                    <div>
                        <label for="new-join" class="label">Join date</label>
                        <input id="new-join" name="join_date" type="date" required
                               value="<?= e(old('join_date', date('Y-m-d'))) ?>" class="input">
                    </div>
                    <button type="submit" class="btn-dark">Add partner</button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>
