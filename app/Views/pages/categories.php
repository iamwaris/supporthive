<?php

/**
 * @var list<array{parent:array<string,mixed>,children:list<array<string,mixed>>}> $expenseTree
 * @var list<array{parent:array<string,mixed>,children:list<array<string,mixed>>}> $incomeTree
 * @var list<array<string,mixed>>  $expenseParents
 * @var list<array<string,mixed>>  $incomeParents
 * @var array<string,list<string>> $errors
 * @var array<string,mixed>|null   $authUser
 */

declare(strict_types=1);

use App\Services\Access;

$errors = $errors ?? [];
$canEdit = Access::canManageMasterData((string) ($authUser['role'] ?? ''));

$trees = [
    'expense' => ['label' => 'Expense categories', 'tree' => $expenseTree, 'parents' => $expenseParents],
    'income' => ['label' => 'Income categories', 'tree' => $incomeTree, 'parents' => $incomeParents],
];
?>

<div class="grid gap-5 xl:grid-cols-[1fr_360px]">

    <div class="flex flex-col gap-5">
        <?php foreach ($trees as $type => $group) : ?>
            <div class="card overflow-hidden">
                <div class="flex items-baseline gap-3 px-5 pt-4 pb-3">
                    <h2 class="flex-1 font-display text-sm font-semibold text-ink"><?= e($group['label']) ?></h2>
                    <span class="money text-[11px] text-slate-400"><?= count($group['tree']) ?> top level</span>
                </div>

                <?php if ($group['tree'] === []) : ?>
                    <p class="px-5 pb-5 text-sm text-slate-500">None yet.</p>
                <?php else : ?>
                    <div class="border-t border-slate-200">
                        <?php foreach ($group['tree'] as $node) : ?>
                            <?php
                            $parent = $node['parent'];
                            $parentId = (int) $parent['id'];
                            $parentActive = (int) $parent['is_active'] === 1;
                            ?>
                            <div class="border-b border-slate-100">
                                <div class="flex items-center gap-3 px-5 py-2.5">
                                    <span class="h-1.5 w-1.5 shrink-0 rounded-full <?= $parentActive ? 'bg-brand-500' : 'bg-slate-300' ?>" aria-hidden="true"></span>
                                    <span class="flex-1 text-[13px] <?= $parentActive ? 'font-medium text-ink' : 'text-slate-400' ?>">
                                        <?= e((string) $parent['name']) ?>
                                    </span>
                                    <?php if (!$parentActive) : ?>
                                        <span class="badge-mute">Hidden</span>
                                    <?php endif; ?>
                                    <?php if ($canEdit) : ?>
                                        <form method="post" action="<?= e(url('/categories/' . $parentId . '/toggle')) ?>">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="rounded-md px-2 py-1 text-[11.5px] font-medium text-slate-500 hover:bg-slate-100 hover:text-slate-700">
                                                <?= $parentActive ? 'Hide' : 'Restore' ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>

                                <?php foreach ($node['children'] as $child) : ?>
                                    <?php $childActive = (int) $child['is_active'] === 1; ?>
                                    <div class="flex items-center gap-3 border-t border-slate-50 py-2 pl-12 pr-5">
                                        <span class="flex-1 text-[12.5px] <?= $childActive ? 'text-slate-600' : 'text-slate-400' ?>">
                                            <?= e((string) $child['name']) ?>
                                        </span>
                                        <?php if (!$childActive) : ?>
                                            <span class="badge-mute">Hidden</span>
                                        <?php endif; ?>
                                        <?php if ($canEdit) : ?>
                                            <form method="post" action="<?= e(url('/categories/' . (int) $child['id'] . '/toggle')) ?>">
                                                <?= csrf_field() ?>
                                                <button type="submit" class="rounded-md px-2 py-1 text-[11.5px] font-medium text-slate-500 hover:bg-slate-100 hover:text-slate-700">
                                                    <?= $childActive ? 'Hide' : 'Restore' ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <p class="flex items-start gap-2 text-[11.5px] leading-relaxed text-slate-500">
            <svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                <circle cx="12" cy="12" r="9"></circle><path d="M12 11v5"></path><path d="M12 8h.01"></path>
            </svg>
            Categories are hidden rather than deleted. A past transaction keeps the category it was filed
            under, so historical reports do not change retroactively.
        </p>
    </div>

    <?php if ($canEdit) : ?>
        <div class="card p-5" x-data="{ type: '<?= e(old('type', 'expense')) ?>' }">
            <h2 class="font-display text-sm font-semibold text-ink">Add a category</h2>
            <p class="mt-0.5 text-[11.5px] text-slate-500">Or a subcategory under an existing one</p>

            <form method="post" action="<?= e(url('/categories')) ?>" class="mt-4 flex flex-col gap-3">
                <?= csrf_field() ?>

                <div>
                    <label for="c-type" class="label">Type</label>
                    <select id="c-type" name="type" class="input" x-model="type">
                        <option value="expense">Expense</option>
                        <option value="income">Income</option>
                    </select>
                    <p class="help">A category is only offered on forms of its own type.</p>
                </div>

                <div>
                    <label for="c-name" class="label">Name</label>
                    <input id="c-name" name="name" type="text" required maxlength="80"
                           value="<?= e(old('name')) ?>"
                           class="<?= isset($errors['name']) ? 'input-error' : 'input' ?>">
                    <?php if (isset($errors['name'][0])) : ?>
                        <p class="error"><?= e($errors['name'][0]) ?></p>
                    <?php endif; ?>
                </div>

                <!-- Two selects, one per type, so a parent of the wrong type can
                     never be submitted even if the type is switched. -->
                <div x-show="type === 'expense'">
                    <label for="c-parent-expense" class="label">Parent <span class="font-normal text-slate-400">optional</span></label>
                    <select id="c-parent-expense" name="parent_id" class="input" x-bind:disabled="type !== 'expense'">
                        <option value="">Top level</option>
                        <?php foreach ($expenseParents as $parent) : ?>
                            <option value="<?= e((string) $parent['id']) ?>"><?= e((string) $parent['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div x-show="type === 'income'" x-cloak>
                    <label for="c-parent-income" class="label">Parent <span class="font-normal text-slate-400">optional</span></label>
                    <select id="c-parent-income" name="parent_id" class="input" x-bind:disabled="type !== 'income'">
                        <option value="">Top level</option>
                        <?php foreach ($incomeParents as $parent) : ?>
                            <option value="<?= e((string) $parent['id']) ?>"><?= e((string) $parent['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="c-sort" class="label">Sort order <span class="font-normal text-slate-400">optional</span></label>
                    <input id="c-sort" name="sort_order" type="number" min="0" max="9999"
                           value="<?= e(old('sort_order')) ?>" placeholder="500" class="input money">
                    <p class="help">Lower appears first.</p>
                </div>

                <button type="submit" class="btn-dark">Add category</button>
            </form>
        </div>
    <?php endif; ?>
</div>
