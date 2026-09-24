<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Models\Account;
use InvalidArgumentException;
use RuntimeException;

/**
 * Every figure on the dashboard, spec §14.
 *
 * Two rules shape this class:
 *
 *   - "Dashboard totals reconcile with transactions" is a V1 acceptance
 *     criterion (§29), so every number here is an aggregate over the same
 *     `transactions` table, through the same LedgerQuery, with the same
 *     `status = 'posted'` filter that every other screen uses. Nothing is
 *     cached and nothing is stored.
 *   - §27 asks for efficient SQL aggregation and forbids loading transaction
 *     tables into the browser. So the query count here is bounded by the
 *     number of widgets, accounts and budgeted categories — never by how many
 *     transactions exist. The trend series is ONE grouped query, not one per
 *     month.
 */
final class DashboardService
{
    /** Months shown in the Revenue vs Expenses trend (spec §14, §15). */
    public const TREND_MONTHS = 6;

    /** Spec §14 "Today's Expenses" — daily spending, always actually today. */
    public static function todayExpenses(): string
    {
        $today = date('Y-m-d');

        return LedgerQuery::posted()->expensesOnly()->between($today, $today)->totalAmount();
    }

    /** Spec §14 "Monthly Expenses" — current-month spending. */
    public static function expensesBetween(string $from, string $to): string
    {
        return LedgerQuery::posted()->expensesOnly()->between($from, $to)->totalAmount();
    }

    /**
     * Spec §14 "Monthly Revenue" — current-month income.
     *
     * Cash basis (decision D-2): posted() excludes rows still sitting at
     * 'pending', so invoiced-but-unpaid money is not revenue here.
     */
    public static function revenueBetween(string $from, string $to): string
    {
        return LedgerQuery::posted()->revenueOnly()->between($from, $to)->totalAmount();
    }

    /**
     * Spec §14/§25 "Net Profit" — revenue minus business expenses.
     *
     * profitAndLossOnly() resolves membership from TransactionType, so
     * transfers and partner capital are excluded by construction rather than
     * by a filter someone has to remember.
     */
    public static function profitBetween(string $from, string $to): string
    {
        return LedgerQuery::posted()->profitAndLossOnly()->between($from, $to)->netAmount();
    }

    /**
     * Spec §14 "Account Balance" — available balances across active accounts.
     *
     * Each balance is opening + posted movements (§25), derived on read. The
     * loop is bounded by how many accounts the business has, not by
     * transaction volume.
     *
     * @return array{total:string,accounts:list<array<string,mixed>>}
     */
    public static function accountBalances(): array
    {
        $accounts = array_values(array_filter(
            (new Account())->allOrdered(),
            static fn (array $a): bool => (int) $a['is_active'] === 1
        ));

        $total = 0.0;
        $rows = [];

        foreach ($accounts as $account) {
            $balance = TransactionService::accountBalance((int) $account['id']);
            $total += (float) $balance;
            $rows[] = $account + ['balance' => $balance];
        }

        return ['total' => number_format($total, 2, '.', ''), 'accounts' => $rows];
    }

    /**
     * Spec §14/§15 "Revenue vs Expenses" — trend comparison.
     *
     * One grouped query for the whole series. Months with no activity are
     * filled in here so the chart shows a continuous axis rather than
     * silently skipping a quiet month.
     *
     * @return list<array{month:string,label:string,revenue:string,expense:string,profit:string}>
     */
    public static function revenueExpenseTrend(int $months = self::TREND_MONTHS): array
    {
        $months = max(1, min($months, 24));
        $start = date('Y-m-01', (int) strtotime('-' . ($months - 1) . ' months'));

        // Grouped by the effective date (received_at when set, else
        // transaction_date) for the same reason LedgerQuery::between() is:
        // cash basis means the month money arrived is the one every figure
        // reports against, not the month it was invoiced.
        $branchId = Auth::branchId();
        if ($branchId === null) {
            throw new RuntimeException('No active branch — cannot read the dashboard.');
        }

        $rows = Database::instance()->all(
            "SELECT DATE_FORMAT(COALESCE(t.received_at, t.transaction_date), '%Y-%m') AS ym,
                    COALESCE(SUM(CASE WHEN t.type = 'income'  THEN t.amount END), 0) AS revenue,
                    COALESCE(SUM(CASE WHEN t.type = 'expense' THEN t.amount END), 0) AS expense
             FROM transactions t
             WHERE t.status = 'posted'
               AND t.branch_id = :branch
               AND t.type IN ('income', 'expense')
               AND COALESCE(t.received_at, t.transaction_date) >= :start
             GROUP BY ym
             ORDER BY ym",
            ['branch' => $branchId, 'start' => $start]
        );

        $byMonth = [];
        foreach ($rows as $row) {
            $byMonth[(string) $row['ym']] = $row;
        }

        $series = [];
        for ($i = 0; $i < $months; $i++) {
            $timestamp = (int) strtotime($start . ' +' . $i . ' months');
            $key = date('Y-m', $timestamp);
            $revenue = (string) ($byMonth[$key]['revenue'] ?? '0.00');
            $expense = (string) ($byMonth[$key]['expense'] ?? '0.00');

            $series[] = [
                'month' => $key,
                'label' => date('M Y', $timestamp),
                'revenue' => $revenue,
                'expense' => $expense,
                'profit' => number_format((float) $revenue - (float) $expense, 2, '.', ''),
            ];
        }

        return $series;
    }

    /**
     * Spec §14/§15 "Expense by Category" — spending mix for the period.
     *
     * @return list<array<string,mixed>>
     */
    public static function expenseByCategory(string $from, string $to, int $limit = 8): array
    {
        $rows = LedgerQuery::posted()->expensesOnly()->between($from, $to)->groupedByCategory();

        return array_slice($rows, 0, max(1, $limit));
    }

    /** Spec §14 "Recent Transactions" — latest activity, paged not loaded whole (§27). */
    public static function recentTransactions(int $limit = 8): array
    {
        return LedgerQuery::posted()->page(1, max(1, min($limit, 50)));
    }

    /** @return array{0:int,1:int} year and month parsed from a YYYY-MM filter value */
    public static function parseMonth(?string $value): array
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}$/', $value) !== 1) {
            return [(int) date('Y'), (int) date('n')];
        }

        [$year, $month] = array_map('intval', explode('-', $value));

        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
            throw new InvalidArgumentException('Not a valid month: ' . $value);
        }

        return [$year, $month];
    }
}
