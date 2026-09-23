<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Http;
use App\Core\Session;
use App\Models\Customer;

final class CustomerController extends Controller
{
    public function index(): void
    {
        $this->view('pages/customers', [
            'title' => 'Customers',
            'nav' => 'customers',
            'pageTitle' => 'Customers',
            'pageMeta' => 'Referenced when recording income',
            'customers' => (new Customer())->allOrdered(),
        ]);
    }

    public function store(): void
    {
        $clean = $this->validate([
            'name' => 'required|max:160',
            'contact_name' => 'nullable|max:120',
            'email' => 'nullable|email|max:190',
            'phone' => 'nullable|max:40',
            'notes' => 'nullable|max:2000',
        ], '/customers');

        (new Customer())->createFor([
            'name' => $clean['name'],
            'contact_name' => $clean['contact_name'],
            'email' => $clean['email'],
            'phone' => $clean['phone'],
            'is_active' => 1,
            'notes' => $clean['notes'],
        ]);

        Session::flash('success', $clean['name'] . ' added.');
        Http::redirect('/customers');
    }

    /** @param array<string,string> $params */
    public function update(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $customers = new Customer();

        if ($customers->find($id) === null) {
            Http::abort(404);
        }

        $clean = $this->validate([
            'name' => 'required|max:160',
            'contact_name' => 'nullable|max:120',
            'email' => 'nullable|email|max:190',
            'phone' => 'nullable|max:40',
            'is_active' => 'required|in:0,1',
            'notes' => 'nullable|max:2000',
        ], '/customers');

        $customers->updateById($id, [
            'name' => $clean['name'],
            'contact_name' => $clean['contact_name'],
            'email' => $clean['email'],
            'phone' => $clean['phone'],
            'is_active' => (int) $clean['is_active'],
            'notes' => $clean['notes'],
        ]);

        Session::flash('success', 'Customer updated.');
        Http::redirect('/customers');
    }
}
