<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Csv;
use App\Core\Pdf;
use App\Core\View;
use App\Domain\TransactionType;
use App\Models\Partner;
use App\Services\BudgetService;
use App\Services\DashboardService;
use App\Services\LedgerQuery;
use App\Services\ProfitDistributionService;
use App\Services\ReportService;
use App\Services\Settings;
use InvalidArgumentException;

/**
 * The 12 reports, spec §16. Read-only, so every action is ['can:view'] —
 * same visibility as the dashboard. Filters come from the query string, same
 * convention as TransactionController::index(), so a filtered report is a
 * shareable URL.
 *
 * Every figure is a bounded SQL aggregate via LedgerQuery / BudgetService /
 * ProfitDistributionService / ReportService — never a PHP loop over the
 * transaction table.
 *
 * `?format=csv` or `?format=pdf` on any report route exports the same rows
 * the page shows, via export() below — one place building both formats from
 * one table shape (App\Core\Csv, App\Core\Pdf).
 */
final class ReportController extends Controller
{
    private const PER_PAGE = 200;

    public function index(): void
    {
        $this->view('pages/reports/index', [
            'title' => 'Reports',
            'nav' => 'reports',
            'pageTitle' => 'Reports',
            'pageMeta' => 'Spec §16 — 12 reports, each with its own filters',
        ]);
    }

    public function profitLoss(): void
    {
        [$from, $to] = $this->periodFromRequest();
        $report = ReportService::profitLoss($from, $to);

        $rows = [
            ['Summary', 'Revenue', $report['revenue']],
            ['Summary', 'Expenses', $report['expenses']],
            ['Summary', 'Net profit', $report['net']],
        ];
        foreach ($report['byCategory'] as $category) {
            $rows[] = ['Expense category', (string) $category['category_name'], (string) $category['total']];
        }

        $this->export('profit-loss-' . $from . '-to-' . $to, ['Type', 'Label', 'Amount'], $rows, [
            'title' => 'Profit & Loss',
            'subtitle' => $this->rangeLabel($from, $to),
        ]);

        $this->view('pages/reports/profit-loss', [
            'title' => 'Profit & Loss',
            'nav' => 'reports',
            'pageTitle' => 'Profit & Loss',
            'pageMeta' => $this->rangeLabel($from, $to),
            'from' => $from,
            'to' => $to,
            'report' => $report,
        ]);
    }

    public function income(): void
    {
        [$from, $to] = $this->periodFromRequest();

        $posted = LedgerQuery::posted()->revenueOnly()->between($from, $to);
        $pending = LedgerQuery::includeVoided()->pendingOnly()->revenueOnly()->between($from, $to);
        $rows = $posted->page(1, self::PER_PAGE);
        $pendingRows = $pending->page(1, self::PER_PAGE);

        $exportRows = [];
        foreach ($rows as $row) {
            $exportRows[] = array_merge(['Received'], $this->transactionCsvRow($row));
        }
        foreach ($pendingRows as $row) {
            $exportRows[] = array_merge(['Expected'], $this->transactionCsvRow($row));
        }

        $this->export(
            'income-' . $from . '-to-' . $to,
            ['Section', 'Date', 'Description', 'Category', 'Account', 'Amount'],
            $exportRows,
            ['title' => 'Monthly Income', 'subtitle' => $this->rangeLabel($from, $to)]
        );

        $this->view('pages/reports/income', [
            'title' => 'Monthly Income',
            'nav' => 'reports',
            'pageTitle' => 'Monthly Income',
            'pageMeta' => $this->rangeLabel($from, $to),
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'total' => $posted->totalAmount(),
            'pendingRows' => $pendingRows,
            'pendingTotal' => $pending->totalAmount(),
        ]);
    }

    public function expenses(): void
    {
        [$from, $to] = $this->periodFromRequest();

        $query = LedgerQuery::posted()->expensesOnly()->between($from, $to);
        $rows = $query->page(1, self::PER_PAGE);

        $this->export(
            'expenses-' . $from . '-to-' . $to,
            ['Date', 'Description', 'Category', 'Account', 'Amount'],
            array_map(fn (array $row): array => $this->transactionCsvRow($row), $rows),
            ['title' => 'Monthly Expenses', 'subtitle' => $this->rangeLabel($from, $to)]
        );

        $this->view('pages/reports/expenses', [
            'title' => 'Monthly Expenses',
            'nav' => 'reports',
            'pageTitle' => 'Monthly Expenses',
            'pageMeta' => $this->rangeLabel($from, $to),
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'total' => $query->totalAmount(),
            'byCategory' => LedgerQuery::posted()->expensesOnly()->between($from, $to)->groupedByCategory(),
        ]);
    }

