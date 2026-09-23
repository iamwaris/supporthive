<?php

/**
 * Pre-deployment / post-deployment sanity check.
 *
 * Run: php scripts/preflight.php
 * Every FAIL must be fixed before the app is exposed to the internet.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $remedy = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  PASS  {$label}\n";
        return;
    }
    $fail++;
    echo "  FAIL  {$label}" . ($remedy !== '' ? " -> {$remedy}" : '') . "\n";
}

$isProduction = Config::isProduction();

echo "Environment\n";
check('PHP 8.2 or newer', PHP_VERSION_ID >= 80200, 'upgrade PHP on the host');
check('APP_KEY is set', (string) Config::get('app.key', '') !== '', 'php scripts/genkey.php');
check('APP_URL is set', (string) Config::get('app.url', '') !== '', 'set APP_URL in .env');
check('pdo_mysql loaded', extension_loaded('pdo_mysql'));
check('mbstring loaded', extension_loaded('mbstring'));
check('fileinfo loaded', extension_loaded('fileinfo'), 'needed for upload MIME detection');
check('openssl loaded', extension_loaded('openssl'));

echo "\nSecurity\n";
check('display_errors off', ini_get('display_errors') === '0' || ini_get('display_errors') === '');
check('expose_php off', ini_get('expose_php') !== '1', 'set expose_php=Off in php.ini');
check('allow_url_include off', ini_get('allow_url_include') !== '1');
check('session.cookie_httponly on', ini_get('session.cookie_httponly') === '1');
if ($isProduction) {
    check('APP_DEBUG is false in production', Config::get('app.debug') === false, 'set APP_DEBUG=false');
    check('SESSION_SECURE is true in production', Config::get('session.secure') === true, 'set SESSION_SECURE=true');
}

echo "\nFilesystem\n";
check('storage/logs writable', is_writable(STORAGE_PATH . '/logs'), 'chmod 750 storage/logs');
check('public/uploads writable', is_writable(PUBLIC_PATH . '/uploads'), 'chmod 755 public/uploads');
check('uploads execution blocked', is_file(PUBLIC_PATH . '/uploads/.htaccess'), 'restore public/uploads/.htaccess');
check('.env not inside the web root', !is_file(PUBLIC_PATH . '/.env'), 'move .env above the web root immediately');
check('compiled CSS present', is_file(PUBLIC_PATH . '/assets/css/app.css'), 'npm run build');

echo "\nDatabase\n";
try {
    Database::instance()->value('SELECT 1');
    check('connection', true);
    $tables = Database::instance()->all('SHOW TABLES');
    check('migrations applied', $tables !== [], 'php database/migrate.php');
} catch (Throwable $e) {
    check('connection', false, 'check DB_* values in .env');
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
