<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'name'     => Env::get('SESSION_NAME', 'shsid'),
    'lifetime' => Env::int('SESSION_LIFETIME', 7200),
    'secure'   => Env::bool('SESSION_SECURE', false),
    'samesite' => Env::get('SESSION_SAMESITE', 'Lax'),
];