    public function expenseByCategory(): void
    {
        [$from, $to] = $this->periodFromRequest();
        $rows = LedgerQuery::posted()->expensesOnly()->between($from, $to)->groupedByCategory();

        $total = array_reduce($rows, static fn (float $sum, array $r): float => $sum + (float) $r['total'], 0.0);

        $exportRows = [];
        foreach ($rows as $row) {
            $pct = $total > 0 ? round(((float) $row['total'] / $total) * 100, 1) : 0.0;
            $exportRows[] = [
                (string) $row['category_name'], (string) $row['total'], (string) $row['entries'], (string) $pct,
            ];
        }

        $this->export(
            'expense-by-category-' . $from . '-to-' . $to,
            ['Category', 'Amount', 'Entries', '% of Total'],
            $exportRows,
            ['title' => 'Expense by Category', 'subtitle' => $this->rangeLabel($from, $to)]
        );

        $this->view('pages/reports/expense-by-category', [
            'title' => 'Expense by Category',
            'nav' => 'reports',
            'pageTitle' => 'Expense by Category',
            'pageMeta' => $this->rangeLabel($from, $to),
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'total' => number_format($total, 2, '.', ''),
        ]);
    }

    public function budgetVsActual(): void
    {
        [$year, $month] = $this->monthFromRequest();
        $summary = BudgetService::summaryForPeriod($year, $month);
        $monthLabel = date('F Y', (int) strtotime(sprintf('%04d-%02d-01', $year, $month)));

        $exportRows = [];
        foreach ($summary as $row) {
            $exportRows[] = [
                (string) $row['category_name'], (string) $row['amount'], (string) $row['spent'],
                (string) $row['remaining'], (string) $row['utilisation_pct'], (string) $row['state'],
            ];
        }

        $this->export(
            'budget-vs-actual-' . sprintf('%04d-%02d', $year, $month),
            ['Category', 'Budget', 'Spent', 'Remaining', 'Utilisation %', 'Status'],
            $exportRows,
            ['title' => 'Budget vs Actual', 'subtitle' => $monthLabel]
        );

        $this->view('pages/reports/budget-vs-actual', [
            'title' => 'Budget vs Actual',
            'nav' => 'reports',
            'pageTitle' => 'Budget vs Actual',
            'pageMeta' => $monthLabel,
            'monthValue' => sprintf('%04d-%02d', $year, $month),
            'summary' => $summary,
            'totals' => BudgetService::totals($summary),
        ]);
    }

    public function cashFlow(): void
    {
        [$from, $to] = $this->periodFromRequest();
        $report = ReportService::cashFlow($from, $to);

        $this->export('cash-flow-' . $from . '-to-' . $to, ['Metric', 'Amount'], [
            ['Money in', $report['inbound']],
            ['Money out', $report['outbound']],
            ['Net cash flow', $report['net']],
            ['Expected income, not counted', $report['pendingIncome']],
        ], ['title' => 'Cash Flow', 'subtitle' => $this->rangeLabel($from, $to)]);

        $this->view('pages/reports/cash-flow', [
            'title' => 'Cash Flow',
            'nav' => 'reports',
            'pageTitle' => 'Cash Flow',
            'pageMeta' => $this->rangeLabel($from, $to),
            'from' => $from,
            'to' => $to,
            'report' => $report,
        ]);
    }

    public function accountBalances(): void
    {
        $asOf = $this->stringOrNull($_GET['as_of'] ?? null);
        $report = ReportService::accountBalancesAsOf($asOf);
        $subtitle = $asOf === null ? 'As of right now' : 'As of ' . date('j M Y', (int) strtotime($asOf));

        $exportRows = [];
        foreach ($report['accounts'] as $account) {
            $exportRows[] = [(string) $account['name'], (string) $account['type'], (string) $account['balance']];
        }

        $this->export(
            'account-balances-' . ($asOf ?? date('Y-m-d')),
            ['Account', 'Type', 'Balance'],
            $exportRows,
            ['title' => 'Account Balances', 'subtitle' => $subtitle]
        );

        $this->view('pages/reports/account-balances', [
            'title' => 'Account Balances',
            'nav' => 'reports',
            'pageTitle' => 'Account Balances',
            'pageMeta' => $subtitle,
            'asOf' => $asOf,
            'report' => $report,
        ]);
    }

