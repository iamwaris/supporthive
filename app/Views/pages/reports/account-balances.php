<?php

/**
 * @var string|null $asOf
 * @var array{total:string,accounts:list<array<string,mixed>>} $report
 * @var array<string,mixed>|null $authUser
 */

declare(strict_types=1);

use App\Models\Account;
use App\Services\Settings;

$symbol = Settings::string('currency_symbol', 'Rs');
$money = static fn (mixed $v): string => number_format((float) $v, 2);
?>

<div class="flex flex-col gap-5">
    <form method="get" action="<?= e(url('/reports/account-balances')) ?>" class="card flex flex-wrap items-end gap-3 px-5 py-4">
        <div>
            <label for="f-as-of" class="label">As of</label>
            <input id="f-as-of" name="as_of" type="date" value="<?= e((string) ($asOf ?? '')) ?>" class="input">
        </div>
        <button type="submit" class="btn-dark">Apply</button>
        <a href="<?= e(url('/reports/account-balances')) ?>" class="btn-secondary">Now</a>
        <a href="<?= e(url('/reports/account-balances') . '?' . http_build_query(array_filter(['as_of' => $asOf, 'format' => 'csv']))) ?>"
           class="btn-secondary ml-auto">Export CSV</a>
        <a href="<?= e(url('/reports/account-balances') . '?' . http_build_query(array_filter(['as_of' => $asOf, 'format' => 'pdf']))) ?>"
           class="btn-secondary">Export PDF</a>
    </form>

    <div class="card px-5 py-4">
        <p class="kpi-label">Total across active accounts</p>
        <p class="money mt-1.5 font-display text-xl font-semibold text-ink"><?= e($symbol) ?> <?= e($money($report['total'])) ?></p>
    </div>

    <div class="card overflow-hidden">
        <table class="w-full border-collapse">
            <thead>
                <tr>
                    <th scope="col" class="th">Account</th>
                    <th scope="col" class="th">Type</th>
                    <th scope="col" class="th text-right">Balance</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($report['accounts'] === []) : ?>
                    <tr><td class="td py-8 text-center text-sm text-slate-500" colspan="3">No active accounts.</td></tr>
                <?php endif; ?>
                <?php foreach ($report['accounts'] as $account) : ?>
                    <tr>
                        <td class="td font-medium text-ink"><?= e((string) $account['name']) ?></td>
                        <td class="td text-slate-600"><?= e(Account::TYPES[(string) $account['type']] ?? (string) $account['type']) ?></td>
                        <td class="td money text-right font-medium <?= (float) $account['balance'] < 0 ? 'text-bad-text' : 'text-ink' ?>">
                            <?= e($money($account['balance'])) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
