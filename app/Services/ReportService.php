<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Domain\TransactionType;
use App\Models\Account;
use RuntimeException;

/**
 * Aggregation for the reports screens, spec §16.
 *
 * Every figure here is built on the same layer the rest of the app already
 * uses to read the ledger — LedgerQuery, BudgetService, ProfitDistributionService,
 * TransactionService::accountBalance() — never a hand-written SUM(). This
 * class only adds the handful of groupings those don't already expose:
 * profit and loss and cash-flow summaries, dated account balances, and
 * partner-grouped totals.
 */
final class ReportService
{
    /**
     * Profit & loss for a period: revenue, expenses, net, and the expense mix.
     *
     * @return array{revenue:string,expenses:string,net:string,byCategory:list<array<string,mixed>>}
     */
    public static function profitLoss(string $from, string $to): array
    {
        return [
            'revenue' => LedgerQuery::posted()->revenueOnly()->between($from, $to)->totalAmount(),
            'expenses' => LedgerQuery::posted()->expensesOnly()->between($from, $to)->totalAmount(),
            'net' => LedgerQuery::posted()->profitAndLossOnly()->between($from, $to)->netAmount(),
            'byCategory' => LedgerQuery::posted()->expensesOnly()->between($from, $to)->groupedByCategory(),
        ];
    }

    /**
     * Cash flow for a period: money in/out across every transaction type,
     * plus pending income shown separately (decision D-2 — never counted as
     * cash received).
     *
     * @return array{inbound:string,outbound:string,net:string,pendingIncome:string}
     */
    public static function cashFlow(string $from, string $to): array
    {
        $inbound = LedgerQuery::posted()->between($from, $to)->types([
            TransactionType::Income,
            TransactionType::TransferIn,
            TransactionType::PartnerContribution,
        ])->totalAmount();

        $outbound = LedgerQuery::posted()->between($from, $to)->types([
            TransactionType::Expense,
            TransactionType::TransferOut,
            TransactionType::PartnerWithdrawal,
            TransactionType::ProfitDistribution,
        ])->totalAmount();

        return [
            'inbound' => $inbound,
            'outbound' => $outbound,
            'net' => number_format((float) $inbound - (float) $outbound, 2, '.', ''),
            'pendingIncome' => LedgerQuery::includeVoided()->pendingOnly()->revenueOnly()
                ->between($from, $to)->totalAmount(),
        ];
    }

    /**
     * Every active account's derived balance as of a date (defaults to now).
     *
     * @return array{total:string,accounts:list<array<string,mixed>>}
     */
    public static function accountBalancesAsOf(?string $asOf): array
    {
        $accounts = array_values(array_filter(
            (new Account())->allOrdered(),
            static fn (array $a): bool => (int) $a['is_active'] === 1
        ));

        $total = 0.0;
        $rows = [];

        foreach ($accounts as $account) {
            $balance = TransactionService::accountBalance((int) $account['id'], $asOf);
            $total += (float) $balance;
            $rows[] = $account + ['balance' => $balance];
        }

        return ['total' => number_format($total, 2, '.', ''), 'accounts' => $rows];
    }

    /**
     * One partner's contributions, withdrawals and distributions for a
     * period, with a running balance and the opening balance carried in from
     * everything posted before $from.
     *
     * @return array{opening:string,rows:list<array<string,mixed>>,closing:string}
     */
    public static function partnerStatement(int $partnerId, string $from, string $to): array
    {
        $opening = LedgerQuery::posted()
            ->partner($partnerId)
            ->between(null, date('Y-m-d', strtotime($from . ' -1 day')))
            ->netAmount();

        $rows = LedgerQuery::posted()
            ->partner($partnerId)
            ->between($from, $to)
            ->orderBy('date', 'ASC')
            ->page(1, 200);

        $running = (float) $opening;
        foreach ($rows as &$row) {
            $running += (int) $row['direction'] === 1 ? (float) $row['amount'] : -(float) $row['amount'];
            $row['running_balance'] = number_format($running, 2, '.', '');
        }
        unset($row);

        return [
            'opening' => $opening,
            'rows' => $rows,
            'closing' => number_format($running, 2, '.', ''),
        ];
    }

    /**
     * Contributions or withdrawals for a period, grouped by partner — the
     * same shape as LedgerQuery::groupedByCategory() but grouped by partner.
     *
     * @return list<array<string,mixed>>
     */
    public static function totalsByPartner(TransactionType $type, string $from, string $to): array
    {
        $branchId = Auth::branchId();
        if ($branchId === null) {
            throw new RuntimeException('No active branch — cannot read reports.');
        }

        return Database::instance()->all(
            'SELECT p.id AS partner_id, p.name AS partner_name,
                    SUM(t.amount) AS total, COUNT(*) AS entries
             FROM transactions t
             JOIN partners p ON p.id = t.partner_id
             WHERE t.branch_id = :branch AND t.status = \'posted\' AND t.type = :type
               AND COALESCE(t.received_at, t.transaction_date) >= :from
               AND COALESCE(t.received_at, t.transaction_date) <= :to
             GROUP BY p.id, p.name
             ORDER BY total DESC',
            ['branch' => $branchId, 'type' => $type->value, 'from' => $from, 'to' => $to]
        );
    }
}
