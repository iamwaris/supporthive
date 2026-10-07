<?php

declare(strict_types=1);

namespace App\Services\Mail;

/**
 * The byte pipe SmtpMailer talks through. Separated from the SMTP dialogue so
 * the dialogue can be tested against a scripted fake server.
 */
interface SmtpStream
{
    /** @throws MailException */
    public function open(string $host, int $port, bool $implicitTls, int $timeoutSeconds): void;

    /** One reply line including its CRLF. @throws MailException on timeout or a closed connection */
    public function readLine(): string;

    /** @throws MailException */
    public function write(string $bytes): void;

    /** Upgrade the open connection to TLS after STARTTLS. @throws MailException */
    public function enableTls(): void;

    public function close(): void;
}
