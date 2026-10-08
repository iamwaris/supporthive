<?php

/**
 * An employee's profile as a definition list. Read-only: shown to the admin
 * on the employee's page and to the employee on their own portal home.
 *
 * @var array<string,mixed> $profile  EmployeeProfile::findWithUser() row
 */

declare(strict_types=1);

$isActive = (string) $profile['status'] === 'active';
$joined = $profile['joining_date'] !== null
    ? date('j M Y', (int) strtotime((string) $profile['joining_date']))
    : null;

$rows = [
    'Name' => (string) $profile['name'],
    'Email' => (string) $profile['email'],
    'Phone' => $profile['phone'] !== null && $profile['phone'] !== '' ? (string) $profile['phone'] : null,
    'Designation' => (string) $profile['designation'],
    'Branch' => (string) $profile['branch_name'],
    'Joining date' => $joined,
];
?>
<dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
    <?php foreach ($rows as $label => $text) : ?>
        <div class="min-w-0">
            <dt class="text-[11px] font-semibold uppercase tracking-wider text-slate-500"><?= e($label) ?></dt>
            <dd class="mt-1 text-sm break-words <?= $text === null ? 'text-slate-400' : 'text-ink' ?>">
                <?= e($text ?? 'Not set') ?>
            </dd>
        </div>
    <?php endforeach; ?>
    <div>
        <dt class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Status</dt>
        <dd class="mt-1">
            <span class="<?= $isActive ? 'badge-ok' : 'badge-bad' ?>"><?= e($isActive ? 'Active' : 'Deactivated') ?></span>
        </dd>
    </div>
</dl>
