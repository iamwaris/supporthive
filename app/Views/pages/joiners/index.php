<?php

/**
 * New joiners — admin only, scoped to this branch: the branch's shared
 * joining link and the submissions that came in through it.
 *
 * @var array<string,mixed>|null   $link        EmployeeJoinLink::current()
 * @var string|null                $newLinkUrl  the full URL, only right after generating it
 * @var list<array<string,mixed>>  $submissions
 * @var array<int,string|null>     $bankMasks   submission id => masked account number or IBAN
 * @var string                     $status      pending | approved
 * @var int                        $total
 * @var int                        $page
 * @var int                        $pages
 * @var int                        $perPage
 */

declare(strict_types=1);

$tabs = ['pending' => 'Pending', 'approved' => 'Approved'];
$emptyText = [
    'pending' => 'No joiners waiting for review. Share the link above with new starters; their details appear here.',
    'approved' => 'No approved joiners yet.',
];
$linkActive = $link !== null && (int) $link['is_active'] === 1;
$when = static fn (mixed $value): string => date('j M Y, H:i', (int) strtotime((string) $value));

$pageUrl = static function (int $target) use ($status): string {
    $query = array_filter(
        ['status' => $status !== 'pending' ? $status : null, 'page' => $target > 1 ? $target : null],
        static fn (mixed $v): bool => $v !== null
    );

    return url('/joiners') . ($query === [] ? '' : '?' . http_build_query($query));
};
?>

<div class="flex flex-col gap-5">

    <section class="card p-5 sm:p-6" aria-labelledby="link-heading">
        <div class="flex flex-wrap items-center gap-3">
            <h2 id="link-heading" class="flex-1 font-display text-sm font-semibold text-ink">Joining link</h2>
            <?php if ($link === null) : ?>
                <span class="badge-mute">No link yet</span>
            <?php elseif ($linkActive) : ?>
                <span class="badge-ok">Active</span>
            <?php else : ?>
                <span class="badge-bad">Disabled</span>
            <?php endif; ?>
        </div>

        <p class="mt-2 text-xs text-slate-600">
            One link for this branch. Anyone who has it can send you their details; nobody gets an account until you
            approve them. Only a fingerprint of the link is stored, so its full address is shown once, when it is generated.
        </p>

        <?php if ($newLinkUrl !== null) : ?>
            <div class="mt-4 rounded-lg border border-ok-line bg-ok-bg p-4" x-data="{ copied: false }">
                <label for="join-link-url" class="label">Your new link — copy it now, it will not be shown again</label>
                <div class="flex flex-col gap-2 sm:flex-row">
                    <input id="join-link-url" type="text" readonly value="<?= e($newLinkUrl) ?>"
                           x-ref="url" class="input money flex-1" aria-describedby="join-link-copy-status">
                    <button type="button" class="btn-dark" x-cloak x-show="navigator.clipboard"
                            x-on:click="navigator.clipboard.writeText($refs.url.value).then(() => copied = true)">
                        Copy link
                    </button>
                </div>
                <p id="join-link-copy-status" class="help" aria-live="polite">
                    <span x-show="copied" x-cloak>Copied to the clipboard.</span>
                    <span x-show="!copied">Select the address and copy it if the button is not available.</span>
                </p>
            </div>
        <?php endif; ?>

        <?php if ($link !== null) : ?>
            <p class="mt-3 text-[11.5px] text-slate-500">
                Generated <?= e($when($link['generated_at'])) ?> by <?= e((string) ($link['generated_by_name'] ?? 'Unknown')) ?>.
            </p>
        <?php endif; ?>

        <div class="mt-4 flex flex-wrap items-start gap-2">
            <?php if ($link === null) : ?>
                <form method="post" action="<?= e(url('/joiners/link/regenerate')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn-primary">Generate link</button>
                </form>
            <?php else : ?>
                <form method="post" action="<?= e(url($linkActive ? '/joiners/link/disable' : '/joiners/link/enable')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="<?= $linkActive ? 'btn-secondary text-bad-text' : 'btn-dark' ?>">
                        <?= e($linkActive ? 'Disable link' : 'Enable link') ?>
                    </button>
                </form>
                <details>
                    <summary class="btn-secondary cursor-pointer list-none [&::-webkit-details-marker]:hidden">Regenerate link</summary>
                    <div class="mt-2 rounded-lg border border-slate-200 bg-slate-50 p-3">
                        <p class="text-xs text-slate-700">
                            The current link stops working immediately. Anyone you sent it to will need the new one.
                        </p>
                        <form method="post" action="<?= e(url('/joiners/link/regenerate')) ?>" class="mt-2">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn-dark">Yes, replace the link</button>
                        </form>
                    </div>
                </details>
            <?php endif; ?>
        </div>
    </section>

    <div class="card overflow-hidden">
        <nav aria-label="Joiner status" class="flex flex-wrap gap-1 border-b border-slate-200 px-4 pt-3">
            <?php foreach ($tabs as $value => $label) : ?>
                <?php $current = $status === $value; ?>
                <a href="<?= e(url('/joiners') . ($value === 'pending' ? '' : '?status=' . $value)) ?>"
                   <?= $current ? 'aria-current="page"' : '' ?>
                   class="<?= $current ? 'border-ink font-semibold text-ink' : 'border-transparent text-slate-600 hover:text-ink' ?> -mb-px border-b-2 px-3 pb-2 text-sm">
                    <?= e($label) ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="overflow-x-auto">
            <table class="w-full border-collapse">
                <caption class="sr-only"><?= e($tabs[$status]) ?> joiners in this branch, oldest first</caption>
                <thead>
                    <tr>
                        <th scope="col" class="th">Name</th>
                        <th scope="col" class="th hidden md:table-cell">Phone</th>
                        <th scope="col" class="th hidden sm:table-cell">Submitted</th>
                        <th scope="col" class="th hidden lg:table-cell">Bank account</th>
                        <th scope="col" class="th"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($submissions === []) : ?>
                        <tr>
                            <td class="td" colspan="5">
                                <p class="py-10 text-center text-sm text-slate-600"><?= e($emptyText[$status]) ?></p>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($submissions as $row) : ?>
                        <?php $id = (int) $row['id']; ?>
                        <tr>
                            <td class="td">
                                <a href="<?= e(url('/joiners/' . $id)) ?>" class="font-medium text-ink hover:underline">
                                    <?= e((string) $row['name']) ?>
                                </a>
                                <p class="text-[11.5px] break-all text-slate-500"><?= e((string) $row['email']) ?></p>
                                <p class="text-[11.5px] text-slate-500 sm:hidden">Submitted <?= e($when($row['created_at'])) ?></p>
                            </td>
                            <td class="td money hidden text-slate-600 md:table-cell"><?= e((string) ($row['phone'] ?? '—')) ?></td>
                            <td class="td hidden whitespace-nowrap text-slate-600 sm:table-cell"><?= e($when($row['created_at'])) ?></td>
                            <td class="td money hidden whitespace-nowrap text-slate-600 lg:table-cell"><?= e($bankMasks[$id] ?? '—') ?></td>
                            <td class="td text-right whitespace-nowrap">
                                <a href="<?= e(url('/joiners/' . $id)) ?>" class="btn-secondary"
                                   aria-label="<?= e(($status === 'pending' ? 'Review ' : 'View ') . (string) $row['name']) ?>">
                                    <?= e($status === 'pending' ? 'Review' : 'View') ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php require APP_PATH . '/Views/partials/pagination.php'; ?>
    </div>
</div>