    public function partnerStatement(): void
    {
        [$from, $to] = $this->periodFromRequest();
        $partners = (new Partner())->allOrdered();
        $partnerId = $this->intOrNull($_GET['partner_id'] ?? null) ?? (int) ($partners[0]['id'] ?? 0);

        $statement = $partnerId > 0 ? ReportService::partnerStatement($partnerId, $from, $to) : null;

        if ($statement !== null) {
            $exportRows = [['Opening balance', '', '', '', $statement['opening']]];
            foreach ($statement['rows'] as $row) {
                $type = TransactionType::from((string) $row['type']);
                $signed = ((int) $row['direction'] === 1 ? '' : '-') . (string) $row['amount'];
                $exportRows[] = [
                    (string) $row['transaction_date'], (string) $row['description'], $type->label(),
                    $signed, (string) $row['running_balance'],
                ];
            }
            $exportRows[] = ['Closing balance', '', '', '', $statement['closing']];

            $this->export(
                'partner-statement-' . $partnerId . '-' . $from . '-to-' . $to,
                ['Date', 'Description', 'Type', 'Amount', 'Running Balance'],
                $exportRows,
                ['title' => 'Partner Statement', 'subtitle' => $this->rangeLabel($from, $to)]
            );
        }

        $this->view('pages/reports/partner-statement', [
            'title' => 'Partner Statement',
            'nav' => 'reports',
            'pageTitle' => 'Partner Statement',
            'pageMeta' => $this->rangeLabel($from, $to),
            'from' => $from,
            'to' => $to,
            'partners' => $partners,
            'partnerId' => $partnerId,
            'statement' => $statement,
        ]);
    }

    public function partnerContributions(): void
    {
        [$from, $to] = $this->periodFromRequest();
        $rows = ReportService::totalsByPartner(TransactionType::PartnerContribution, $from, $to);

        $this->export(
            'partner-contributions-' . $from . '-to-' . $to,
            ['Partner', 'Amount', 'Entries'],
            $this->partnerTotalsRows($rows),
            ['title' => 'Partner Contributions', 'subtitle' => $this->rangeLabel($from, $to)]
        );

        $this->view('pages/reports/partner-contributions', [
            'title' => 'Partner Contributions',
            'nav' => 'reports',
            'pageTitle' => 'Partner Contributions',
            'pageMeta' => $this->rangeLabel($from, $to),
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
        ]);
    }

    public function partnerWithdrawals(): void
    {
        [$from, $to] = $this->periodFromRequest();
        $rows = ReportService::totalsByPartner(TransactionType::PartnerWithdrawal, $from, $to);

        $this->export(
            'partner-withdrawals-' . $from . '-to-' . $to,
            ['Partner', 'Amount', 'Entries'],
            $this->partnerTotalsRows($rows),
            ['title' => 'Partner Withdrawals', 'subtitle' => $this->rangeLabel($from, $to)]
        );

        $this->view('pages/reports/partner-withdrawals', [
            'title' => 'Partner Withdrawals',
            'nav' => 'reports',
            'pageTitle' => 'Partner Withdrawals',
            'pageMeta' => $this->rangeLabel($from, $to),
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
        ]);
    }

    public function profitDistribution(): void
    {
        $batchId = $this->stringOrNull($_GET['batch'] ?? null);
        $batches = ProfitDistributionService::batches();

        $exportRows = [];
        foreach ($batches as $batch) {
            $exportRows[] = [
                (string) $batch['period_start'] . ' to ' . (string) $batch['period_end'],
                (string) $batch['status'], (string) $batch['partner_count'], (string) $batch['total_amount'],
            ];
        }

        $this->export('profit-distribution', ['Period', 'Status', 'Partners', 'Amount'], $exportRows, [
            'title' => 'Profit Distribution',
            'subtitle' => 'Every calculated, approved and distributed batch',
        ]);

        $this->view('pages/reports/profit-distribution', [
            'title' => 'Profit Distribution',
            'nav' => 'reports',
            'pageTitle' => 'Profit Distribution',
            'pageMeta' => 'Every calculated, approved and distributed batch',
            'batches' => $batches,
            'batchId' => $batchId,
            'batchRows' => $batchId !== null ? ProfitDistributionService::batchRows($batchId) : [],
        ]);
    }

