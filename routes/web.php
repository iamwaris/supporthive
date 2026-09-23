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

use App\Core\Http;
use App\Core\Router;

/** @var Router $router */

// --- Public ---------------------------------------------------------------
$router->get('/', static fn (): never => Http::redirect('/dashboard'));

// --- Authentication -------------------------------------------------------
$router->get('/login', 'AuthController@showLogin', ['guest']);
$router->post('/login', 'AuthController@login', ['guest']);
$router->post('/logout', 'AuthController@logout', ['auth']);

// --- Application ----------------------------------------------------------
$router->get('/dashboard', 'DashboardController@index', ['can:view']);

// --- System ---------------------------------------------------------------
$router->get('/settings', 'SettingsController@index', ['can:administer']);
$router->post('/settings', 'SettingsController@update', ['can:administer']);

// Routes land here as their modules are built (see docs/MODULES.md). They are
// deliberately absent rather than stubbed: the sidebar renders an unbuilt item
// as disabled, so nothing links into a 404.
