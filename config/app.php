<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'name'     => Env::get('APP_NAME', 'LedgerHive'),
    'env'      => Env::get('APP_ENV', 'production'),
    // Debug defaults to OFF. A missing or malformed .env must never expose traces.
    'debug'    => Env::bool('APP_DEBUG', false),
    'url'      => rtrim((string) Env::get('APP_URL', ''), '/'),
    'key'      => Env::get('APP_KEY', ''),
    // Encrypts bank details at rest (App\Core\SecretBox). Separate from APP_KEY on purpose.
    'encryption_key' => Env::get('APP_ENCRYPTION_KEY', ''),
    'timezone' => Env::get('APP_TIMEZONE', 'UTC'),
];
