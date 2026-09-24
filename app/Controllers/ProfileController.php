<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Http;
use App\Core\Session;

/**
 * The signed-in user's own profile. Just the password screen for now —
 * everything else about "my account" is future scope.
 *
 * Reachable regardless of role or active branch (Auth::requireLogin() only
 * excludes this path and /logout from the forced-password-change redirect),
 * since a super admin with no branch yet must still be able to reach it.
 */
final class ProfileController extends Controller
{
    public function password(): void
    {
        $user = Auth::user();

        $this->view('pages/account-password', [
            'title' => 'Change password',
            'nav' => '',
            'pageTitle' => 'Change password',
            'pageMeta' => (int) ($user['must_change_password'] ?? 0) === 1
                ? 'Set a new password before continuing'
                : null,
            'forced' => (int) ($user['must_change_password'] ?? 0) === 1,
            'minLength' => (int) Config::get('security.password_min_length', 12),
        ]);
    }

    public function updatePassword(): void
    {
        $minLength = (int) Config::get('security.password_min_length', 12);

        $clean = $this->validate([
            'current_password' => 'required|max:200',
            'password' => 'required|min:' . $minLength . '|max:200',
            'password_confirmation' => 'required|max:200',
        ], '/account/password');

        $userId = Auth::id();
        $hash = Database::instance()->value('SELECT password_hash FROM users WHERE id = :id', ['id' => $userId]);

        if (!is_string($hash) || !password_verify((string) $clean['current_password'], $hash)) {
            Session::flash('errors', ['current_password' => ['That is not your current password.']]);
            Session::flash('error', 'Your current password was not correct.');
            Http::redirect('/account/password');
        }

        if ($clean['password'] !== $clean['password_confirmation']) {
            Session::flash('errors', ['password_confirmation' => ['The passwords do not match.']]);
            Session::flash('error', 'The new password and confirmation do not match.');
            Http::redirect('/account/password');
        }

        Auth::updatePassword((string) $clean['password']);

        Session::flash('success', 'Password changed.');
        Http::redirect('/dashboard');
    }
}