    public function dailyTransactions(): void
    {
        $date = $this->stringOrNull($_GET['date'] ?? null) ?? date('Y-m-d');

        $query = LedgerQuery::posted()->between($date, $date);

        $inbound = (clone $query)->types([
            TransactionType::Income,
            TransactionType::TransferIn,
            TransactionType::PartnerContribution,
        ])->totalAmount();

        $outbound = (clone $query)->types([
            TransactionType::Expense,
            TransactionType::TransferOut,
            TransactionType::PartnerWithdrawal,
            TransactionType::ProfitDistribution,
        ])->totalAmount();

        $rows = $query->page(1, self::PER_PAGE);

        $exportRows = array_map(function (array $row): array {
            $type = TransactionType::from((string) $row['type']);
            $signed = ((int) $row['direction'] === 1 ? '' : '-') . (string) $row['amount'];

            return [
                (string) $row['description'], $type->label(), (string) ($row['category_name'] ?? ''),
                (string) $row['account_name'], (string) $row['created_by_name'], $signed,
            ];
        }, $rows);

        $this->export(
            'daily-transactions-' . $date,
            ['Description', 'Type', 'Category', 'Account', 'By', 'Amount'],
            $exportRows,
            ['title' => 'Daily Transaction Report', 'subtitle' => date('l, j F Y', (int) strtotime($date))]
        );

        $this->view('pages/reports/daily-transactions', [
            'title' => 'Daily Transaction Report',
            'nav' => 'reports',
            'pageTitle' => 'Daily Transaction Report',
            'pageMeta' => date('l, j F Y', (int) strtotime($date)),
            'date' => $date,
            'rows' => $rows,
            'inbound' => $inbound,
            'outbound' => $outbound,
        ]);
    }

    /**
     * `?format=csv` or `?format=pdf` exports exactly the rows the page is
     * about to show — one place building both formats from one table shape,
     * so a report's export can never drift from what it displays.
     *
     * @param list<string> $header
     * @param iterable<list<int|float|string|null>> $rows
     * @param array{title:string,subtitle:string} $meta
     */
    private function export(string $baseName, array $header, iterable $rows, array $meta): void
    {
        $format = (string) ($_GET['format'] ?? '');

        if ($format === 'csv') {
            Csv::download($baseName . '.csv', $header, $rows);
        }

        if ($format === 'pdf') {
            $html = View::capture('pdf/report', [
                'title' => $meta['title'],
                'subtitle' => $meta['subtitle'],
                'header' => $header,
                'rows' => $rows,
                'companyName' => Settings::string('company_name', 'LedgerHive'),
            ], null);

            Pdf::download($baseName . '.pdf', $html);
        }
    }

    /**
     * A transaction row in the shape every CSV export of a transaction list
     * uses: date, description, category, account, signed amount.
     *
     * @param array<string,mixed> $row from LedgerQuery::page()
     * @return list<string>
     */
    private function transactionCsvRow(array $row): array
    {
        $signed = ((int) $row['direction'] === 1 ? '' : '-') . (string) $row['amount'];

        return [
            (string) $row['transaction_date'],
            (string) $row['description'],
            (string) ($row['category_name'] ?? ''),
            (string) $row['account_name'],
            $signed,
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows from ReportService::totalsByPartner()
     * @return list<list<string>>
     */
    private function partnerTotalsRows(array $rows): array
    {
        return array_map(
            static fn (array $row): array => [
                (string) $row['partner_name'], (string) $row['total'], (string) $row['entries'],
            ],
            $rows
        );
    }

    /** @return array{0:string,1:string} */
    private function periodFromRequest(): array
    {
        $from = $this->stringOrNull($_GET['from'] ?? null);
        $to = $this->stringOrNull($_GET['to'] ?? null);

        if ($from !== null && $to !== null) {
            return [$from, $to];
        }

        return BudgetService::periodBounds((int) date('Y'), (int) date('n'));
    }

    /** @return array{0:int,1:int} */
    private function monthFromRequest(): array
    {
        try {
            return DashboardService::parseMonth($_GET['month'] ?? null);
        } catch (InvalidArgumentException) {
            return [(int) date('Y'), (int) date('n')];
        }
    }

    private function rangeLabel(string $from, string $to): string
    {
        return date('j M Y', (int) strtotime($from)) . ' – ' . date('j M Y', (int) strtotime($to));
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function intOrNull(mixed $value): ?int
    {
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
