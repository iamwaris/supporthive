<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Http;
use App\Core\Logger;
use App\Core\Session;
use App\Core\Validator;
use App\Domain\ReceivablePayableKind;
use App\Domain\ReceivablePayableRules;
use App\Domain\ReceivablePayableRuleViolation;
use App\Models\Account;
use App\Models\ReceivablePayable;
use App\Services\ReceivablePayableService;
use App\Services\Settings;
use InvalidArgumentException;
use PDOException;
use RuntimeException;

/**
 * The receivables / payables register (/receivables and /payables).
 *
 * One controller for both sections: routes/web.php fixes `kind` into the
 * route params for each section, so a request can never choose its own.
 * Every id is looked up within this branch AND this kind — another branch's
 * entry, or a payable id under /receivables, is a 404.
 *
 * Entries are loans / advances that post to the ledger through
 * ReceivablePayableService (docs/PLAN.md §5, 2026-10-23): every account
 * picked here is checked server-side to be an active account of this branch.
 */
final class ReceivablePayableController extends Controller
{
    private const PER_PAGE = 25;

    private const ENTRY_RULES = [
        'party_name' => 'required|max:160',
        'reference_no' => 'nullable|max:80',
        'description' => 'nullable|max:255',
        'amount' => 'required|max:17|money',
        'issue_date' => 'required|ymd',
        'due_date' => 'nullable|ymd',
        'account_id' => 'required|int',
    ];

    /**
     * Amount, issue date and account are locked once posted, so the edit form
     * does not send them; if a request does, they are checked for shape here
     * and refused by the service when they differ from the stored values.
     */
    private const EDIT_RULES = [
        'party_name' => 'required|max:160',
        'reference_no' => 'nullable|max:80',
        'description' => 'nullable|max:255',
        'due_date' => 'nullable|ymd',
        'amount' => 'nullable|max:17|money',
        'issue_date' => 'nullable|ymd',
        'account_id' => 'nullable|int',
    ];

    /** @param array<string,string> $params */
    public function index(array $params): void
    {
        $kind = $this->kind($params);

        // Filters come from the URL: anything that fails its rule simply
        // falls back to the default instead of erroring.
        $query = Validator::make($_GET, [
            'status' => 'nullable|in:' . implode(',', ReceivablePayable::FILTERS),
            'q' => 'nullable|max:160',
            'page' => 'nullable|int|between:1,100000',
        ])->validated();

        $status = (string) ($query['status'] ?? 'open');
        $search = (string) ($query['q'] ?? '');
        $today = date('Y-m-d');

        $entries = new ReceivablePayable();
        $rows = [];
        $total = 0;
        $summary = null;
        $page = 1;
        $pages = 1;
        $accounts = [];
        $defaultAccountId = null;

        try {
            $accounts = (new Account())->activeOrdered();
            $defaultAccountId = (new Account())->cashAccountId();
            $summary = $entries->summary($kind, $today);
            $total = $entries->listingCount($kind, $status, $search);
            $pages = max(1, (int) ceil($total / self::PER_PAGE));
            $page = min(max(1, (int) ($query['page'] ?? 1)), $pages);
            $rows = $entries->listing($kind, $status, $search, $page, self::PER_PAGE);
        } catch (PDOException $e) {
            Logger::error('Receivables/payables register failed to load', [
                'kind' => $kind->value,
                'error' => $e->getMessage(),
            ]);
        }

        $this->view('pages/receivables-payables', [
            'title' => $kind->title(),
            'nav' => $kind->navKey(),
            'pageTitle' => $kind->title(),
            'pageMeta' => $kind->tagline(),
            'kind' => $kind,
            'rows' => $rows,
            'summary' => $summary,
            'loadFailed' => $summary === null,
            'status' => $status,
            'search' => $search,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'perPage' => self::PER_PAGE,
            'today' => $today,
            'rowFailure' => Session::flash('rowFailure'),
            'accounts' => $accounts,
            'defaultAccountId' => $defaultAccountId,
        ]);
    }

    /** @param array<string,string> $params */
    public function create(array $params): void
    {
        $kind = $this->kind($params);

        $this->view('pages/receivable-payable-form', [
            'title' => 'New ' . $kind->singular(),
            'nav' => $kind->navKey(),
            'pageTitle' => 'New ' . $kind->singular(),
            'pageMeta' => $kind->tagline(),
            'kind' => $kind,
            'entry' => null,
            'accounts' => (new Account())->activeOrdered(),
            'defaultAccountId' => (new Account())->cashAccountId(),
            'accountName' => null,
            'symbol' => Settings::string('currency_symbol', 'Rs'),
        ]);
    }

