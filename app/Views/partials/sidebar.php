<?php

/**
 * Sidebar navigation, per spec §20.1 and the approved mockups.
 *
 * Items are filtered by ability, but hiding a link is presentation only — the
 * route itself is gated by middleware. Never treat this as access control.
 *
 * @var string                   $nav      active key
 * @var array<string,mixed>|null $authUser
 */

declare(strict_types=1);

use App\Services\Access;
use App\Services\Settings;

$nav = $nav ?? '';
$authUser = $authUser ?? null;
$role = (string) ($authUser['role'] ?? '');

// 'ready' marks a route that exists today. The full structure from spec
// section 20.1 stays visible so the shape of the app is legible, but an
// unbuilt item is rendered disabled rather than as a link into a 404.
$admin = Access::canAdminister($role);

$groups = [
    'Overview' => [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => '/dashboard', 'icon' => 'grid', 'show' => true, 'ready' => true],
    ],
    'Transactions' => [
        ['key' => 'expenses', 'label' => 'Expenses', 'href' => '/expenses', 'icon' => 'receipt', 'show' => true, 'ready' => false],
        ['key' => 'income', 'label' => 'Income', 'href' => '/income', 'icon' => 'trend', 'show' => true, 'ready' => false],
        ['key' => 'transfers', 'label' => 'Transfers', 'href' => '/transfers', 'icon' => 'swap', 'show' => true, 'ready' => false],
        ['key' => 'ledger', 'label' => 'All Transactions', 'href' => '/transactions', 'icon' => 'list', 'show' => true, 'ready' => false],
    ],
    'Finance' => [
        ['key' => 'accounts', 'label' => 'Accounts', 'href' => '/accounts', 'icon' => 'card', 'show' => true, 'ready' => true],
        ['key' => 'customers', 'label' => 'Customers', 'href' => '/customers', 'icon' => 'users', 'show' => true, 'ready' => true],
        ['key' => 'budgets', 'label' => 'Budgets', 'href' => '/budgets', 'icon' => 'bars', 'show' => true, 'ready' => false],
    ],
    'Partners' => [
        ['key' => 'partners', 'label' => 'Partners & Profit', 'href' => '/partners', 'icon' => 'users', 'show' => true, 'ready' => true],
        ['key' => 'capital', 'label' => 'Capital Movements', 'href' => '/capital', 'icon' => 'coins', 'show' => true, 'ready' => false],
    ],
    'Reports' => [
        ['key' => 'reports', 'label' => 'Profit & Loss', 'href' => '/reports', 'icon' => 'doc', 'show' => true, 'ready' => false],
    ],
    'System' => [
        ['key' => 'categories', 'label' => 'Categories', 'href' => '/categories', 'icon' => 'list', 'show' => true, 'ready' => true],
        ['key' => 'users', 'label' => 'Users', 'href' => '/users', 'icon' => 'users', 'show' => $admin, 'ready' => false],
        ['key' => 'settings', 'label' => 'Settings', 'href' => '/settings', 'icon' => 'sliders', 'show' => $admin, 'ready' => true],
    ],
];

$initials = 'LH';
if ($authUser !== null && ($authUser['name'] ?? '') !== '') {
    $parts = preg_split('/\s+/', trim((string) $authUser['name'])) ?: [];
    $initials = strtoupper(substr((string) ($parts[0] ?? ''), 0, 1) . substr((string) ($parts[1] ?? ''), 0, 1));
    $initials = $initials === '' ? 'LH' : $initials;
}
?>
<nav aria-label="Main" class="flex w-62 shrink-0 flex-col gap-6 overflow-y-auto bg-ink px-4 py-5">

    <a href="<?= e(url('/dashboard')) ?>" class="flex items-center gap-2.5 px-2">
        <svg class="h-6.5 w-6.5 text-brand-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M4 19.5V5a2 2 0 0 1 2-2h11a2 2 0 0 1 2 2v14.5"></path>
            <path d="M4 19.5A1.5 1.5 0 0 0 5.5 21H19"></path>
            <path d="M8 13l3-3 2.5 2.5L17 8"></path>
        </svg>
        <span class="font-display text-base font-bold tracking-tight text-white">
            <?= e(Settings::string('company_name', 'LedgerHive')) ?>
        </span>
    </a>

    <?php foreach ($groups as $groupLabel => $items) : ?>
        <?php $visible = array_filter($items, static fn (array $i): bool => $i['show'] === true); ?>
        <?php if ($visible === []) : ?>
            <?php continue; ?>
        <?php endif; ?>
        <div class="flex flex-col gap-0.5">
            <div class="nav-group"><?= e($groupLabel) ?></div>
            <?php foreach ($visible as $item) : ?>
                <?php if ($item['ready'] === true) : ?>
                    <a href="<?= e(url($item['href'])) ?>"
                       class="<?= $nav === $item['key'] ? 'nav-link-active' : 'nav-link' ?>"
                       <?= $nav === $item['key'] ? 'aria-current="page"' : '' ?>>
                        <?php require APP_PATH . '/Views/partials/icon.php'; ?>
                        <?= e($item['label']) ?>
                    </a>
                <?php else : ?>
                    <span class="nav-link cursor-not-allowed text-slate-500 hover:bg-transparent hover:text-slate-500"
                          title="Not built yet">
                        <?php require APP_PATH . '/Views/partials/icon.php'; ?>
                        <span class="flex-1"><?= e($item['label']) ?></span>
                        <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-slate-600" aria-hidden="true"></span>
                        <span class="sr-only">(not available yet)</span>
                    </span>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>

    <div class="mt-auto flex items-center gap-2.5 border-t border-white/10 px-2 pt-3">
        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-500 font-display text-xs font-bold text-ink">
            <?= e($initials) ?>
        </div>
        <div class="min-w-0 flex-1">
            <p class="truncate text-xs font-medium text-white"><?= e((string) ($authUser['name'] ?? 'Signed out')) ?></p>
            <p class="text-[11px] text-slate-400"><?= e(ucfirst(str_replace('_', ' ', $role))) ?></p>
        </div>
        <form method="post" action="<?= e(url('/logout')) ?>">
            <?= csrf_field() ?>
            <button type="submit" aria-label="Sign out"
                    class="flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 hover:bg-white/5 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-400">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M15 17l5-5-5-5"></path><path d="M20 12H9"></path><path d="M12 4H6a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h6"></path>
                </svg>
            </button>
        </form>
    </div>
</nav>
