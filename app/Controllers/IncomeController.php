<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Http;
use App\Core\Session;
use App\Domain\TransactionType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Sale;
use App\Services\LedgerQuery;
use App\Services\Settings;
use App\Services\TransactionService;
use InvalidArgumentException;
use RuntimeException;

/**
 * Income, with the cash-basis distinction front and centre.
 *
 * An invoice that has not been paid is recorded as pending: visible and
 * chaseable, but excluded from revenue and from every balance until the money
 * actually arrives. Marking it received is what makes it count — which is the
 * whole point of decision D-2.
 */
final class IncomeController extends Controller
{
    private const PER_PAGE = 25;

    public function index(): void
    {
        $month = $this->monthFilter();

        $received = LedgerQuery::posted()
            ->revenueOnly()
            ->between($month['from'], $month['to']);

        $total = $received->count();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, (int) ($_GET['page'] ?? 1)), $pages);

        // Pending is its own list on purpose. Folding it into the revenue
        // figure is exactly what the cash basis rules out.
        $pending = LedgerQuery::includeVoided()->pendingOnly()->revenueOnly();

        $this->view('pages/income', [
            'title' => 'Income',
            'nav' => 'income',
            'pageTitle' => 'Income',
            'pageMeta' => $month['label'] . ' · ' . number_format($total) . ' received',
            'rows' => $this->withDetail($received->page($page, self::PER_PAGE)),
            'pendingRows' => $this->withDetail($pending->page(1, 50)),
            'monthTotal' => $received->totalAmount(),
            'pendingTotal' => $pending->totalAmount(),
            'pendingCount' => $pending->count(),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'perPage' => self::PER_PAGE,
            'month' => $month,
        ]);
    }

    public function create(): void
    {
        $this->view('pages/income-form', [
            'title' => 'Record Income',
            'nav' => 'income',
            'pageTitle' => 'Record Income',
            'pageMeta' => 'A pending invoice is tracked but never counted as revenue',
            'accounts' => (new Account())->allOrdered(),
            'tree' => (new Category())->tree('income'),
            'customers' => (new Customer())->allOrdered(),
            'lastAccountId' => (int) Session::get('_last_income_account', 0),
        ]);
    }

    public function store(): void
    {
        $clean = $this->validate([
            'transaction_date' => 'required|date',
            'amount' => 'required|max:20',
            'category_id' => 'required|int',
            'account_id' => 'required|int',
            'description' => 'required|max:255',
            'customer_id' => 'nullable|int',
            'invoice_no' => 'nullable|max:80',
            'payment_status' => 'required|in:received,pending',
            'notes' => 'nullable|max:2000',
        ], '/income/new');

        $invoiceNo = $clean['invoice_no'] === null ? null : trim((string) $clean['invoice_no']);

        if ($invoiceNo !== null && $invoiceNo !== '' && Sale::invoiceExists($invoiceNo)) {
            Session::set('_old', $_POST);
            Session::flash('errors', ['invoice_no' => ['That invoice number is already recorded.']]);
            Session::flash('error', 'That invoice number has been used already.');
            Http::redirect('/income/new');
        }

        $customerId = $clean['customer_id'] === null ? null : (int) $clean['customer_id'];
        $notes = $clean['notes'] === null ? null : (string) $clean['notes'];
        $isPending = $clean['payment_status'] === 'pending';

        try {
            TransactionService::post([
                'type' => TransactionType::Income,
                'amount' => (string) $clean['amount'],
                'account_id' => (int) $clean['account_id'],
                'category_id' => (int) $clean['category_id'],
                'transaction_date' => (string) $clean['transaction_date'],
                'description' => (string) $clean['description'],
                'reference_no' => $invoiceNo,
                'status' => $isPending ? 'pending' : 'posted',
            ], static function (int $transactionId) use ($customerId, $invoiceNo, $notes): void {
                Sale::write($transactionId, $customerId, $invoiceNo, $notes);
            });
        } catch (InvalidArgumentException | RuntimeException $e) {
            Session::set('_old', $_POST);
            Session::flash('error', $e->getMessage());
            Http::redirect('/income/new');
        }

        Session::set('_last_income_account', (int) $clean['account_id']);
        Session::forget('_old');

        $amount = Settings::string('currency_symbol', 'Rs')
            . ' ' . number_format((float) $clean['amount'], 2);

        Session::flash(
            'success',
            $isPending
                ? $amount . ' recorded as expected income. It will not count as revenue until received.'
                : $amount . ' income recorded.'
        );

        Http::redirect(isset($_POST['add_another']) ? '/income/new' : '/income');
    }

    /** @param array<string,string> $params */
    public function markReceived(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $when = trim((string) ($_POST['received_at'] ?? ''));

        try {
            TransactionService::markReceived($id, $when === '' ? null : $when);
        } catch (InvalidArgumentException | RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            Http::redirect('/income');
        }

        Session::flash('success', 'Marked as received — it now counts towards revenue and the account balance.');
        Http::redirect('/income');
    }

    /**
     * Attach customer and invoice detail to listing rows.
     *
     * One query per page rather than one per row.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function withDetail(array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }

        $placeholders = [];
        $params = [];

        foreach ($rows as $index => $row) {
            $placeholders[] = ':s' . $index;
            $params['s' . $index] = (int) $row['id'];
        }

        $details = Database::instance()->all(
            'SELECT s.transaction_id, s.invoice_no, s.notes, c.name AS customer_name
             FROM sales s
             LEFT JOIN customers c ON c.id = s.customer_id
             WHERE s.transaction_id IN (' . implode(', ', $placeholders) . ')',
            $params
        );

        $byId = [];
        foreach ($details as $detail) {
            $byId[(int) $detail['transaction_id']] = $detail;
        }

        foreach ($rows as $index => $row) {
            $detail = $byId[(int) $row['id']] ?? null;
            $rows[$index]['invoice_no'] = $detail['invoice_no'] ?? null;
            $rows[$index]['customer_name'] = $detail['customer_name'] ?? null;
            $rows[$index]['notes'] = $detail['notes'] ?? null;
        }

        return $rows;
    }

    /** @return array{from:string,to:string,value:string,label:string} */
    private function monthFilter(): array
    {
        $value = (string) ($_GET['month'] ?? date('Y-m'));

        if (preg_match('/^\d{4}-\d{2}$/', $value) !== 1) {
            $value = date('Y-m');
        }

        $timestamp = (int) strtotime($value . '-01');

        return [
            'from' => $value . '-01',
            'to' => date('Y-m-t', $timestamp),
            'value' => $value,
            'label' => date('F Y', $timestamp),
        ];
    }
}
