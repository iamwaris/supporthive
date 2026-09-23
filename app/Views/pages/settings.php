<?php

/**
 * @var array<string,mixed>        $settings
 * @var array<string,list<string>> $errors
 */

declare(strict_types=1);

$settings = $settings ?? [];
$errors = $errors ?? [];

/** Field value: flashed input wins over the stored value, so a failed save keeps what was typed. */
$value = static function (string $key) use ($settings): string {
    $old = old($key);
    return $old !== '' ? $old : (string) ($settings[$key] ?? '');
};

$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
];

$fiscalStart = (int) ($value('fiscal_year_start') ?: 7);
?>
<form method="post" action="<?= e(url('/settings')) ?>" class="max-w-2xl" novalidate>
    <?= csrf_field() ?>

    <div class="card p-6">
        <h2 class="font-display text-base font-semibold tracking-tight text-ink">Company</h2>
        <p class="mt-1 text-xs text-slate-500">Shown in the sidebar, page titles and on exports.</p>

        <div class="mt-5">
            <label for="company_name" class="label">Company name</label>
            <input id="company_name" name="company_name" type="text" required maxlength="120"
                   value="<?= e($value('company_name')) ?>"
                   class="<?= isset($errors['company_name']) ? 'input-error' : 'input' ?>"
                   <?= isset($errors['company_name']) ? 'aria-invalid="true" aria-describedby="company_name-error"' : '' ?>>
            <?php if (isset($errors['company_name'][0])) : ?>
                <p id="company_name-error" class="error"><?= e($errors['company_name'][0]) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mt-5 p-6">
        <h2 class="font-display text-base font-semibold tracking-tight text-ink">Currency</h2>
        <p class="mt-1 text-xs text-slate-500">
            Single currency in V1. Amounts are stored as exact decimals, never as
            floating point.
        </p>

        <div class="mt-5 grid gap-5 sm:grid-cols-2">
            <div>
                <label for="currency_code" class="label">Code</label>
                <input id="currency_code" name="currency_code" type="text" required maxlength="3"
                       value="<?= e($value('currency_code')) ?>"
                       class="<?= isset($errors['currency_code']) ? 'input-error' : 'input' ?> uppercase">
                <p class="help">Three letters, e.g. PKR</p>
                <?php if (isset($errors['currency_code'][0])) : ?>
                    <p class="error"><?= e($errors['currency_code'][0]) ?></p>
                <?php endif; ?>
            </div>

            <div>
                <label for="currency_symbol" class="label">Symbol</label>
                <input id="currency_symbol" name="currency_symbol" type="text" required maxlength="8"
                       value="<?= e($value('currency_symbol')) ?>"
                       class="<?= isset($errors['currency_symbol']) ? 'input-error' : 'input' ?>">
                <p class="help">Prefixed to amounts in the UI</p>
                <?php if (isset($errors['currency_symbol'][0])) : ?>
                    <p class="error"><?= e($errors['currency_symbol'][0]) ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card mt-5 p-6">
        <h2 class="font-display text-base font-semibold tracking-tight text-ink">Reporting &amp; alerts</h2>
        <p class="mt-1 text-xs text-slate-500">Affects how periods are grouped and when budget warnings appear.</p>

        <div class="mt-5 grid gap-5 sm:grid-cols-2">
            <div>
                <label for="fiscal_year_start" class="label">Fiscal year starts</label>
                <select id="fiscal_year_start" name="fiscal_year_start" class="input">
                    <?php foreach ($months as $number => $name) : ?>
                        <option value="<?= e((string) $number) ?>" <?= $fiscalStart === $number ? 'selected' : '' ?>>
                            <?= e($name) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['fiscal_year_start'][0])) : ?>
                    <p class="error"><?= e($errors['fiscal_year_start'][0]) ?></p>
                <?php endif; ?>
            </div>

            <div>
                <label for="budget_alert_pct" class="label">Warn when a budget reaches</label>
                <div class="relative">
                    <input id="budget_alert_pct" name="budget_alert_pct" type="number" min="1" max="100" required
                           value="<?= e($value('budget_alert_pct')) ?>"
                           class="<?= isset($errors['budget_alert_pct']) ? 'input-error' : 'input' ?> money pr-9">
                    <span class="absolute right-3 top-0 flex h-10.5 items-center text-sm text-slate-400">%</span>
                </div>
                <p class="help">Exceeding a budget always warns, regardless</p>
                <?php if (isset($errors['budget_alert_pct'][0])) : ?>
                    <p class="error"><?= e($errors['budget_alert_pct'][0]) ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="mt-5 flex items-center gap-3">
        <p class="flex-1 text-xs text-slate-500">Changes are recorded against your account.</p>
        <a href="<?= e(url('/dashboard')) ?>" class="btn-secondary">Cancel</a>
        <button type="submit" class="btn-primary">Save settings</button>
    </div>
</form>