    /** @param array<string,string> $params */
    public function store(array $params): void
    {
        $kind = $this->kind($params);
        $back = $kind->path() . '/new';
        $fields = $this->entryFields($this->validate(self::ENTRY_RULES, $back));

        try {
            ReceivablePayableService::record($kind, $fields);
        } catch (ReceivablePayableRuleViolation $e) {
            $this->backWithFieldErrors($e, $back);
        } catch (InvalidArgumentException $e) {
            $this->backWithFieldErrors(new ReceivablePayableRuleViolation(['amount' => $e->getMessage()]), $back);
        }

        Session::forget('_old');
        Session::flash(
            'success',
            ucfirst($kind->singular()) . ' for ' . $fields['party_name'] . ' recorded and posted to the ledger.'
        );
        Http::redirect($kind->path());
    }

    /** @param array<string,string> $params */
    public function edit(array $params): void
    {
        $kind = $this->kind($params);
        $entry = $this->entryOr404($params, $kind);

        $refusal = ReceivablePayableRules::editErrors((string) $entry['status']);
        if ($refusal !== []) {
            Session::flash('error', (string) reset($refusal));
            Http::redirect($kind->path());
        }

        $this->view('pages/receivable-payable-form', [
            'title' => 'Edit ' . $kind->singular(),
            'nav' => $kind->navKey(),
            'pageTitle' => 'Edit ' . $kind->singular(),
            'pageMeta' => (string) $entry['party_name'],
            'kind' => $kind,
            'entry' => $entry,
            'accounts' => [],
            'defaultAccountId' => null,
            'accountName' => $entry['account_id'] === null
                ? null
                : (string) ((new Account())->find((int) $entry['account_id'])['name'] ?? ''),
            'symbol' => Settings::string('currency_symbol', 'Rs'),
        ]);
    }

    /** @param array<string,string> $params */
    public function update(array $params): void
    {
        $kind = $this->kind($params);
        $entry = $this->entryOr404($params, $kind);
        $id = (int) $entry['id'];
        $back = $kind->path() . '/' . $id . '/edit';
        $clean = $this->validate(self::EDIT_RULES, $back);

        try {
            ReceivablePayableService::update($id, $kind, [
                'party_name' => (string) $clean['party_name'],
                'reference_no' => $this->stringOrNull($clean['reference_no']),
                'description' => $this->stringOrNull($clean['description']),
                'due_date' => $this->stringOrNull($clean['due_date']),
            ], [
                'amount' => $this->stringOrNull($clean['amount']),
                'issue_date' => $this->stringOrNull($clean['issue_date']),
                'account_id' => $clean['account_id'] === null ? null : (int) $clean['account_id'],
            ]);
        } catch (ReceivablePayableRuleViolation $e) {
            if (isset($e->fieldErrors()['status'])) {
                Session::flash('error', $e->getMessage());
                Http::redirect($kind->path());
            }
            $this->backWithFieldErrors($e, $back);
        }

        Session::forget('_old');
        Session::flash('success', ucfirst($kind->singular()) . ' updated.');
        Http::redirect($kind->path());
    }

    /** @param array<string,string> $params */
    public function settle(array $params): void
    {
        $kind = $this->kind($params);
        $id = (int) $this->entryOr404($params, $kind)['id'];

        $input = Validator::make($_POST, [
            'settled_on' => 'required|ymd',
            'settlement_note' => 'nullable|max:255',
            'settlement_account_id' => 'required|int',
        ]);
        if ($input->fails()) {
            $this->backToRow($kind, $id, 'settle', $input->errors(), $_POST);
        }
        $clean = $input->validated();

        try {
            ReceivablePayableService::settle(
                $id,
                $kind,
                (string) $clean['settled_on'],
                $clean['settlement_note'] === null ? null : (string) $clean['settlement_note'],
                (int) $clean['settlement_account_id']
            );
        } catch (ReceivablePayableRuleViolation $e) {
            $this->backToRow($kind, $id, 'settle', $this->asErrorLists($e), $_POST);
        } catch (InvalidArgumentException $e) {
            $this->backToRow($kind, $id, 'settle', ['settled_on' => [$e->getMessage()]], $_POST);
        } catch (PDOException $e) {
            $this->databaseFailure($e, $kind, $id, 'settle');
        }

        Session::flash(
            'success',
            'Marked as ' . strtolower($kind->settledLabel()) . ' and posted to the ledger.'
        );
        Http::redirect($kind->path());
    }

