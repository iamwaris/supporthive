<?php

declare(strict_types=1);

use App\Core\Env;

$port = Env::int('MAIL_PORT', 587);
$encryption = strtolower(trim((string) Env::get('MAIL_ENCRYPTION', '')));
$fromAddress = trim((string) Env::get('MAIL_FROM', ''));
$fromName = (string) Env::get('MAIL_FROM_NAME', '');

return [
    // Blank host = mail is off: the app logs what it would have sent and
    // carries on, so a fresh install works before anyone sets up SMTP.
    'host'       => trim((string) Env::get('MAIL_HOST', '')),
    'port'       => $port,
    'username'   => (string) Env::get('MAIL_USER', ''),
    'password'   => (string) Env::get('MAIL_PASS', ''),
    // tls = implicit TLS (465), starttls = upgrade after connect (587),
    // none = plaintext, only for a local catcher; credentials are refused on it.
    'encryption' => $encryption !== '' ? $encryption : ($port === 465 ? 'tls' : 'starttls'),
    'from'       => $fromAddress !== '' ? $fromAddress : 'no-reply@example.com',
    'from_name'  => $fromName !== '' ? $fromName : (string) Env::get('APP_NAME', 'LedgerHive'),
    'timeout'    => Env::int('MAIL_TIMEOUT', 10),
];
