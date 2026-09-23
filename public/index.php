<?php

/**
 * Single front controller. This file and the assets beside it are the ONLY
 * things that ever need to be web-reachable.
 *
 * The application directory is deliberately NOT inside the document root, so
 * this file has to find it. Layouts it supports:
 *
 *   Local (XAMPP)      <project>/public/index.php
 *                                            -> <project>/app
 *   Hostinger site     ~/domains/<site>/public_html/index.php
 *                                            -> ~/domains/<site>/supporthive_app/app
 *   Single web root    ~/public_html/index.php
 *                                            -> ~/supporthive_app/app
 *
 * The depth differs per host, which is why this walks upwards rather than
 * checking one fixed path.
 */

declare(strict_types=1);

define('PUBLIC_PATH', __DIR__);

/** Directory name used for the application code on the host. Matches APP_DIR. */
const APP_DIRECTORY_NAME = 'supporthive_app';

$bootstrap = null;

// 1. Development layout: the app is a sibling of public/.
if (is_file(__DIR__ . '/../app/bootstrap.php')) {
    $bootstrap = __DIR__ . '/../app/bootstrap.php';
} else {
    // 2. Host layout: walk up from the document root looking for the private
    //    application directory. Three levels covers every shared-host layout
    //    without ever scanning outside the account's own home directory.
    $directory = __DIR__;

    for ($depth = 0; $depth < 3; $depth++) {
        $directory = dirname($directory);
        $candidate = $directory . '/' . APP_DIRECTORY_NAME . '/app/bootstrap.php';

        if (is_file($candidate)) {
            $bootstrap = $candidate;
            break;
        }
    }
}

if ($bootstrap === null) {
    // Deliberately vague: the visitor learns nothing about the filesystem.
    http_response_code(500);
    error_log('SupportHive: application directory not found from ' . __DIR__);
    exit('Application is temporarily unavailable.');
}

require $bootstrap;

use App\Core\Router;

$router = new Router();
require ROUTES_PATH . '/web.php';
$router->dispatch();
