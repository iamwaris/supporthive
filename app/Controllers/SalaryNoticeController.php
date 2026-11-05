<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Http;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Core\Session;
use App\Domain\PayrollLineStatus;
use App\Models\PayrollRunLine;
use App\Services\Audit;
use App\Services\Payroll\PayrollNoticeSender;

/**
 * One employee's salary notice behind their private link,
 * /salary-notice/{token} (PAY-1). No sign-in: the token selects exactly one
 * line, and the page shows that line's figures, screenshot and the month's
 * note and nothing else. Every way a token can fail (unknown, malformed,
 * replaced by a later send, expired, branch locked) renders one neutral
 * page with one status, as the joiner form does.
 *
 * The employee responds once per send — Confirm (typing their full name)
 * or a query with a comment — and the answer is final: the update applies
 * only while the line is still awaiting a response under this token.
 * Every request is rate-limited per IP and per token; POSTs are
 * CSRF-checked in Router::dispatch().
 */
final class SalaryNoticeController extends Controller
{
    private const MAX_BODY_BYTES = 16384;

    private const VIEW_PER_IP = 120;
    private const VIEW_PER_TOKEN = 60;
    private const RESPOND_PER_IP = 20;
    private const RESPOND_PER_TOKEN = 10;
    private const WINDOW_SECONDS = 3600;

    /** @param array<string,string> $params */
    public function show(array $params): void
    {
        $token = (string) ($params['token'] ?? '');
        $line = $this->guardView($token);

        $this->view('pages/salary-notice/show', [
            'title' => 'Salary notice',
            'line' => $line,
            'status' => PayrollLineStatus::from((string) $line['status']),
            'monthLabel' => PayrollNoticeSender::monthLabel((string) $line['period_month']),
            'base' => '/salary-notice/' . $token,
        ], 'layouts/public');
    }

    /** @param array<string,string> $params */
    public function screenshot(array $params): never
    {
        $line = $this->guardView((string) ($params['token'] ?? ''));
        PayrollController::streamScreenshot($line);
    }

    /** @param array<string,string> $params */
    public function confirm(array $params): void
    {
        $token = (string) ($params['token'] ?? '');
        $line = $this->guardResponse($token);
        $clean = $this->validate(
            ['signed_name' => 'required|min:2|max:120'],
            '/salary-notice/' . $token . '#confirm'
        );

        $this->record($token, $line, 'confirmed', trim((string) $clean['signed_name']), null);
        Session::flash('success', 'Thank you — your confirmation has been recorded.');
        Http::redirect('/salary-notice/' . $token);
    }

    /** @param array<string,string> $params */
    public function query(array $params): void
    {
        $token = (string) ($params['token'] ?? '');
        $line = $this->guardResponse($token);
        $clean = $this->validate(
            ['query_text' => 'required|min:5|max:2000'],
            '/salary-notice/' . $token . '#query'
        );

        $this->record($token, $line, 'query', null, str_replace("\r\n", "\n", trim((string) $clean['query_text'])));
        Session::flash('success', 'Thank you — your query has been sent. Someone will get back to you.');
        Http::redirect('/salary-notice/' . $token);
    }

    /**
     * A POST whose CSRF token matched no live session — a tab left open
     * across a session timeout, almost always. Nothing is stored and CSRF
     * is still enforced: the employee is sent back to a fresh copy of the
     * notice to answer again.
     *
     * @param array<string,string> $params
     */
    public function recoverLostSession(array $params): void
    {
        $token = (string) ($params['token'] ?? '');
        $this->guardResponse($token);

        Session::flash(
            'error',
            'The page timed out before your answer was sent — nothing was saved. Please answer again.'
        );
        Http::redirect('/salary-notice/' . $token, 303);
    }

    /**
     * @param array<string,mixed> $line
     */
    private function record(string $token, array $line, string $status, ?string $signedName, ?string $query): void
    {
        $recorded = PayrollRunLine::respond(
            $token,
            $status,
            $signedName,
            $query,
            Http::clientIp(),
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
        );

        if (!$recorded) {
            Session::forget('_old');
            Session::flash('error', 'This notice has already been answered.');
            Http::redirect('/salary-notice/' . $token);
        }

        Audit::recordAnonymous(
            (int) $line['branch_id'],
            'payroll_line.' . $status,
            'payroll_run_lines',
            (int) $line['id']
        );
        Logger::security('Salary notice answered', ['line_id' => (int) $line['id'], 'status' => $status]);
    }

    /** @return array<string,mixed> */
    private function guardView(string $token): array
    {
        Http::sendPrivatePageHeaders();
        RateLimiter::guard('salary-notice-view-ip:' . Http::clientIp(), self::VIEW_PER_IP, self::WINDOW_SECONDS);
        RateLimiter::guard(
            'salary-notice-view-token:' . PayrollRunLine::hash($token),
            self::VIEW_PER_TOKEN,
            self::WINDOW_SECONDS
        );

        return PayrollRunLine::findByToken($token) ?? $this->inactive();
    }

    /** @return array<string,mixed> */
    private function guardResponse(string $token): array
    {
        Http::sendPrivatePageHeaders();

        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > self::MAX_BODY_BYTES) {
            Logger::security('Salary notice response rejected: body too large', ['ip' => Http::clientIp()]);
            Http::abort(413);
        }

        RateLimiter::guard('salary-notice-respond-ip:' . Http::clientIp(), self::RESPOND_PER_IP, self::WINDOW_SECONDS);
        RateLimiter::guard(
            'salary-notice-respond-token:' . PayrollRunLine::hash($token),
            self::RESPOND_PER_TOKEN,
            self::WINDOW_SECONDS
        );

        return PayrollRunLine::findByToken($token) ?? $this->inactive();
    }

    /**
     * One page for every token that does not open a notice — the same
     * wording and status as a dead joining link, so it says nothing about
     * whether this link ever existed.
     */
    private function inactive(): never
    {
        http_response_code(404);
        $this->view('pages/join/message', [
            'title' => 'This link is no longer active',
            'heading' => 'This link is no longer active',
            'text' => 'Please ask the person who sent it to you for a new one.',
        ], 'layouts/public');
        exit;
    }
}
