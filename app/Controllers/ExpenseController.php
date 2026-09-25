<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Http;
use App\Core\RateLimiter;
use App\Core\Session;
use App\Domain\TransactionType;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Expense;
use App\Services\AiAvailability;
use App\Services\AnthropicClient;
use App\Services\LedgerQuery;
use App\Services\Settings;
use App\Services\TransactionService;
use InvalidArgumentException;
use JsonException;
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
            'aiEnabled' => AiAvailability::enabledForCurrentBranch(),
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
     * Pre-fill convenience only: reads an uploaded receipt image/PDF, asks the
     * Anthropic API to extract vendor/amount/date/category from it, and
     * returns the guess as JSON. Nothing here is persisted — not the file
     * (no Upload::store() call), not a transaction, not an attachment — so a
     * bad extraction costs the user nothing but a re-type. Only a usage-log
     * row is written, for per-branch quota reporting.
     */
    public function scanReceipt(): void
    {
        if (!AiAvailability::enabledForCurrentBranch()) {
            // A branch with AI off should not even be able to detect that
            // this endpoint exists.
            Http::abort(404);
        }

        RateLimiter::guard('ai_receipt:' . Auth::branchId(), 10, 3600);

        /** @var array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int}|null $file */
        $file = $_FILES['receipt'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $this->json(['error' => 'No file uploaded.'], 422);
        }

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->json(['error' => 'The upload failed. Try again.'], 422);
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            $this->json(['error' => 'Invalid upload.'], 422);
        }

        $maxBytes = (int) Config::get('uploads.max_bytes', 5_242_880);
        if ((int) ($file['size'] ?? 0) > $maxBytes) {
            $this->json(['error' => 'File is larger than the ' . round($maxBytes / 1048576, 1) . ' MB limit.'], 422);
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmpName);

        /** @var array<string,string> $extensionsByMime */
        $extensionsByMime = [
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
            'image/webp'      => 'webp',
            'image/gif'       => 'gif',
            'application/pdf' => 'pdf',
        ];

        /** @var list<string> $allowedMime */
        $allowedMime = Config::get('uploads.allowed_mime', []);
        if (!isset($extensionsByMime[$mime]) || !in_array($mime, $allowedMime, true)) {
            $this->json(['error' => 'That file type is not allowed.'], 422);
        }

        if (str_starts_with($mime, 'image/') && @getimagesize($tmpName) === false) {
            $this->json(['error' => 'That image could not be read.'], 422);
        }

        $bytes = file_get_contents($tmpName);
        if ($bytes === false) {
            $this->json(['error' => 'Could not read that receipt. Enter the details manually.'], 502);
        }

        $encoded = base64_encode($bytes);
        $contentBlock = $mime === 'application/pdf'
            ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $encoded]]
            : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $encoded]];

        $tool = [
            'name' => 'extract_receipt',
            'description' => 'Record the fields extracted from a receipt image or document.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'vendor' => ['type' => ['string', 'null'], 'description' => 'The vendor/payee name as printed.'],
                    'amount' => [
                        'type' => ['string', 'null'],
                        'description' => 'Total amount as a plain decimal string, no currency symbol, e.g. "12.50".',
                    ],
                    'transaction_date' => [
                        'type' => ['string', 'null'],
                        'description' => 'Date in ISO format YYYY-MM-DD.',
                    ],
                    'category_guess' => [
                        'type' => ['string', 'null'],
                        'description' => 'Best-guess expense category name, if evident.',
                    ],
                    'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                ],
                'required' => ['vendor', 'amount', 'transaction_date', 'category_guess', 'confidence'],
            ],
        ];

        $system = 'You extract structured data from a receipt image or PDF. Report only what is visibly printed '
            . 'on the receipt - never infer, guess, or fill in a value that is not legible. If a field is not '
            . 'present or not legible, return null for it. Always respond using the extract_receipt tool, with '
            . 'no other output.';

        try {
            $response = AnthropicClient::forCurrentBranch()->createMessage(
                [
                    [
                        'role' => 'user',
                        'content' => [
                            $contentBlock,
                            ['type' => 'text', 'text' => 'Extract the fields from this receipt.'],
                        ],
                    ],
                ],
                $system,
                [$tool],
                1024
            );

            $extracted = $this->extractToolInput($response);
        } catch (RuntimeException | JsonException) {
            $this->json(['error' => 'Could not read that receipt. Enter the details manually.'], 502);
        }

        if ($extracted === null) {
            $this->json(['error' => 'Could not read that receipt. Enter the details manually.'], 502);
        }

        $categoryId = null;
        $categoryLabel = null;
        $categoryGuess = $extracted['category_guess'] ?? null;
        if (is_string($categoryGuess) && trim($categoryGuess) !== '') {
            foreach ((new Category())->tree('expense') as $node) {
                if (strcasecmp((string) $node['parent']['name'], $categoryGuess) === 0) {
                    $categoryId = (int) $node['parent']['id'];
                    $categoryLabel = (string) $node['parent']['name'];
                    break;
                }
                foreach ($node['children'] as $child) {
                    if (strcasecmp((string) $child['name'], $categoryGuess) === 0) {
                        $categoryId = (int) $child['id'];
                        $categoryLabel = (string) $child['name'];
                        break 2;
                    }
                }
            }
        }

        Database::instance()->insert('ai_usage_log', [
            'branch_id' => Auth::branchId(),
            'user_id' => Auth::id(),
            'feature' => 'receipt_scan',
        ]);

        $this->json([
            'vendor' => $this->stringOrNull($extracted['vendor'] ?? null),
            'amount' => $this->stringOrNull($extracted['amount'] ?? null),
            'transaction_date' => $this->stringOrNull($extracted['transaction_date'] ?? null),
            'category_id' => $categoryId,
            'category_label' => $categoryLabel,
            'confidence' => $this->stringOrNull($extracted['confidence'] ?? null) ?? 'low',
        ]);
    }

    /**
     * Pulls the extract_receipt tool_use block's input out of a Messages API
     * response. Returns null on anything unexpected — the caller turns that
     * into the same generic "could not read" response as every other failure
     * mode, never a raw parse error.
     *
     * @param array<string,mixed> $response
     * @return array<string,mixed>|null
     */
    private function extractToolInput(array $response): ?array
    {
        $content = $response['content'] ?? null;
        if (!is_array($content)) {
            return null;
        }

        foreach ($content as $block) {
            if (!is_array($block) || ($block['type'] ?? null) !== 'tool_use') {
                continue;
            }
            if (($block['name'] ?? null) !== 'extract_receipt') {
                continue;
            }

            $input = $block['input'] ?? null;
            if (is_array($input)) {
                return $input;
            }
            if (is_string($input)) {
                /** @var array<string,mixed> $decoded */
                $decoded = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
                return $decoded;
            }

            return null;
        }

        return null;
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
