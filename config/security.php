<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'csrf_lifetime'        => Env::int('CSRF_TOKEN_LIFETIME', 7200),
    'password_min_length'  => Env::int('PASSWORD_MIN_LENGTH', 12),
    'login_max_attempts'   => Env::int('LOGIN_MAX_ATTEMPTS', 5),
    'login_lockout'        => Env::int('LOGIN_LOCKOUT_SECONDS', 900),

    // Password-free login buttons on the sign-in page, for testing. OFF unless
    // explicitly switched on. This is an authentication bypass: with it on,
    // anyone who can reach the login page can sign in as any active user.
    // Turning it off is one value change - no code edit, nothing to remember
    // to delete.
    'dev_quick_login'      => Env::bool('DEV_QUICK_LOGIN', false),

    // Leave false unless the app genuinely sits behind a trusted reverse proxy.
    // When true, X-Forwarded-For is believed - and it is attacker-controlled.
    'trust_proxy'          => false,
];
