<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Mail\MailException;
use App\Services\Mail\MailMessage;
use App\Services\Mail\MailTransport;

/** Captures sent messages instead of delivering them, or fails every send. */
final class RecordingMailTransport implements MailTransport
{
    /** @var list<MailMessage> */
    public array $sent = [];

    public int $attempts = 0;

    public function __construct(private readonly bool $failing = false)
    {
    }

    public function send(MailMessage $message): void
    {
        $this->attempts++;

        if ($this->failing) {
            throw new MailException('RCPT TO rejected by the mail server: 550 mailbox unavailable');
        }

        $this->sent[] = $message;
    }
}
