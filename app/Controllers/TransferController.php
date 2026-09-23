<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Http;
use App\Core\Session;
use App\Domain\TransactionType;
use App\Models\Account;
use App\Services\LedgerQuery;
use App\Services\TransactionService;
use InvalidArgumentException;
use RuntimeException;

final class TransferController extends Controller
{
    public function index(): void
    {
        $accounts = (new Account())->allOrdered();

        // Balances are derived per account rather than stored, so the figures
        // beside each name are always the ledger's own answer.
        $balances = [];
        foreach ($accounts as $account) {
            $balances[(int) $account['id']] = TransactionService::accountBalance((int) $account['id']);
        }

        $this->view('pages/transfers', [
            'title' => 'Transfers',
            'nav' => 'transfers',
            'pageTitle' => 'Transfers',
            'pageMeta' => 'Moving money between your own accounts — never income or expense',
            'accounts' => $accounts,
            'balances' => $balances,
            'rows' => LedgerQuery::posted()
                ->types([TransactionType::TransferOut])
                ->page(1, 25),
        ]);
    }

    public function store(): void
    {
        $clean = $this->validate([
            'from_account_id' => 'required|int',
            'to_account_id' => 'required|int',
            'amount' => 'required|max:20',
            'transaction_date' => 'required|date',
            'description' => 'required|max:255',
            'reference_no' => 'nullable|max:80',
        ], '/transfers');

        try {
            TransactionService::postTransfer(
                (int) $clean['from_account_id'],
                (int) $clean['to_account_id'],
                (string) $clean['amount'],
                (string) $clean['transaction_date'],
                (string) $clean['description'],
                $clean['reference_no'] === null ? null : (string) $clean['reference_no']
            );
        } catch (InvalidArgumentException | RuntimeException $e) {
            Session::set('_old', $_POST);
            Session::flash('error', $e->getMessage());
            Http::redirect('/transfers');
        }

        Session::forget('_old');
        Session::flash('success', 'Transfer recorded as two linked legs. Neither affects profit.');
        Http::redirect('/transfers');
    }
}
