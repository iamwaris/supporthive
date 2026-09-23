<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Http;
use App\Core\Logger;
use App\Core\Session;
use App\Models\Budget;
use App\Models\Category;
use App\Services\LedgerQuery;

/**
 * Monthly budget per category, read against the ledger live.
 *
 * Utilisation and the exceeded/nearing-threshold state are computed on every
 * request rather than stored, for the same reason an account balance is
 * derived: a cached figure drifts from the ledger the moment a transaction in
 * the period is voided, backdated or added.
 */
final class BudgetController extends Controller
{
    public function index(): void
    {
        [$year, $month] = $this->periodFromRequest();

        $budgets = new Budget();
        $rows = $budgets->forPeriod($year, $month);

        $from = sprintf('%04d-%02d-01', $year, $month);
        $to = date('Y-m-t', (int) strtotime($from));
        $actuals = self::actualsByCategory($from, $to);

        $summary = [];
        foreach ($rows as $row) {
            $categoryId = (int) $row['category_id'];
            $spent = $actuals[$categoryId] ?? '0.00';
            $budgetAmount = (float) $row['amount'];
            $threshold = (int) $row['alert_threshold_pct'];
            $utilisationPct = $budgetAmount > 0 ? round(((float) $spent / $budgetAmount) * 100, 1) : 0.0;

            $summary[] = $row + [
                'spent' => $spent,
                'utilisation_pct' => $utilisationPct,
                'state' => match (true) {
                    (float) $spent > $budgetAmount => 'exceeded',
                    $utilisationPct >= $threshold => 'warning',
                    default => 'ok',
                },
            ];
        }

        $categories = new Category();
        $budgetedIds = array_map(static fn (array $r): int => (int) $r['category_id'], $rows);
        $available = array_values(array_filter(
            $categories->parentsFor('expense'),
            static fn (array $c): bool => !in_array((int) $c['id'], $budgetedIds, true)
        ));

        $this->view('pages/budgets', [
            'title' => 'Budgets',
            'nav' => 'budgets',
            'pageTitle' => 'Budgets',
            'pageMeta' => 'Budget vs actual, read live from the ledger',
            'year' => $year,
            'month' => $month,
            'budgets' => $summary,
            'availableCategories' => $available,
        ]);
    }

    public function store(): void
    {
        [$year, $month] = $this->periodFromRequest();
        $back = '/budgets?year=' . $year . '&month=' . $month;

        $clean = $this->validate([
            'year' => 'required|int|between:2020,2100',
            'month' => 'required|int|between:1,12',
            'category_id' => 'required|int',
            'amount' => 'required|numeric',
            'alert_threshold_pct' => 'nullable|int|between:1,100',
        ], $back);

        $budgetYear = (int) $clean['year'];
        $budgetMonth = (int) $clean['month'];
        $categoryId = (int) $clean['category_id'];
        $back = '/budgets?year=' . $budgetYear . '&month=' . $budgetMonth;

        $categories = new Category();
        $category = $categories->find($categoryId);

        // Only top-level expense categories: a budget on a subcategory would
        // never match groupedByCategory(), which rolls children into their
        // parent for every other report, and would look silently unspent.
        if ($category === null || (string) $category['type'] !== 'expense' || $category['parent_id'] !== null) {
            Session::flash('error', 'Choose a top-level expense category — budgets track spending, not revenue.');
            Http::redirect($back);
        }

        $budgets = new Budget();

        if ($budgets->existsFor($budgetYear, $budgetMonth, $categoryId)) {
            Session::set('_old', $_POST);
            Session::flash('errors', ['category_id' => ['This category already has a budget for this month.']]);
            Session::flash('error', 'A budget already exists for that category and month.');
            Http::redirect($back);
        }

        $budgets->createFor([
            'year' => $budgetYear,
            'month' => $budgetMonth,
            'category_id' => $categoryId,
            'amount' => (string) $clean['amount'],
            'alert_threshold_pct' => (int) ($clean['alert_threshold_pct'] ?? 80),
        ]);

        Logger::info('Budget created', ['year' => $budgetYear, 'month' => $budgetMonth, 'category_id' => $categoryId]);
        Session::flash('success', (string) $category['name'] . ' budget set.');
        Http::redirect($back);
    }

    /** @param array<string,string> $params */
    public function update(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $budgets = new Budget();
        $existing = $budgets->find($id);

        if ($existing === null) {
            Http::abort(404);
        }

        $back = '/budgets?year=' . (int) $existing['year'] . '&month=' . (int) $existing['month'];

        $clean = $this->validate([
            'amount' => 'required|numeric',
            'alert_threshold_pct' => 'nullable|int|between:1,100',
        ], $back);

        $budgets->updateById($id, [
            'amount' => (string) $clean['amount'],
            'alert_threshold_pct' => (int) ($clean['alert_threshold_pct'] ?? 80),
        ]);

        Logger::info('Budget updated', ['budget_id' => $id]);
        Session::flash('success', 'Budget updated.');
        Http::redirect($back);
    }

    /** @return array{0:int,1:int} */
    private function periodFromRequest(): array
    {
        $year = (int) ($_GET['year'] ?? $_POST['year'] ?? date('Y'));
        $month = (int) ($_GET['month'] ?? $_POST['month'] ?? date('n'));

        if ($month < 1 || $month > 12) {
            $month = (int) date('n');
        }
        if ($year < 2020 || $year > 2100) {
            $year = (int) date('Y');
        }

        return [$year, $month];
    }

    /**
     * Posted expenses for the period, grouped by parent category — the same
     * rollup every other category report uses, via the one query builder that
     * applies `status = 'posted'` so a voided expense can never count here.
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
