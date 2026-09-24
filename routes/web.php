<?php

/**
 * Route table. Every reachable URL in the application is listed here.
 *
 * Signature: $router->method($path, 'Controller@action', [middleware...])
 * Middleware:
 *   auth              signed in
 *   guest             signed out only
 *   can:<ability>     checked against App\Services\Access (unit tested)
 *                     write | master | administer | distribute | view
 */

declare(strict_types=1);

use App\Controllers\DevAuthController;
use App\Core\Http;
use App\Core\Router;

/** @var Router $router */

// --- Public ---------------------------------------------------------------
$router->get('/', static fn (): never => Http::redirect('/dashboard'));

// --- Authentication -------------------------------------------------------
$router->get('/login', 'AuthController@showLogin', ['guest']);
$router->post('/login', 'AuthController@login', ['guest']);
$router->post('/logout', 'AuthController@logout', ['auth']);

// Reachable regardless of role or active branch — Auth::requireLogin()
// excludes this path from the forced-password-change redirect, since a
// super admin with no branch yet must still be able to reach it.
$router->get('/account/password', 'ProfileController@password', ['auth']);
$router->post('/account/password', 'ProfileController@updatePassword', ['auth']);

// --- Quick login (testing) ------------------------------------------------
// Registered ONLY while DEV_QUICK_LOGIN is on, so with the switch off this URL
// does not exist and returns 404 rather than merely hiding its button. The
// controller checks the same switch again.
if (DevAuthController::isEnabled()) {
    // Deliberately NOT ['guest']: these buttons exist to switch between test
    // accounts, and the guest gate turned a click while signed in into a
    // silent redirect that kept you as the user you already were.
    $router->post('/dev-login', 'DevAuthController@login');
}

// --- Application ----------------------------------------------------------
$router->get('/dashboard', 'DashboardController@index', ['can:view']);
$router->get('/dashboard/charts/trend', 'DashboardController@trendChart', ['can:view']);
$router->get('/dashboard/charts/category', 'DashboardController@categoryChart', ['can:view']);
$router->get('/dashboard/charts/budget', 'DashboardController@budgetChart', ['can:view']);

// --- System ---------------------------------------------------------------
$router->get('/settings', 'SettingsController@index', ['can:administer']);
$router->post('/settings', 'SettingsController@update', ['can:administer']);

// --- Master data (M2) -----------------------------------------------------
// Reading is open to anyone who may see financials; every write needs the
// master-data ability, which today means admin only.
$router->get('/partners', 'PartnerController@index', ['can:view']);
$router->post('/partners', 'PartnerController@store', ['can:master']);
$router->post('/partners/shares', 'PartnerController@activateShares', ['can:master']);
$router->post('/partners/{id}', 'PartnerController@update', ['can:master']);

$router->get('/categories', 'CategoryController@index', ['can:view']);
$router->post('/categories', 'CategoryController@store', ['can:master']);
$router->post('/categories/{id}/toggle', 'CategoryController@toggle', ['can:master']);

$router->get('/accounts', 'AccountController@index', ['can:view']);
$router->post('/accounts', 'AccountController@store', ['can:master']);
$router->post('/accounts/{id}', 'AccountController@update', ['can:master']);

$router->get('/customers', 'CustomerController@index', ['can:view']);
$router->post('/customers', 'CustomerController@store', ['can:master']);
$router->post('/customers/{id}', 'CustomerController@update', ['can:master']);

// --- Ledger (M3) ----------------------------------------------------------
// Reading is open to anyone who may see financials. Posting and voiding need
// the write ability, which today means admin only.
$router->get('/transactions', 'TransactionController@index', ['can:view']);
$router->post('/transactions/{id}/void', 'TransactionController@void', ['can:write']);
$router->post('/transactions/{id}/documents', 'DocumentController@store', ['can:write']);

// --- Attachments (M7) -------------------------------------------------------
// Receipts, invoices, payment proofs. Streamed by an authenticated controller
// from storage/documents, never from a public path (decision D-4).
$router->get('/documents/{id}', 'DocumentController@show', ['can:view']);

