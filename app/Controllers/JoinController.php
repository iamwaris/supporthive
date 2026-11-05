<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Http;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Core\Session;
use App\Models\EmployeeJoinLink;
use App\Models\JoinerSubmission;
use App\Services\Audit;
use App\Services\BankDetails;

/**
 * The public "new joiner" form behind a branch's shared link, /join/{token}.
 * No sign-in: the token is the only thing that selects a branch, and every
 * way it can fail (unknown, malformed, disabled, replaced, branch locked)
 * renders the same neutral page with the same status, so the URL cannot be
 * used to learn whether a link ever existed.
 *
 * A submission creates a pending joiner_submissions row and nothing else —
 * no login, no user, no employee. Designation and salary are not fields
 * here and are never read from the request: an admin sets them on approval.
 * The Employee ID the joiner types is only a suggestion: it is checked for
 * format alone — never for uniqueness, so the form cannot be used to learn
 * which IDs exist — and it prefills the admin's approve form, where the
 * admin can change it and the uniqueness checks run.
 *
 * Abuse controls, in the order they run on POST: CSRF (Router::dispatch(),
 * before this controller), body size cap, rate limits per IP and per token,
 * honeypot, minimum time on the form, then Validator — and only validated()
 * output is stored. PHP's post_max_size is the hard ceiling on the body;
 * the cap here refuses anything far larger than this form can produce.
 */
final class JoinController extends Controller
{
    /** Generous for these fields (about 1 KB when filled in), small enough that nothing else rides along. */
    private const MAX_BODY_BYTES = 16384;

    /** A person needs longer than this to fill in the form; a script usually does not. */
    private const MIN_SECONDS_ON_FORM = 3;

    private const SUBMIT_PER_IP = 20;
    private const SUBMIT_PER_TOKEN = 100;
    private const VIEW_PER_IP = 120;
    private const WINDOW_SECONDS = 3600;

    /**
     * Not a real field; a person never sees or fills it. Named so that no
     * password manager or browser autofill recognises it ("website", "url"
     * and the like get filled for real people), and rendered autocomplete=off.
     */
    public const HONEYPOT = 'hp_contact_ref';

    /**
     * Marks the form reached through recoverLostSession(), in the URL rather
     * than the session because a lost session is the very thing it guards.
     */
    private const RECOVERED_QUERY = 'resubmit';

    private const STARTED_KEY = '_join_form_started_at';
    private const SUBMITTED_KEY = '_join_submitted';

    private const RULES = [
        'name' => 'required|max:120',
        'email' => 'required|email|max:190',
        // Same format as the admin's Employee ID (JoinerController::APPROVE_RULES).
        'employee_code' => 'required|max:30|regex:/^[A-Za-z0-9-]+$/',
        'phone' => 'nullable|max:30|regex:/^[0-9+()\s-]{7,30}$/',
        'joining_date' => 'nullable|ymd',
    ] + BankDetails::RULES;

    /** @param array<string,string> $params */
    public function show(array $params): void
    {
        Http::sendPrivatePageHeaders();
        RateLimiter::guard('join-view-ip:' . Http::clientIp(), self::VIEW_PER_IP, self::WINDOW_SECONDS);

        $token = (string) ($params['token'] ?? '');
        if (EmployeeJoinLink::activeBranchFor($token) === null) {
            $this->inactive();
        }

        // Restarted on every view, including the one after a failed submit,
        // so the timer always measures the visit that leads to the POST.
        Session::set(self::STARTED_KEY, time());

        $this->renderForm($token, isset($_GET[self::RECOVERED_QUERY]));
    }

    /** @param array<string,string> $params */
    public function submit(array $params): void
    {
        Http::sendPrivatePageHeaders();

        $token = (string) ($params['token'] ?? '');
        $this->guardSubmission($token);

        $branchId = EmployeeJoinLink::activeBranchFor($token);
        if ($branchId === null) {
            $this->inactive();
        }

        // A bot that fills every field gets the thank-you page and nothing
        // is stored, so it learns nothing about why.
        $honeypot = $_POST[self::HONEYPOT] ?? '';
        if (!is_string($honeypot) || $honeypot !== '') {
            Logger::security('Joiner form honeypot filled', ['ip' => Http::clientIp(), 'branch_id' => $branchId]);
            EmployeeJoinLink::recordBlocked($token);
            $this->finish();
        }

        $back = '/join/' . $token;
        $startedAt = Session::get(self::STARTED_KEY);
        if (!is_int($startedAt) || time() - $startedAt < self::MIN_SECONDS_ON_FORM) {
            Logger::security('Joiner form submitted too quickly', ['ip' => Http::clientIp(), 'branch_id' => $branchId]);
            EmployeeJoinLink::recordBlocked($token);
            Session::set('_old', array_diff_key($_POST, array_flip(BankDetails::NOT_FLASHED)));
            Session::flash('error', 'Please take a moment to check your details, then submit again.');
            Http::redirect($back);
        }

        $clean = $this->validateSubmission($back);

        $fields = [
            'name' => trim((string) $clean['name']),
            'email' => strtolower(trim((string) $clean['email'])),
            'employee_code' => trim((string) $clean['employee_code']),
            'phone' => $clean['phone'] === null ? null : trim((string) $clean['phone']),
            'joining_date' => $clean['joining_date'] === null ? null : (string) $clean['joining_date'],
        ];
        $bankColumns = BankDetails::from($clean);

        Database::instance()->transaction(static function () use ($branchId, $fields, $bankColumns): void {
            $id = JoinerSubmission::submit($branchId, $fields, $bankColumns, Http::clientIp());
            Audit::recordAnonymous($branchId, 'joiner.submitted', 'joiner_submissions', $id);
        });
        Logger::security('Joiner form submitted', ['branch_id' => $branchId, 'ip' => Http::clientIp()]);

        $this->finish();
    }

