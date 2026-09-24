<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Http;
use App\Core\Session;
use App\Domain\TransactionType;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Expense;
use App\Services\LedgerQuery;
use App\Services\Settings;
use App\Services\TransactionService;
use InvalidArgumentException;
use RuntimeException;

/**
 * Expenses — the screen that gets used twenty times a day.
 *
 * The spec's priority here is speed of repeated entry (§20.3), so the form
 * defaults the date to today, remembers the last account used, and offers
 * save-and-add-another. None of that weakens the server-side checks: the
 * posting still goes through TransactionService like everything else.
 */
final class ExpenseController extends Controller
{
    private const PER_PAGE = 25;

    public function index(): void
    {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $month = $this->monthFilter();

        $query = LedgerQuery::posted()
            ->expensesOnly()
            ->between($month['from'], $month['to'])
            ->search($this->stringOrNull($_GET['q'] ?? null));

        $total = $query->count();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);

        $this->view('pages/expenses', [
            'title' => 'Expenses',
            'nav' => 'expenses',
            'pageTitle' => 'Expenses',
            'pageMeta' => $month['label'] . ' · ' . number_format($total) . ' recorded',
            'rows' => $this->withVendors($query->page($page, self::PER_PAGE)),
            'monthTotal' => $query->totalAmount(),
            'byCategory' => (clone $query)->groupedByCategory(),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'perPage' => self::PER_PAGE,
            'month' => $month,
            'search' => $this->stringOrNull($_GET['q'] ?? null),
        ]);
    }

    public function create(): void
    {
        $categories = new Category();

        $this->view('pages/expense-form', [
            'title' => 'Add Expense',
            'nav' => 'expenses',
            'pageTitle' => 'Add Expense',
            'pageMeta' => 'Recorded against you, with the date and time',
            'accounts' => (new Account())->allOrdered(),
            'tree' => $categories->tree('expense'),
            'lastAccountId' => (int) Session::get('_last_expense_account', 0),
            'suggestions' => Expense::vendorSuggestions('', 6),
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
            'vendor' => 'nullable|max:160',
            'reference_no' => 'nullable|max:80',
            'notes' => 'nullable|max:2000',
        ], '/expenses/new');

        $vendor = Expense::tidyVendor($clean['vendor'] === null ? null : (string) $clean['vendor']);
        $notes = $clean['notes'] === null ? null : (string) $clean['notes'];

        try {
            $id = TransactionService::post([
                'type' => TransactionType::Expense,
                'amount' => (string) $clean['amount'],
                'account_id' => (int) $clean['account_id'],
                'category_id' => (int) $clean['category_id'],
                'transaction_date' => (string) $clean['transaction_date'],
                'description' => (string) $clean['description'],
                'reference_no' => $clean['reference_no'] === null ? null : (string) $clean['reference_no'],
            ], static function (int $transactionId) use ($vendor, $notes): void {
                Expense::write($transactionId, $vendor, $notes);
            });
        } catch (InvalidArgumentException | RuntimeException $e) {
            Session::set('_old', $_POST);
            Session::flash('error', $e->getMessage());
            Http::redirect('/expenses/new');
        }

        // Remembered for the next entry: most days' expenses come out of the
        // same account, and re-picking it every time is the slow part.
        Session::set('_last_expense_account', (int) $clean['account_id']);
        Session::forget('_old');

        $symbol = Settings::string('currency_symbol', 'Rs');
        $message = $symbol . ' ' . number_format((float) $clean['amount'], 2) . ' expense recorded (#' . $id . ').';

        $receipt = $_FILES['receipt'] ?? null;
        if (is_array($receipt) && ($receipt['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                /** @var array{name:string,type:string,tmp_name:string,error:int,size:int} $receipt */
                (new Attachment())->attachUpload($id, $receipt);
                $message .= ' Receipt attached.';
            } catch (RuntimeException $e) {
                Session::flash('error', 'Expense recorded, but the receipt was not saved: ' . $e->getMessage());
                Http::redirect(isset($_POST['add_another']) ? '/expenses/new' : '/expenses');
            }
        }

        Session::flash('success', $message);

        // Save-and-add-another keeps a repetitive session moving.
        Http::redirect(isset($_POST['add_another']) ? '/expenses/new' : '/expenses');
    }

    /** Type-ahead for the free-text vendor field. */
    public function vendors(): void
    {
        $term = (string) ($_GET['q'] ?? '');

        $this->json(['suggestions' => Expense::vendorSuggestions($term, 8)]);
    }

    /**
     * Attach vendor names to the listing rows.
     *
     * One query for the page rather than one per row: the listing is paged, so
     * this is at most 25 ids and an N+1 here is felt immediately.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function withVendors(array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $placeholders = [];
        $params = [];

        foreach (array_values($ids) as $index => $id) {
            $placeholders[] = ':e' . $index;
            $params['e' . $index] = $id;
        }

        $details = Database::instance()->all(
            'SELECT transaction_id, vendor, notes FROM expenses WHERE transaction_id IN ('
            . implode(', ', $placeholders) . ')',
            $params
        );

        $byId = [];
        foreach ($details as $detail) {
            $byId[(int) $detail['transaction_id']] = $detail;
        }

        foreach ($rows as $index => $row) {
            $detail = $byId[(int) $row['id']] ?? null;
            $rows[$index]['vendor'] = $detail['vendor'] ?? null;
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

        $start = $value . '-01';
        $timestamp = (int) strtotime($start);

        return [
            'from' => $start,
            'to' => date('Y-m-t', $timestamp),
            'value' => $value,
            'label' => date('F Y', $timestamp),
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