$router->get('/transfers', 'TransferController@index', ['can:view']);
$router->post('/transfers', 'TransferController@store', ['can:write']);

// --- Daily entry (M4) -----------------------------------------------------
$router->get('/expenses', 'ExpenseController@index', ['can:view']);
$router->get('/expenses/new', 'ExpenseController@create', ['can:write']);
$router->post('/expenses', 'ExpenseController@store', ['can:write']);
// Type-ahead over previously used vendor names (decision D-5).
$router->get('/expenses/vendors', 'ExpenseController@vendors', ['can:write']);

$router->get('/income', 'IncomeController@index', ['can:view']);
$router->get('/income/new', 'IncomeController@create', ['can:write']);
$router->post('/income', 'IncomeController@store', ['can:write']);
$router->post('/income/{id}/received', 'IncomeController@markReceived', ['can:write']);

$router->get('/capital', 'CapitalController@index', ['can:view']);
$router->post('/capital', 'CapitalController@store', ['can:write']);

// --- Budgets & profit distribution (M5) ------------------------------------
$router->get('/budgets', 'BudgetController@index', ['can:view']);
$router->post('/budgets', 'BudgetController@store', ['can:master']);
$router->post('/budgets/{id}', 'BudgetController@update', ['can:master']);

// Reading a distribution batch is financial data; calculating, approving and
// paying one out needs the distribute ability, which today means admin only.
$router->get('/profit-distributions', 'ProfitDistributionController@index', ['can:view']);
$router->post('/profit-distributions', 'ProfitDistributionController@calculate', ['can:distribute']);
$router->post('/profit-distributions/{batch}/approve', 'ProfitDistributionController@approve', ['can:distribute']);
$router->post(
    '/profit-distributions/{batch}/distribute',
    'ProfitDistributionController@distribute',
    ['can:distribute']
);

// --- Reports (M6) -----------------------------------------------------------
// Read-only, spec §16: same visibility as the dashboard, everything through
// the existing aggregation layer (LedgerQuery / BudgetService /
// ProfitDistributionService / TransactionService::accountBalance()).
$router->get('/reports', 'ReportController@index', ['can:view']);
$router->get('/reports/profit-loss', 'ReportController@profitLoss', ['can:view']);
$router->get('/reports/income', 'ReportController@income', ['can:view']);
$router->get('/reports/expenses', 'ReportController@expenses', ['can:view']);
$router->get('/reports/expense-by-category', 'ReportController@expenseByCategory', ['can:view']);
$router->get('/reports/budget-vs-actual', 'ReportController@budgetVsActual', ['can:view']);
$router->get('/reports/cash-flow', 'ReportController@cashFlow', ['can:view']);
$router->get('/reports/account-balances', 'ReportController@accountBalances', ['can:view']);
$router->get('/reports/partner-statement', 'ReportController@partnerStatement', ['can:view']);
$router->get('/reports/partner-contributions', 'ReportController@partnerContributions', ['can:view']);
$router->get('/reports/partner-withdrawals', 'ReportController@partnerWithdrawals', ['can:view']);
$router->get('/reports/profit-distribution', 'ReportController@profitDistribution', ['can:view']);
$router->get('/reports/daily-transactions', 'ReportController@dailyTransactions', ['can:view']);

// --- Branch administration (multi-branch retrofit) --------------------------
// Super admin only, and deliberately NOT gated by can:view/etc — those all
// require an active branch (Router::requireAbility() -> requireActiveBranch())
// and super admin never has one (decision 2026-09-26: manages branches, never
// operates inside one). role:super_admin fails closed on every other role the
// same way can:* does.
$router->get('/admin/branches', 'BranchController@index', ['role:super_admin']);
$router->post('/admin/branches', 'BranchController@store', ['role:super_admin']);
$router->post('/admin/branches/{id}/toggle', 'BranchController@toggleActive', ['role:super_admin']);
$router->post('/admin/branches/{id}/delete', 'BranchController@destroy', ['role:super_admin']);

// Later modules land here as they are built (see docs/MODULES.md). They are
// deliberately absent rather than stubbed: the sidebar renders an unbuilt item
// as disabled, so nothing links into a 404.
