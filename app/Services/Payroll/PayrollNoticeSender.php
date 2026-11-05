<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Core\Config;
use App\Core\Logger;
use App\Domain\PayrollLineStatus;
use App\Models\PayrollRunLine;
use App\Services\Audit;
use App\Services\Mail\MailMessage;
use App\Services\Mail\MailTransport;
use App\Services\Mail\Mailer;
use App\Services\Mail\NullMailer;
use App\Services\Settings;
use Throwable;

/**
 * Emails each employee the private link to their own salary notice.
 *
 * The email holds no figures — only a short notice and the link — so a
 * forwarded or misdelivered email reveals nothing by itself. Every send
 * issues a fresh token; the line records it only after the mail server
 * accepted the message, so a failed send leaves the previous link working.
 * One line failing never stops the rest, and why it failed goes to the log,
 * never to the browser.
 */
final class PayrollNoticeSender
{
    public const LINK_DAYS = 30;

    public function __construct(
        private readonly PayrollRunLine $lines,
        private readonly MailTransport $transport,
        private readonly string $fromAddress,
        private readonly string $fromName,
        private readonly string $appUrl,
        private readonly string $companyName,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            new PayrollRunLine(),
            Mailer::transport(),
            (string) Config::get('mail.from', ''),
            (string) Config::get('mail.from_name', ''),
            (string) Config::get('app.url', ''),
            Settings::string('company_name', 'LedgerHive')
        );
    }

    /**
     * False when MAIL_HOST is blank: the transport is then NullMailer, which
     * "accepts" every message by logging it. Treating that as delivered
     * would mark lines sent with links nobody received — and, on a resend,
     * cancel a link the employee does have.
     */
    public function mailIsConfigured(): bool
    {
        return !$this->transport instanceof NullMailer;
    }

    /**
     * @param list<array<string,mixed>> $rows lines as PayrollRunLine::forRun() returns them
     */
    public function send(array $rows, string $periodMonth): PayrollSendReport
    {
        $sent = [];
        $skipped = [];
        $failed = [];
        $month = self::monthLabel($periodMonth);

        if (!$this->mailIsConfigured()) {
            Logger::info('Salary notices not sent: MAIL_HOST is not configured', ['lines' => count($rows)]);
            $reason = 'Email is not configured (MAIL_HOST)';

            return new PayrollSendReport(
                [],
                array_map(
                    static fn (array $row): array => ['name' => (string) $row['name'], 'reason' => $reason],
                    $rows
                ),
                []
            );
        }

        foreach ($rows as $row) {
            $name = (string) $row['name'];
            $status = PayrollLineStatus::from((string) $row['status']);

            $reason = $this->skipReason($row, $status);
            if ($reason !== null) {
                $skipped[] = ['name' => $name, 'reason' => $reason];
                continue;
            }

            $token = PayrollRunLine::newToken();
            $expiresAt = date('Y-m-d H:i:s', time() + self::LINK_DAYS * 86400);

            try {
                $this->transport->send($this->compose($row, $month, $token, $expiresAt));
            } catch (Throwable $e) {
                Logger::error('Salary notice email was not sent', [
                    'line_id' => (int) $row['id'],
                    'error' => $e->getMessage(),
                ]);
                $failed[] = ['name' => $name, 'reason' => 'the mail server did not accept the message'];
                continue;
            }

            if (!$this->lines->markSent((int) $row['id'], $status->value, $token, $expiresAt)) {
                $failed[] = ['name' => $name, 'reason' => 'the line changed while sending; reload and try again'];
                continue;
            }

            Audit::record(
                $status === PayrollLineStatus::Draft ? 'payroll_line.sent' : 'payroll_line.resent',
                'payroll_run_lines',
                (int) $row['id'],
                // The previous response, kept here before markSent() cleared it.
                $status === PayrollLineStatus::Query
                    ? [
                        'status' => $status->value,
                        'responded_at' => $row['responded_at'],
                        'query_text' => $row['query_text'],
                    ]
                    : ['status' => $status->value],
                ['status' => PayrollLineStatus::Sent->value, 'link_expires_at' => $expiresAt]
            );
            $sent[] = $name;
        }

        return new PayrollSendReport($sent, $skipped, $failed);
    }

    /** "2026-10-01" => "October 2026" */
    public static function monthLabel(string $periodMonth): string
    {
        $timestamp = strtotime($periodMonth);

        return $timestamp === false ? $periodMonth : date('F Y', $timestamp);
    }

    /** @param array<string,mixed> $row */
    public function compose(array $row, string $month, string $token, string $expiresAt): MailMessage
    {
        $link = rtrim($this->appUrl, '/') . '/salary-notice/' . $token;
        $expiresOn = date('j F Y', (int) strtotime($expiresAt));

        $lines = [
            'Hello ' . MailMessage::headerText((string) $row['name']) . ',',
            '',
            'Your salary notice for ' . $month . ' is ready.',
            '',
            'Please open the link below to see your salary, attendance and the note for the month,',
            'then confirm it or raise a query:',
            '',
            $link,
            '',
            'This link is for you only - please do not forward it. It works until ' . $expiresOn . '.',
            '',
            '--',
            MailMessage::headerText($this->companyName),
        ];

        return new MailMessage(
            $this->fromAddress,
            $this->fromName,
            [(string) $row['email']],
            'Your salary notice for ' . $month,
            implode("\n", $lines)
        );
    }

    /** @param array<string,mixed> $row */
    private function skipReason(array $row, PayrollLineStatus $status): ?string
    {
        if (!$status->canSend()) {
            return 'already confirmed';
        }
        // A link already sent keeps working after the employee is disabled,
        // so they can still answer for a month they worked; no new one goes out.
        if ((string) ($row['user_status'] ?? 'active') !== 'active') {
            return 'employee is disabled';
        }
        if ($row['screenshot_filename'] === null || (string) $row['screenshot_filename'] === '') {
            return 'no attendance screenshot yet';
        }
        if (filter_var((string) ($row['email'] ?? ''), FILTER_VALIDATE_EMAIL) === false) {
            return 'no valid email address';
        }
        if (preg_match('#^https?://#i', $this->appUrl) !== 1) {
            return 'APP_URL is not set, so no link can be built';
        }
        if (filter_var($this->fromAddress, FILTER_VALIDATE_EMAIL) === false) {
            return 'MAIL_FROM is not set';
        }

        return null;
    }
}
