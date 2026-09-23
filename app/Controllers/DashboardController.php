<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;

final class DashboardController extends Controller
{
    public function index(): void
    {
        // Real figures arrive with the ledger (M3) and the KPI aggregates (M6).
        // The shell ships first so navigation, roles and layout are settled
        // before any query is written against them.
        $this->view('pages/dashboard', [
            'title' => 'Dashboard',
            'nav' => 'dashboard',
            'pageTitle' => 'Dashboard',
            'pageMeta' => date('F Y') . ' · all accounts',
        ]);
    }
}
