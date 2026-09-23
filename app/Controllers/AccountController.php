<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Http;
use App\Core\Logger;
use App\Core\Session;
use App\Models\Account;

final class AccountController extends Controller
{
    public function index(): void
    {
        $this->view('pages/accounts', [
            'title' => 'Accounts',
            'nav' => 'accounts',
            'pageTitle' => 'Accounts',
            'pageMeta' => 'Balances are derived from the ledger, never stored',
            'accounts' => (new Account())->allOrdered(),
            'types' => Account::TYPES,
        ]);
    }

    public function store(): void
    {
        $clean = $this->validate([
            'name' => 'required|max:120',
            'type' => 'required|in:cash,bank,credit_card,petty_cash,other',
            'opening_balance' => 'required|numeric',
            'opening_date' => 'required|date',
            'institution' => 'nullable|max:120',
            'reference' => 'nullable|max:80',
            'notes' => 'nullable|max:2000',
        ], '/accounts');

        $accounts = new Account();

        if ($accounts->nameExists((string) $clean['name'])) {
            Session::set('_old', $_POST);
            Session::flash('errors', ['name' => ['An account with that name already exists.']]);
            Session::flash('error', 'That account name is taken.');
            Http::redirect('/accounts');
        }

        $id = $accounts->createFor([
            'name' => $clean['name'],
            'type' => $clean['type'],
            // Kept as a string so the exact decimal reaches the DECIMAL column
            // without passing through a float on the way.
            'opening_balance' => (string) $clean['opening_balance'],
            'opening_date' => date('Y-m-d', (int) strtotime((string) $clean['opening_date'])),
            'institution' => $clean['institution'],
            'reference' => $clean['reference'],
            'is_active' => 1,
            'notes' => $clean['notes'],
        ]);

        Logger::info('Account created', ['account_id' => $id]);
        Session::flash('success', $clean['name'] . ' added.');
        Http::redirect('/accounts');
    }

    /** @param array<string,string> $params */
    public function update(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $accounts = new Account();

        if ($accounts->find($id) === null) {
            Http::abort(404);
        }

        $clean = $this->validate([
            'name' => 'required|max:120',
            'type' => 'required|in:cash,bank,credit_card,petty_cash,other',
            'opening_balance' => 'required|numeric',
            'opening_date' => 'required|date',
            'institution' => 'nullable|max:120',
            'reference' => 'nullable|max:80',
            'is_active' => 'required|in:0,1',
            'notes' => 'nullable|max:2000',
        ], '/accounts');

        if ($accounts->nameExists((string) $clean['name'], $id)) {
            Session::flash('error', 'Another account already uses that name.');
            Http::redirect('/accounts');
        }

        $accounts->updateById($id, [
            'name' => $clean['name'],
            'type' => $clean['type'],
            'opening_balance' => (string) $clean['opening_balance'],
            'opening_date' => date('Y-m-d', (int) strtotime((string) $clean['opening_date'])),
            'institution' => $clean['institution'],
            'reference' => $clean['reference'],
            'is_active' => (int) $clean['is_active'],
            'notes' => $clean['notes'],
        ]);

        Logger::info('Account updated', ['account_id' => $id]);
        Session::flash('success', 'Account updated.');
        Http::redirect('/accounts');
    }
}
