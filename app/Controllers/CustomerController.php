<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Http;
use App\Core\Session;
use App\Models\Customer;
use App\Services\Audit;

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

        $customerData = [
            'name' => $clean['name'],
            'contact_name' => $clean['contact_name'],
            'email' => $clean['email'],
            'phone' => $clean['phone'],
            'is_active' => 1,
            'notes' => $clean['notes'],
        ];
        $id = (new Customer())->createFor($customerData);
        Audit::record('customer.created', 'customers', $id, null, $customerData);

        Session::flash('success', $clean['name'] . ' added.');
        Http::redirect('/customers');
    }

    /** @param array<string,string> $params */
    public function update(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $customers = new Customer();
        $before = $customers->find($id);

        if ($before === null) {
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

        $after = [
            'name' => $clean['name'],
            'contact_name' => $clean['contact_name'],
            'email' => $clean['email'],
            'phone' => $clean['phone'],
            'is_active' => (int) $clean['is_active'],
            'notes' => $clean['notes'],
        ];
        $customers->updateById($id, $after);

        $diff = Audit::diff($before, $after);
        Audit::record('customer.updated', 'customers', $id, $diff['before'], $diff['after']);

        Session::flash('success', 'Customer updated.');
        Http::redirect('/customers');
    }
}
