<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Models\RecurringOccurrence;
use App\Services\BudgetService;
use App\Services\DashboardService;
use InvalidArgumentException;

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
        [$year, $month] = $this->periodFromRequest();
        [$from, $to] = BudgetService::periodBounds($year, $month);

        $budgetSummary = BudgetService::summaryForPeriod($year, $month);
        $budgetTotals = BudgetService::totals($budgetSummary);

        $this->view('pages/dashboard', [
            'title' => 'Dashboard',
            'nav' => 'dashboard',
            'pageTitle' => 'Dashboard',
            'pageMeta' => date('F Y', (int) strtotime($from)) . ' · all accounts',
            'pageScripts' => '<link rel="stylesheet" href="' . e(asset('assets/vendor/apexcharts.css')) . '">'
                . '<script src="' . e(asset('assets/vendor/apexcharts.min.js')) . '" defer></script>'
                . '<script src="' . e(asset('assets/js/dashboard.js')) . '" defer></script>',
            'monthValue' => sprintf('%04d-%02d', $year, $month),
            'todayExpenses' => DashboardService::todayExpenses(),
            'monthExpenses' => DashboardService::expensesBetween($from, $to),
            'monthRevenue' => DashboardService::revenueBetween($from, $to),
            'monthProfit' => DashboardService::profitBetween($from, $to),
            'budgetTotals' => $budgetTotals,
            'accountBalances' => DashboardService::accountBalances(),
            'recentTransactions' => DashboardService::recentTransactions(8),
            'alerts' => BudgetService::alerts($budgetSummary),
            // One bounded COUNT query (RecurringOccurrence::pendingCount()),
            // same cost discipline as every other widget here (§27).
            'pendingRecurringCount' => (new RecurringOccurrence())->pendingCount(),
        ]);
    }

    /** Spec §14/§15 "Revenue vs Expenses" trend, last 6 months. */
    public function trendChart(): void
    {
        $this->json(['series' => DashboardService::revenueExpenseTrend()]);
    }

    /** Spec §14/§15 "Expense by Category" for the selected month. */
    public function categoryChart(): void
    {
        [$year, $month] = $this->periodFromRequest();
        [$from, $to] = BudgetService::periodBounds($year, $month);

        $this->json(['categories' => DashboardService::expenseByCategory($from, $to)]);
    }

    /** Spec §14/§15 "Budget vs Actual" for the selected month. */
    public function budgetChart(): void
    {
        [$year, $month] = $this->periodFromRequest();

        $this->json(['budgets' => BudgetService::summaryForPeriod($year, $month)]);
    }

    /** @return array{0:int,1:int} */
    private function periodFromRequest(): array
    {
        try {
            return DashboardService::parseMonth($_GET['month'] ?? null);
        } catch (InvalidArgumentException) {
            return [(int) date('Y'), (int) date('n')];
        }
    }
}
