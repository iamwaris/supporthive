<?php

declare(strict_types=1);

namespace App\Services\Mail;

/**
 * Something that can deliver a MailMessage. The seam that lets tests swap in
 * a fake and lets an unconfigured install degrade to a logged no-op.
 */
interface MailTransport
{
    /** @throws MailException when the message was not accepted for delivery */
    public function send(MailMessage $message): void;
}
