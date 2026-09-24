<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Http;
use App\Core\Logger;
use App\Core\Session;
use App\Models\User;
use App\Services\Access;

/**
 * In-app user management, admin only (`can:administer`), scoped to the
 * signed-in admin's own branch — App\Models\User inherits that scoping from
 * App\Core\Model the same way every other master-data model does.
 *
 * super_admin is deliberately unreachable from here: it has no branch_id, so
 * it never appears in User::allOrdered(), and Router::requireAbility()
 * already sends a branch-less session to /admin/branches before a request
 * gets this far. This screen only ever creates admin/partner logins
 * (App\Services\Access::ASSIGNABLE) — the same two roles
 * scripts/create-user.php has always been limited to.
 */
final class UserController extends Controller
{
    public function index(): void
    {
        $this->view('pages/users', [
            'title' => 'Users',
            'nav' => 'users',
            'pageTitle' => 'Users',
            'pageMeta' => 'Logins for this branch. A new user must change their password on first sign-in.',
            'users' => (new User())->allOrdered(),
            'roles' => Access::ASSIGNABLE,
        ]);
    }

    public function store(): void
    {
        $clean = $this->validate([
            'name' => 'required|max:120',
            'email' => 'required|email|max:190',
            'role' => 'required|in:' . implode(',', Access::ASSIGNABLE),
        ], '/users');

        $name = trim((string) $clean['name']);
        $email = strtolower(trim((string) $clean['email']));
        $users = new User();

        if ($users->emailExists($email)) {
            Session::set('_old', $_POST);
            Session::flash('errors', ['email' => ['That email is already in use by another account.']]);
            Session::flash('error', 'Please correct the highlighted fields.');
            Http::redirect('/users');
        }

        // A generated one-time password, shown once — the same forced-change
        // treatment scripts/create-user.php and branch creation both give
        // every account they create. Nobody but the operator ever sees it,
        // and it is never written to a log.
        $tempPassword = bin2hex(random_bytes(9));

        $id = $users->create([
            'name' => $name,
            'email' => $email,
            'password_hash' => Auth::hash($tempPassword),
            'role' => $clean['role'],
            'status' => 'active',
            'must_change_password' => 1,
        ]);

        Logger::security('User created', ['user_id' => $id, 'role' => $clean['role'], 'by' => Auth::id()]);
        Session::flash(
            'success',
            $name . ' created. Login: ' . $email . ' / temporary password: ' . $tempPassword
            . ' — this is shown once; the account must change it on first sign-in.'
        );
        Http::redirect('/users');
    }

    /** @param array<string,string> $params */
    public function update(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $users = new User();
        $target = $users->find($id);

        if ($target === null) {
            Http::abort(404);
        }

        $clean = $this->validate([
            'name' => 'required|max:120',
            'role' => 'required|in:' . implode(',', Access::ASSIGNABLE),
        ], '/users');

        if ($id === Auth::id() && $clean['role'] !== $target['role']) {
            Session::flash('error', 'You cannot change your own role.');
            Http::redirect('/users');
        }

        $users->updateById($id, ['name' => trim((string) $clean['name']), 'role' => $clean['role']]);

        Logger::security('User updated', ['user_id' => $id, 'role' => $clean['role'], 'by' => Auth::id()]);
        Session::flash('success', 'User updated.');
        Http::redirect('/users');
    }

    /**
     * Suspends or reactivates a login. A suspended account fails
     * Auth::attempt() immediately and cannot sign in again until reactivated
     * here — this is not a soft delete, nothing about the account's history
     * changes.
     *
     * @param array<string,string> $params
     */
    public function toggleStatus(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $users = new User();
        $target = $users->find($id);

        if ($target === null) {
            Http::abort(404);
        }

        if ($id === Auth::id()) {
            Session::flash('error', 'You cannot suspend your own account.');
            Http::redirect('/users');
        }

        $wasActive = (string) $target['status'] === 'active';
        $users->updateById($id, ['status' => $wasActive ? 'suspended' : 'active']);

        Logger::security($wasActive ? 'User suspended' : 'User reactivated', ['user_id' => $id, 'by' => Auth::id()]);
        Session::flash('success', (string) $target['name'] . ($wasActive ? ' suspended.' : ' reactivated.'));
        Http::redirect('/users');
    }

    /**
     * Issues a new one-time password for an account that is locked out —
     * the admin never learns or sets the account's actual password, only
     * this temporary one, and the account must change it before doing
     * anything else (Auth::requireLogin()).
     *
     * @param array<string,string> $params
     */
    public function resetPassword(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $users = new User();
        $target = $users->find($id);

        if ($target === null) {
            Http::abort(404);
        }

        if ($id === Auth::id()) {
            Session::flash('error', 'Use Account -> Change password for your own account.');
            Http::redirect('/users');
        }

        $tempPassword = bin2hex(random_bytes(9));
        $users->updateById($id, ['password_hash' => Auth::hash($tempPassword), 'must_change_password' => 1]);

        Logger::security('User password reset', ['user_id' => $id, 'by' => Auth::id()]);
        Session::flash(
            'success',
            'New temporary password for ' . (string) $target['name'] . ': ' . $tempPassword
            . ' — shown once; the account must change it on next sign-in.'
        );
        Http::redirect('/users');
    }
}
