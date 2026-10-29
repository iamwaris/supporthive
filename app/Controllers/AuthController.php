<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Http;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Core\RememberMe;
use App\Core\Session;
use App\Core\Validator;
use App\Services\Access;

final class AuthController extends Controller
{
    public function showLogin(): void
    {
        $this->view('auth/login', ['title' => 'Sign in'], 'layouts/auth');
    }

    public function login(): void
    {
        $validator = Validator::make($_POST, [
            'email' => 'required|email|max:190',
            'password' => 'required|max:200',
            // A checkbox: absent when unticked, "1" when ticked. Anything
            // else (an array, another value) fails like any other bad field.
            'remember' => 'nullable|in:1',
        ]);

        // Deliberately vague on validation failure too: "enter a valid email"
        // on a login form tells an attacker which half they got wrong.
        if ($validator->fails()) {
            $this->failLogin((string) ($_POST['email'] ?? ''), ($_POST['remember'] ?? null) === '1');
        }

        /** @var array{email:string,password:string,remember:?string} $input */
        $input = $validator->validated();
        $email = strtolower(trim($input['email']));

        $maxAttempts = (int) Config::get('security.login_max_attempts', 5);
        $lockout = (int) Config::get('security.login_lockout', 900);

        // Two keys on purpose. Per-email stops one account being ground down;
        // per-IP stops one host spraying many accounts, which a per-email
        // limit alone does nothing about.
        RateLimiter::guard('login:' . $email, $maxAttempts, $lockout);
        RateLimiter::guard('login-ip:' . Http::clientIp(), $maxAttempts * 4, $lockout);

        if (!Auth::attempt($email, $input['password'])) {
            $this->failLogin($email, $input['remember'] === '1');
        }

        RateLimiter::clear('login:' . $email);

        // Drop any token this browser still holds (a different account's, or
        // one that is no longer valid) before deciding whether to issue one.
        RememberMe::forgetCurrentDevice();
        if ($input['remember'] === '1') {
            RememberMe::remember((int) Auth::id());
        }

        Session::forget('_old');
        Http::redirect(Access::landingPath((string) (Auth::user()['role'] ?? '')));
    }

    public function logout(): void
    {
        Auth::logout();
        Session::start();
        Session::flash('success', 'You have been signed out.');
        Http::redirect('/login');
    }

    /**
     * One message and one code path for every kind of failure — unknown email,
     * wrong password, inactive account. Anything that distinguishes them turns
     * the form into an account-existence oracle.
     */
    private function failLogin(string $email, bool $remember): never
    {
        Logger::security('Login rejected', ['email_given' => $email !== '', 'ip' => Http::clientIp()]);

        Session::set('_old', ['email' => $email, 'remember' => $remember ? '1' : '']);
        Session::flash('error', 'Those details do not match an account.');
        Http::redirect('/login');
    }
}
