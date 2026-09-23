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

// Later modules land here as they are built (see docs/MODULES.md). They are
// deliberately absent rather than stubbed: the sidebar renders an unbuilt item
// as disabled, so nothing links into a 404.
