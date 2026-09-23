<?php

declare(strict_types=1);

use App\Core\Env;

$schema = Env::get('DB_NAME', '');

// PHPUnit runs against a SEPARATE schema, set by tests/bootstrap.php.
//
// Without this the suite shares the development database, which fails both
// ways: real data makes tests fail (a partner added by hand broke four share
// tests), and tests mutate data someone is using. Neither is acceptable for a
// suite that is supposed to tell the truth about the code.
if (getenv('LEDGERHIVE_TESTING') === '1') {
    $schema = Env::get('DB_NAME_TEST', $schema . '_test');
}

return [
    'host'    => Env::get('DB_HOST', '127.0.0.1'),
    'port'    => Env::int('DB_PORT', 3306),
    'name'    => $schema,
    'user'    => Env::get('DB_USER', ''),
    'pass'    => Env::get('DB_PASS', ''),
    'charset' => Env::get('DB_CHARSET', 'utf8mb4'),
];
