<?php

declare(strict_types=1);

namespace App\Services\Mail;

/**
 * Picks the transport for this environment: real SMTP when MAIL_HOST is set,
 * otherwise the logging no-op.
 */
final class Mailer
{
    private static ?MailTransport $override = null;

    public static function transport(): MailTransport
    {
        if (self::$override !== null) {
            return self::$override;
        }

        $config = SmtpConfig::fromConfig();

        return $config === null ? new NullMailer() : new SmtpMailer(new SocketSmtpStream(), $config);
    }

    /** Tests only: route every message through $transport; null restores the real one. */
    public static function useTransport(?MailTransport $transport): void
    {
        self::$override = $transport;
    }
}