    /** @param array<string,string> $params */
    public function cancel(array $params): void
    {
        $kind = $this->kind($params);
        $id = (int) $this->entryOr404($params, $kind)['id'];

        $input = Validator::make($_POST, ['cancel_reason' => 'required|max:255']);
        if ($input->fails()) {
            $this->backToRow($kind, $id, 'cancel', $input->errors(), $_POST);
        }

        try {
            ReceivablePayableService::cancel($id, $kind, (string) $input->validated()['cancel_reason']);
        } catch (ReceivablePayableRuleViolation $e) {
            $this->backToRow($kind, $id, 'cancel', $this->asErrorLists($e), $_POST);
        } catch (PDOException $e) {
            // Before RuntimeException, which PDOException extends: SQL error
            // text must never reach the browser.
            $this->databaseFailure($e, $kind, $id, 'cancel');
        } catch (RuntimeException $e) {
            $this->backToRow($kind, $id, 'cancel', ['cancel_reason' => [$e->getMessage()]], $_POST);
        }

        Session::flash(
            'success',
            ucfirst($kind->singular()) . ' cancelled; any ledger posting was voided. It stays listed with its reason.'
        );
        Http::redirect($kind->path());
    }

    /** @param array<string,string> $params */
    private function kind(array $params): ReceivablePayableKind
    {
        return ReceivablePayableKind::tryFrom($params['kind'] ?? '') ?? Http::abort(404);
    }

    /**
     * @param array<string,string> $params
     * @return array<string,mixed>
     */
    private function entryOr404(array $params, ReceivablePayableKind $kind): array
    {
        $id = (int) ($params['id'] ?? 0);

        return (new ReceivablePayable())->findOfKind($id, $kind) ?? Http::abort(404);
    }

    /**
     * @param array<string,mixed> $clean Validator output for ENTRY_RULES
     * @return array{party_name:string,reference_no:?string,description:?string,
     *               amount:string,issue_date:string,due_date:?string,account_id:int}
     */
    private function entryFields(array $clean): array
    {
        return [
            'party_name' => (string) $clean['party_name'],
            'reference_no' => $this->stringOrNull($clean['reference_no']),
            'description' => $this->stringOrNull($clean['description']),
            'amount' => (string) $clean['amount'],
            'issue_date' => (string) $clean['issue_date'],
            'due_date' => $this->stringOrNull($clean['due_date']),
            'account_id' => (int) $clean['account_id'],
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function backWithFieldErrors(ReceivablePayableRuleViolation $e, string $back): never
    {
        $old = $_POST;
        unset($old[Csrf::FIELD]);
        Session::set('_old', $old);
        Session::flash('errors', $this->asErrorLists($e));
        Session::flash('error', 'Please correct the highlighted fields.');
        Http::redirect($back);
    }

    /**
     * A refused settle or cancel goes back to the list with that row's form
     * reopened, its input kept and the message next to the field it is about.
     * A one-read flash, not `_old`, so the row does not stay open afterwards.
     *
     * @param array<string,list<string>> $errors
     * @param array<string,mixed>        $input
     */
    private function backToRow(
        ReceivablePayableKind $kind,
        int $id,
        string $action,
        array $errors,
        array $input
    ): never {
        $value = static fn (string $field): string => is_string($input[$field] ?? null) ? $input[$field] : '';

        Session::flash('rowFailure', [
            'id' => $id,
            'action' => $action,
            'errors' => $errors,
            'settled_on' => $value('settled_on'),
            'settlement_note' => $value('settlement_note'),
            'cancel_reason' => $value('cancel_reason'),
            'settlement_account_id' => $value('settlement_account_id'),
        ]);
        Session::flash('error', (string) (array_values($errors)[0][0] ?? 'That change was not saved.'));
        Http::redirect($kind->path());
    }

    /**
     * A database failure (lock wait timeout, deadlock, lost connection) is
     * logged with its detail and shown to the user only as a generic message:
     * SQL error text never reaches the browser (CLAUDE.md rule 11). The
     * transaction has rolled back, so nothing changed.
     */
    private function databaseFailure(PDOException $e, ReceivablePayableKind $kind, int $id, string $action): never
    {
        Logger::error('Receivables/payables change failed', [
            'kind' => $kind->value,
            'id' => $id,
            'action' => $action,
            'error' => $e->getMessage(),
        ]);
        Session::flash('error', 'That change could not be saved, and nothing was changed. Please try again.');
        Http::redirect($kind->path());
    }

    /** @return array<string,list<string>> */
    private function asErrorLists(ReceivablePayableRuleViolation $e): array
    {
        return array_map(static fn (string $message): array => [$message], $e->fieldErrors());
    }
}
