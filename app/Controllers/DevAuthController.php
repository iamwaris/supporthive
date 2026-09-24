<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Http;
use App\Core\Logger;
use App\Core\Session;

/**
 * Password-free login for testing.
 *
 * This is a real authentication bypass: while it is on, anyone who can reach
 * the sign-in page can become any active user, including an administrator.
 * It is enabled on the live site at the owner's explicit request, while they
 * are the only user.
 *
 * Three things make that recoverable rather than permanent:
 *
 *   1. One switch, DEV_QUICK_LOGIN, defaulting to false. Off is the default
 *      state, and turning it off is a value change rather than a code edit.
 *   2. The route is only registered while the switch is on, so with it off the
 *      URL does not exist and returns 404 - not merely a hidden button.
 *   3. preflight.php warns about it on every run in production, and every use
 *      is written to the security log with the user and role.
 *
 * MUST be switched off before anyone else has access.
 */
final class DevAuthController extends Controller
{
    /**
     * Driven by one setting, DEV_QUICK_LOGIN, which defaults to false.
     *
     * Deliberately not tied to APP_ENV: the owner wants this available on the
     * live site while they are the only user. Tying it to a single flag means
     * switching it off later is one value change and a redeploy - not a code
     * edit somebody has to remember to make.
     */
    public static function isEnabled(): bool
    {
        return Config::get('security.dev_quick_login') === true;
    }

    /**
     * Test accounts to offer. Scoped to the active branch when one is set
     * (the normal case), so the picker does not mix names across branches;
     * a super admin with no branch chosen yet sees every account, since
     * picking who to become is exactly what this screen is for them.
     *
     * @return list<array<string,mixed>>
     */
    public static function testAccounts(): array
    {
        if (!self::isEnabled()) {
            return [];
        }

        $branchId = Auth::branchId();
        $sql = "SELECT id, name, email, role FROM users WHERE status = 'active'";
        $params = [];

        if ($branchId !== null) {
            $sql .= ' AND branch_id = :branch';
            $params['branch'] = $branchId;
        }

        $sql .= " ORDER BY FIELD(role, 'super_admin', 'admin', 'partner', 'accountant', 'data_entry'), name LIMIT 8";

        return Database::instance()->all($sql, $params);
    }

    public function login(): void
    {
        if (!self::isEnabled()) {
            // Not an error page that explains itself: in production this should
            // look exactly like a URL that was never there.
            Logger::security('Dev quick-login attempted outside local', [
                'env' => (string) Config::get('app.env'),
                'ip' => Http::clientIp(),
            ]);
            Http::abort(404);
        }

        $userId = (int) ($_POST['user_id'] ?? 0);

        $user = Database::instance()->first(
            "SELECT id, name, email, role FROM users WHERE id = :id AND status = 'active' LIMIT 1",
            ['id' => $userId]
        );

        if ($user === null) {
            Session::flash('error', 'That test account no longer exists.');
            Http::redirect('/login');
        }

        // Clear any existing session first. Auth::login() regenerates the id,
        // but leaving the previous user's session data in place risks carrying
        // their flashes and cached state into the new identity.
        $previousId = Auth::id();
        if ($previousId !== null) {
            Auth::logout();
            Session::start();
        }

        Auth::login((int) $user['id']);

        Logger::security('Dev quick-login used', [
            'switched_from' => $previousId,
            'user_id' => $user['id'],
            'role' => $user['role'],
            'env' => (string) Config::get('app.env'),
        ]);

        Session::flash('success', 'Signed in as ' . (string) $user['name'] . ' (development shortcut).');
        Http::redirect('/dashboard');
    }
}
