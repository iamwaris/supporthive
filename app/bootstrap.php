<?php

/**
 * Application bootstrap. Loaded by public/index.php and by CLI scripts.
 * Order matters: errors -> env -> config -> autoload -> session/headers.
 */

declare(strict_types=1);

define('APP_START', microtime(true));
define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
define('CONFIG_PATH', BASE_PATH . '/config');
define('ROUTES_PATH', BASE_PATH . '/routes');
define('STORAGE_PATH', BASE_PATH . '/storage');

// PUBLIC_PATH is resolved below, after .env is available: over the web
// public/index.php has already defined it, but a CLI script cannot know where
// the document root is when the deployed layout splits it from BASE_PATH.

// Fail loudly during bootstrap; ErrorHandler takes over as soon as it is registered.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', STORAGE_PATH . '/logs/php-error.log');

// --- Autoloading -----------------------------------------------------------
// Composer is a dev-tool dependency only; the app must boot without vendor/.
if (is_file(BASE_PATH . '/vendor/autoload.php')) {
    require BASE_PATH . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        if (!str_starts_with($class, 'App\\')) {
            return;
        }
        $relative = str_replace('\\', '/', substr($class, 4));
        $file = APP_PATH . '/' . $relative . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}

require APP_PATH . '/Helpers/functions.php';

use App\Core\Config;
use App\Core\Env;
use App\Core\ErrorHandler;
use App\Core\Http;
use App\Core\Session;

Env::load(BASE_PATH . '/.env');
Config::load(CONFIG_PATH);

// Resolve the document root now that .env has been read.
//   - Over the web, public/index.php already defined it from __DIR__.
//   - On the host the application lives outside the web root, so a CLI script
//     (preflight, maintenance tasks) has no way to infer it: the deploy writes
//     APP_PUBLIC_PATH into .env for exactly this case.
//   - Locally it is simply BASE_PATH/public.
if (!defined('PUBLIC_PATH')) {
    $configuredPublicPath = Env::get('APP_PUBLIC_PATH', '');
    define(
        'PUBLIC_PATH',
        $configuredPublicPath !== '' && is_dir($configuredPublicPath)
            ? rtrim($configuredPublicPath, '/\\')
            : BASE_PATH . '/public'
    );
}

date_default_timezone_set(Config::get('app.timezone', 'UTC'));
mb_internal_encoding('UTF-8');

ErrorHandler::register(Config::get('app.debug', false));

if (PHP_SAPI !== 'cli') {
    Http::sendSecurityHeaders();
    Session::start();
}
