<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Http;
use App\Core\Session;
use App\Domain\TransactionType;
use App\Models\Account;
use App\Models\Partner;
use App\Services\LedgerQuery;
use App\Services\Settings;
use App\Services\TransactionService;
use InvalidArgumentException;
use RuntimeException;

/**
 * Partner contributions and withdrawals.
 *
 * Thin by design: the whole purpose of these two screens is that money moving
 * in or out on a partner's behalf is NOT revenue and NOT an operating expense.
 * It changes the bank balance and leaves the profit figure alone.
 *
 * There is no satellite table. The ledger row already carries the partner, the
 * description and a reference, so inventing one would add a join and hold
 * nothing.
 */
final class CapitalController extends Controller
{
    public function index(): void
    {
        $contributions = LedgerQuery::posted()->types([TransactionType::PartnerContribution]);
        $withdrawals = LedgerQuery::posted()->types([TransactionType::PartnerWithdrawal]);

        $partners = new Partner();

        $this->view('pages/capital', [
            'title' => 'Capital Movements',
            'nav' => 'capital',
            'pageTitle' => 'Capital Movements',
            'pageMeta' => 'Partner money in and out — never revenue, never an expense',
            'contributions' => $contributions->page(1, 50),
            'withdrawals' => $withdrawals->page(1, 50),
            'contributedTotal' => $contributions->totalAmount(),
            'withdrawnTotal' => $withdrawals->totalAmount(),
            'partners' => $partners->active(),
            'partnerTotals' => $this->perPartnerTotals(),
            'accounts' => (new Account())->allOrdered(),
        ]);
    }

    public function store(): void
    {
        $clean = $this->validate([
            'movement' => 'required|in:contribution,withdrawal',
            'partner_id' => 'required|int',
            'account_id' => 'required|int',
            'amount' => 'required|max:20',
            'transaction_date' => 'required|date',
            'description' => 'required|max:255',
            'reference_no' => 'nullable|max:80',
        ], '/capital');

        $type = $clean['movement'] === 'contribution'
            ? TransactionType::PartnerContribution
            : TransactionType::PartnerWithdrawal;

        try {
            TransactionService::post([
                'type' => $type,
                'amount' => (string) $clean['amount'],
                'account_id' => (int) $clean['account_id'],
                'partner_id' => (int) $clean['partner_id'],
                'transaction_date' => (string) $clean['transaction_date'],
                'description' => (string) $clean['description'],
                'reference_no' => $clean['reference_no'] === null ? null : (string) $clean['reference_no'],
            ]);
        } catch (InvalidArgumentException | RuntimeException $e) {
            Session::set('_old', $_POST);
            Session::flash('error', $e->getMessage());
            Http::redirect('/capital');
        }

        Session::forget('_old');

        $amount = Settings::string('currency_symbol', 'Rs')
            . ' ' . number_format((float) $clean['amount'], 2);

        Session::flash(
            'success',
            $type === TransactionType::PartnerContribution
                ? $amount . ' contribution recorded. It raises the balance but not revenue.'
                : $amount . ' withdrawal recorded. It lowers the balance but is not an expense.'
        );

        Http::redirect('/capital');
    }

    /**
     * Net capital per partner: what they have put in, less what they have
     * taken out.
     *
     * @return list<array<string,mixed>>
     */
    private function perPartnerTotals(): array
    {
        return Database::instance()->all(
            "SELECT p.id,
                    p.name,
                    COALESCE(SUM(CASE WHEN t.type = 'partner_contribution' THEN t.amount END), 0) AS contributed,
                    COALESCE(SUM(CASE WHEN t.type = 'partner_withdrawal'   THEN t.amount END), 0) AS withdrawn
             FROM partners p
             LEFT JOIN transactions t
                    ON t.partner_id = p.id
                   AND t.status = 'posted'
                   AND t.type IN ('partner_contribution', 'partner_withdrawal')
             GROUP BY p.id, p.name
             ORDER BY p.name"
        );
    }
}
