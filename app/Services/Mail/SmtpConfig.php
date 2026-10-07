<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Core\Config;
use InvalidArgumentException;

/**
 * Connection settings for SmtpMailer, read from config/mail.php.
 *
 * The password lives here only as long as the mailer needs it and is never
 * part of any message or exception built from this object.
 */
final class SmtpConfig
{
    public const ENCRYPTION_TLS = 'tls';
    public const ENCRYPTION_STARTTLS = 'starttls';
    public const ENCRYPTION_NONE = 'none';

    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $username,
        #[\SensitiveParameter]
        public readonly string $password,
        public readonly string $encryption,
        public readonly string $fromAddress,
        public readonly string $fromName,
        public readonly int $timeoutSeconds = 10,
        public readonly string $heloName = 'localhost',
    ) {
        if (!in_array($encryption, [self::ENCRYPTION_TLS, self::ENCRYPTION_STARTTLS, self::ENCRYPTION_NONE], true)) {
            throw new InvalidArgumentException('MAIL_ENCRYPTION must be tls, starttls or none.');
        }
        if (filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('MAIL_FROM must be a valid email address.');
        }
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('MAIL_PORT is out of range.');
        }
    }

    /** Null when MAIL_HOST is blank, i.e. mail is deliberately not configured. */
    public static function fromConfig(): ?self
    {
        $host = (string) Config::get('mail.host', '');
        if ($host === '') {
            return null;
        }

        return new self(
            $host,
            (int) Config::get('mail.port', 587),
            (string) Config::get('mail.username', ''),
            (string) Config::get('mail.password', ''),
            (string) Config::get('mail.encryption', self::ENCRYPTION_STARTTLS),
            (string) Config::get('mail.from', ''),
            (string) Config::get('mail.from_name', ''),
            max(1, (int) Config::get('mail.timeout', 10)),
            self::heloNameFromAppUrl((string) Config::get('app.url', '')),
        );
    }

    /** EHLO should name this host; the APP_URL host is the best name the app knows for itself. */
    private static function heloNameFromAppUrl(string $appUrl): string
    {
        $host = (string) parse_url($appUrl, PHP_URL_HOST);

        return preg_match('/^[A-Za-z0-9.-]+$/', $host) === 1 ? $host : 'localhost';
    }
}
