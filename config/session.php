<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'name'     => Env::get('SESSION_NAME', 'shsid'),
    'lifetime' => Env::int('SESSION_LIFETIME', 7200),
    'secure'   => Env::bool('SESSION_SECURE', false),
    'samesite' => Env::get('SESSION_SAMESITE', 'Lax'),
    // "Remember me on this device" (App\Core\RememberMe). Each use re-issues
    // the token for another full period, so this is the longest a device can
    // sit unused and still be signed back in.
    'remember_days' => Env::int('REMEMBER_ME_DAYS', 30),
];
