<?php

/**
 * Route table. Every reachable URL in the application is listed here.
 *
 * Signature: $router->method($path, 'Controller@action', [middleware...])
 * Middleware: 'auth', 'guest', 'role:admin,agent'
 */

declare(strict_types=1);

use App\Core\Router;

/** @var Router $router */

// --- Public ---------------------------------------------------------------
$router->get('/', 'HomeController@index');

// --- Authentication (add controllers as the app plan lands) ---------------
// $router->get('/login',    'AuthController@showLogin',  ['guest']);
// $router->post('/login',   'AuthController@login',      ['guest']);
// $router->post('/logout',  'AuthController@logout',     ['auth']);

// --- Application ----------------------------------------------------------
// $router->get('/dashboard',      'DashboardController@index', ['auth']);
// $router->get('/tickets/{id}',   'TicketController@show',     ['auth']);
// $router->post('/admin/users',   'UserController@store',      ['auth', 'role:admin']);
