<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Budget;

/**
 * Budget versus actual, for a month.
 *
 * Extracted from BudgetController when the dashboard became a second reader:
 * spec §14 alone needs this four times over (Budget Used, Budget Remaining,
 * the Budget vs Actual chart and Alerts), and a utilisation percentage that
 * disagreed between the dashboard and the Budgets screen would be exactly the
 * kind of defect the "dashboard reconciles with transactions" criterion exists
 * to prevent.
 *
 * Actual spend is always read live from the ledger through LedgerQuery, which
 * applies `status = 'posted'` in its constructor — so a voided expense drops
 * out of utilisation everywhere at once.
 */
final class BudgetService
{
    public const DEFAULT_THRESHOLD_PCT = 80;

    /**
     * Every budget for a month, with its live actual, remaining and state.
     *
     * @return list<array<string,mixed>>
     */
    public static function summaryForPeriod(int $year, int $month): array
    {
        $rows = (new Budget())->forPeriod($year, $month);

        if ($rows === []) {
            return [];
        }

        [$from, $to] = self::periodBounds($year, $month);
        $actuals = self::actualsByCategory($from, $to);

        $summary = [];
        foreach ($rows as $row) {
            $spent = $actuals[(int) $row['category_id']] ?? '0.00';
            $budget = (float) $row['amount'];
            $threshold = (int) $row['alert_threshold_pct'];

            // Spec §25: utilisation = actual / budget x 100, remaining =
            // budget - actual. Both are presentation figures derived on read,
            // never stored.
            $utilisation = $budget > 0 ? round(((float) $spent / $budget) * 100, 1) : 0.0;

            $summary[] = $row + [
                'spent' => $spent,
                'remaining' => number_format($budget - (float) $spent, 2, '.', ''),
                'utilisation_pct' => $utilisation,
                'state' => match (true) {
                    (float) $spent > $budget => 'exceeded',
                    $utilisation >= $threshold => 'warning',
                    default => 'ok',
                },
            ];
        }

        return $summary;
    }

    /**
     * Month totals across every budgeted category.
     *
     * @param list<array<string,mixed>> $summary from summaryForPeriod()
     * @return array{budgeted:string,spent:string,remaining:string,utilisation_pct:float,count:int}
     */
    public static function totals(array $summary): array
    {
        $budgeted = 0.0;
        $spent = 0.0;

        foreach ($summary as $row) {
            $budgeted += (float) $row['amount'];
            $spent += (float) $row['spent'];
        }

        return [
            'budgeted' => number_format($budgeted, 2, '.', ''),
            'spent' => number_format($spent, 2, '.', ''),
            'remaining' => number_format($budgeted - $spent, 2, '.', ''),
            'utilisation_pct' => $budgeted > 0 ? round(($spent / $budgeted) * 100, 1) : 0.0,
            'count' => count($summary),
        ];
    }

    /**
     * Budgets that need attention — spec §13 asks for both the approaching
     * threshold and the exceeded case, and spec §14 surfaces them as Alerts.
     *
     * @param list<array<string,mixed>> $summary from summaryForPeriod()
     * @return list<array<string,mixed>>
     */
    public static function alerts(array $summary): array
    {
        $alerts = array_values(array_filter(
            $summary,
            static fn (array $row): bool => $row['state'] !== 'ok'
        ));

        // Worst first: over budget outranks merely approaching the limit, and
        // within each, the higher utilisation is the more urgent.
        usort($alerts, static function (array $a, array $b): int {
            $rank = static fn (array $row): int => $row['state'] === 'exceeded' ? 0 : 1;
            return [$rank($a), -$a['utilisation_pct']] <=> [$rank($b), -$b['utilisation_pct']];
        });

        return $alerts;
    }

    /** @return array{0:string,1:string} first and last day of the month */
    public static function periodBounds(int $year, int $month): array
    {
        $from = sprintf('%04d-%02d-01', $year, $month);

        return [$from, date('Y-m-t', (int) strtotime($from))];
    }

    /**
     * Posted expenses for the period, grouped by parent category — the same
     * rollup every other category report uses.
     *
     * @return array<int,string> category id => spent amount
     */
    private static function actualsByCategory(string $from, string $to): array
    {
        $rows = LedgerQuery::posted()->expensesOnly()->between($from, $to)->groupedByCategory();

        $actuals = [];
        foreach ($rows as $row) {
            $actuals[(int) $row['category_id']] = (string) $row['total'];
        }

        return $actuals;
    }
}
