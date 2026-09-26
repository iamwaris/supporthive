<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Http;
use App\Core\Session;
use App\Domain\TransactionType;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Partner;
use App\Services\DateRangePreset;
use App\Services\LedgerFilters;
use App\Services\LedgerQuery;
use App\Services\TransactionService;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

final class TransactionController extends Controller
{
    private const PER_PAGE = 25;

    /**
     * The ledger listing, with the filter set from spec §17.
     *
     * Filters come from the query string so a filtered view is a shareable URL
     * and the browser's back button behaves. Everything is paged server-side:
     * the spec is explicit that the whole table must never be loaded into the
     * browser, and at 2,000+ rows it would be unusable anyway.
     */
    public function index(): void
    {
        $filters = $this->readFilters();

        $query = $filters['status'] === 'void'
            ? LedgerQuery::includeVoided()->voidedOnly()
            : ($filters['status'] === 'all' ? LedgerQuery::includeVoided() : LedgerQuery::posted());

        $query
            ->between($filters['from'], $filters['to'])
            ->account($filters['account_id'])
            ->category($filters['category_id'])
            ->partner($filters['partner_id'])
            ->search($filters['q'])
            ->amountBetween($filters['min'], $filters['max']);

        if ($filters['type'] !== null) {
            $query->types([$filters['type']]);
        }

        $total = $query->count();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $filters['page']), $pages);

        // Money in and out are computed from the same filtered set, so the
        // summary strip can never disagree with the rows beneath it.
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

        $rows = $query->page($page, self::PER_PAGE);
        $attachments = (new Attachment())->forTransactions(
            array_map(static fn (array $row): int => (int) $row['id'], $rows)
        );

        $accounts = (new Account())->allOrdered();
        $categories = (new Category())->tree('expense');
        $incomeCategories = (new Category())->tree('income');
        $partners = (new Partner())->allOrdered();
        $types = TransactionType::options();

        $ledgerFilters = new LedgerFilters($filters, url('/transactions'));
        $chips = $ledgerFilters->chips(
            $types,
            $this->namesById($accounts),
            $this->categoryNamesById([...$categories, ...$incomeCategories]),
            $this->namesById($partners)
        );

        $this->view('pages/transactions', [
            'title' => 'All Transactions',
            'nav' => 'ledger',
            'pageTitle' => 'All Transactions',
            'pageMeta' => $total === 1 ? '1 entry' : number_format($total) . ' entries',
            'rows' => $rows,
            'attachments' => $attachments,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'perPage' => self::PER_PAGE,
            'inbound' => $inbound,
            'outbound' => $outbound,
            'net' => $query->netAmount(),
            'inShare' => $this->inShare($inbound, $outbound),
            'rangeLabel' => DateRangePreset::label($filters['from'], $filters['to']),
            'rangeOptions' => DateRangePreset::options(),
            'filters' => $filters,
            'ledgerFilters' => $ledgerFilters,
            'chips' => $chips,
            'accounts' => $accounts,
            'categories' => $categories,
            'incomeCategories' => $incomeCategories,
            'partners' => $partners,
            'types' => $types,
        ]);
    }

    /** @param array<string,string> $params */
    public function void(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $reason = trim((string) ($_POST['void_reason'] ?? ''));

        try {
            $count = TransactionService::void($id, $reason);
        } catch (InvalidArgumentException | RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            Http::redirect('/transactions');
        }

        Session::flash(
            'success',
            $count > 1
                ? 'Both legs of the transfer were voided.'
                : 'Transaction voided. It stays visible with its reason.'
        );
        Http::redirect('/transactions');
    }

    /**
     * Money in as a percentage of all money moved, for the hero's in/out bar.
     * Null when nothing moved, so the view can show an empty bar rather than 0/0.
     */
    private function inShare(string $inbound, string $outbound): ?float
    {
        $in = max(0.0, (float) $inbound);
        $moved = $in + max(0.0, (float) $outbound);

        return $moved > 0 ? min(100.0, max(0.0, round($in / $moved * 100, 1))) : null;
    }

    /**
     * @param list<array<string,mixed>> $records
     * @return array<int,string>
     */
    private function namesById(array $records): array
    {
        $names = [];
        foreach ($records as $record) {
            $names[(int) $record['id']] = (string) $record['name'];
        }

        return $names;
    }

    /**
     * Children are named "Parent · Child", matching the ledger's Category column.
     *
     * @param list<array{parent:array<string,mixed>,children:list<array<string,mixed>>}> $tree
     * @return array<int,string>
     */
    private function categoryNamesById(array $tree): array
    {
        $names = [];
        foreach ($tree as $node) {
            $parentName = (string) $node['parent']['name'];
            $names[(int) $node['parent']['id']] = $parentName;
            foreach ($node['children'] as $child) {
                $names[(int) $child['id']] = $parentName . ' · ' . (string) $child['name'];
            }
        }

        return $names;
    }

    /**
     * @return array{
     *     range:string, from:?string, to:?string, account_id:?int, category_id:?int,
     *     partner_id:?int, type:?string, status:string, q:?string,
     *     min:?string, max:?string, page:int
     * }
     */
    private function readFilters(): array
    {
        $type = isset($_GET['type']) ? trim((string) $_GET['type']) : '';
        $preset = $this->stringOrNull($_GET['range'] ?? null);
        $range = DateRangePreset::resolve(
            $preset !== null && array_key_exists($preset, DateRangePreset::options()) ? $preset : null,
            $this->stringOrNull($_GET['from'] ?? null),
            $this->stringOrNull($_GET['to'] ?? null),
            new DateTimeImmutable('today')
        );

        return [
            'range' => $range['preset'],
            'from' => $range['from'],
            'to' => $range['to'],
            'account_id' => $this->intOrNull($_GET['account_id'] ?? null),
            'category_id' => $this->intOrNull($_GET['category_id'] ?? null),
            'partner_id' => $this->intOrNull($_GET['partner_id'] ?? null),
            'type' => TransactionType::tryFrom($type) !== null ? $type : null,
            'status' => in_array($_GET['status'] ?? '', ['all', 'void'], true)
                ? (string) $_GET['status']
                : 'posted',
            'q' => $this->stringOrNull($_GET['q'] ?? null),
            'min' => $this->stringOrNull($_GET['min'] ?? null),
            'max' => $this->stringOrNull($_GET['max'] ?? null),
            'page' => max(1, (int) ($_GET['page'] ?? 1)),
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

    private function intOrNull(mixed $value): ?int
    {
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
