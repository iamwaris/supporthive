<?php

/**
 * Single front controller. This file and the assets beside it are the ONLY
 * things that ever need to be web-reachable.
 *
 * On shared hosting where public/ is the document root, BASE_PATH resolution
 * below also finds an application directory placed one level above the web root.
 */

declare(strict_types=1);

define('PUBLIC_PATH', __DIR__);

// Local (XAMPP): the app sits one level up. cPanel: public/ IS public_html and
// the application was deployed to a sibling directory outside the web root.
$bootstrap = is_file(__DIR__ . '/../app/bootstrap.php')
    ? __DIR__ . '/../app/bootstrap.php'
    : __DIR__ . '/../supporthive_app/app/bootstrap.php';

if (!is_file($bootstrap)) {
    http_response_code(500);
    exit('Application not deployed correctly.');
}

require $bootstrap;

use App\Core\Router;

$router = new Router();
require ROUTES_PATH . '/web.php';
$router->dispatch();