    /**
     * The POST's CSRF token matched no live session (Router::dispatch() runs
     * this instead of submit()). For a real joiner that is a session that
     * went away under them — a tab restored from the back/forward cache, a
     * page cache replaying someone else's GET, a cookie set on the other
     * host — so a dead-end 419 throws their typing away for nothing.
     *
     * Nothing is stored and CSRF is still enforced: the visitor is sent back
     * to a fresh GET of the form (new session, token and timer) with the safe
     * fields refilled. The account number and IBAN are never kept, as after
     * any failed submit. The round trip counts against the same submit rate
     * limits, and the GET against the view limit.
     *
     * It cannot loop: the redirect is a GET, which CSRF never checks, and it
     * carries ?resubmit=1 into the form's action. Should that form fail CSRF
     * too (the browser is not keeping the cookie at all), this returns and
     * Csrf::check() ends the request in the neutral timed-out page instead
     * of bouncing again.
     *
     * @param array<string,string> $params
     */
    public function recoverLostSession(array $params): void
    {
        Http::sendPrivatePageHeaders();

        $token = (string) ($params['token'] ?? '');
        $this->guardSubmission($token);

        // A dead link must never read as "timed out", or the page would
        // tell a guesser the link once existed.
        if (EmployeeJoinLink::activeBranchFor($token) === null) {
            $this->inactive();
        }

        if (isset($_GET[self::RECOVERED_QUERY])) {
            return;
        }

        Session::set('_old', $this->safeInputToKeep());
        Session::flash(
            'error',
            'Your form timed out before it was sent — nothing was saved. '
            . 'Please check your details and press Submit again.'
        );
        Http::redirect('/join/' . $token . '?' . self::RECOVERED_QUERY . '=1', 303);
    }

    /**
     * The thank-you page, once, right after a submission from this browser.
     * Anyone else asking for it gets the neutral page.
     */
    public function submitted(): void
    {
        Http::sendPrivatePageHeaders();

        if (Session::get(self::SUBMITTED_KEY) !== true) {
            $this->inactive();
        }
        Session::forget(self::SUBMITTED_KEY);

        $this->message(
            200,
            'Thank you — your details have been sent',
            'Someone from the team will review them and be in touch. You can close this page now.'
        );
    }

    /**
     * Validator over exactly the fields this form has: the personal fields
     * and the suggested Employee ID as typed, the bank fields normalised.
     * Nothing else in $_POST — a smuggled designation, salary, branch or
     * status included — reaches it.
     *
     * @return array<string,mixed>
     */
    private function validateSubmission(string $back): array
    {
        $input = [];
        foreach (['name', 'email', 'employee_code', 'phone', 'joining_date'] as $field) {
            $input[$field] = $_POST[$field] ?? null;
        }
        $input = BankDetails::normaliseInput($_POST) + $input;

        return $this->validate(self::RULES, $back, $input, array_merge(BankDetails::NOT_FLASHED, [self::HONEYPOT]));
    }

    /** Body size cap, then the per-IP and per-token submit limits; any POST to the form counts. */
    private function guardSubmission(string $token): void
    {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > self::MAX_BODY_BYTES) {
            Logger::security('Joiner form rejected: body too large', ['ip' => Http::clientIp()]);
            $this->message(413, 'That was too much to send', 'Please check your details and try again.');
        }

        RateLimiter::guard('join-submit-ip:' . Http::clientIp(), self::SUBMIT_PER_IP, self::WINDOW_SECONDS);
        RateLimiter::guard(
            'join-submit-token:' . EmployeeJoinLink::hash($token),
            self::SUBMIT_PER_TOKEN,
            self::WINDOW_SECONDS
        );
    }

    /**
     * The form's own fields as typed, for old() after a lost session: an
     * allow-list, so the account number, IBAN, honeypot, CSRF token and
     * anything smuggled in stay out of the session, and only strings.
     *
     * @return array<string,string>
     */
    private function safeInputToKeep(): array
    {
        $kept = [];
        foreach (array_diff(array_keys(self::RULES), BankDetails::NOT_FLASHED) as $field) {
            $value = $_POST[$field] ?? null;
            if (is_string($value)) {
                $kept[$field] = $value;
            }
        }

        return $kept;
    }

    private function finish(): never
    {
        Session::forget(self::STARTED_KEY);
        Session::forget('_old');
        Session::set(self::SUBMITTED_KEY, true);
        Http::redirect('/join/submitted');
    }

    private function renderForm(string $token, bool $recovered = false): void
    {
        $this->view('pages/join/form', [
            'title' => 'Joining form',
            'action' => '/join/' . $token . ($recovered ? '?' . self::RECOVERED_QUERY . '=1' : ''),
            'honeypot' => self::HONEYPOT,
        ], 'layouts/public');
    }

    /**
     * One page for every token that does not open the form. Same status and
     * wording whatever the reason, so it says nothing about the link.
     */
    private function inactive(): never
    {
        $this->message(404, 'This link is no longer active', 'Please ask the person who sent it to you for a new one.');
    }

    private function message(int $status, string $heading, string $text): never
    {
        http_response_code($status);
        $this->view('pages/join/message', [
            'title' => $heading,
            'heading' => $heading,
            'text' => $text,
        ], 'layouts/public');
        exit;
    }
}
