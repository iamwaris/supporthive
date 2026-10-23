<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Models\RecurringOccurrence;
use App\Services\BudgetService;
use App\Services\DashboardPeriod;
use App\Services\DashboardService;
use DateTimeImmutable;

/**
 * The dashboard, spec §14.
 *
 * Every widget is a bounded SQL aggregate via DashboardService/BudgetService —
 * never a PHP loop over the transaction table (§27) — so the number of
 * queries this page issues does not grow with how much has been recorded.
 *
 * The three chart widgets (Revenue vs Expenses, Expense by Category, Budget
 * vs Actual) are fed by JSON endpoints below rather than inline <script>
 * data: the CSP has no 'unsafe-inline' for scripts, and baking JSON into a
 * template is an XSS vector even when the CSP allows it.
 */
final class DashboardController extends Controller
{
    public function index(): void
    {
        $period = $this->periodFromRequest();

        // Budgets are set per calendar month, so "All time" has no budget
        // figures to show; alerts stay actionable by following the current month.
        $budgetSummary = BudgetService::summaryForPeriod($period->year, $period->month);

        $this->view('pages/dashboard', [
            'title' => 'Dashboard',
            'nav' => 'dashboard',
            'pageTitle' => 'Dashboard',
            'pageMeta' => $period->label() . ' · all accounts',
            'pageScripts' => '<link rel="stylesheet" href="' . e(asset('assets/vendor/apexcharts.css')) . '">'
                . '<script src="' . e(asset('assets/vendor/apexcharts.min.js')) . '" defer></script>'
                . '<script src="' . e(asset('assets/js/dashboard.js')) . '" defer></script>',
            'period' => $period,
            'todayExpenses' => DashboardService::todayExpenses(),
            'monthExpenses' => DashboardService::expensesBetween($period->from, $period->to),
            'monthRevenue' => DashboardService::revenueBetween($period->from, $period->to),
            'monthProfit' => DashboardService::profitBetween($period->from, $period->to),
            'budgetTotals' => BudgetService::totals($budgetSummary),
            'accountBalances' => DashboardService::accountBalances(),
            'recentTransactions' => DashboardService::recentTransactions(8),
            'alerts' => BudgetService::alerts($budgetSummary),
            // One bounded COUNT query (RecurringOccurrence::pendingCount()),
            // same cost discipline as every other widget here (§27).
            'pendingRecurringCount' => (new RecurringOccurrence())->pendingCount(),
        ]);
    }

    /** Spec §14/§15 "Revenue vs Expenses" trend: last 6 months, or by year for All time. */
    public function trendChart(): void
    {
        $period = $this->periodFromRequest();

        $this->json(['series' => $period->allTime
            ? DashboardService::revenueExpenseByYear($period->to)
            : DashboardService::revenueExpenseTrend()]);
    }

    /** Spec §14/§15 "Expense by Category" for the selected month or all time. */
    public function categoryChart(): void
    {
        $period = $this->periodFromRequest();

        $this->json(['categories' => DashboardService::expenseByCategory($period->from, $period->to)]);
    }

    /**
     * Spec §14/§15 "Budget vs Actual" for the selected month.
     *
     * Budgets are monthly, so "All time" has none: the dashboard hides this
     * chart then, and the endpoint answers with an empty list to match.
     */
    public function budgetChart(): void
    {
        $period = $this->periodFromRequest();

        $this->json(['budgets' => $period->allTime
            ? []
            : BudgetService::summaryForPeriod($period->year, $period->month)]);
    }

    private function periodFromRequest(): DashboardPeriod
    {
        return DashboardPeriod::fromQuery($_GET, new DateTimeImmutable('today'));
    }
}
