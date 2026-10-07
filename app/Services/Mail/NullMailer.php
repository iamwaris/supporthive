<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Core\Logger;

/**
 * Used when MAIL_HOST is blank: records that a message would have gone out,
 * so a missing SMTP setup is visible in the log instead of silently eating
 * notifications.
 */
final class NullMailer implements MailTransport
{
    public function send(MailMessage $message): void
    {
        Logger::info('Mail not sent: MAIL_HOST is not configured', [
            'subject' => $message->subject,
            'recipients' => count($message->to),
        ]);
    }
}
