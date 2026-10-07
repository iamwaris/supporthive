<?php

declare(strict_types=1);

namespace App\Services\Mail;

use DateTimeImmutable;

/**
 * A minimal SMTP client (RFC 5321) — enough to hand one message to a
 * submission server, written by hand because the app ships with no runtime
 * Composer dependencies.
 *
 * Dialogue: greeting → EHLO → STARTTLS + EHLO (port 587) → AUTH LOGIN →
 * MAIL FROM → RCPT TO × n → DATA → QUIT. Every reply code is checked, and
 * credentials are never sent over an unencrypted connection.
 */
final class SmtpMailer implements MailTransport
{
    public function __construct(
        private readonly SmtpStream $stream,
        private readonly SmtpConfig $config,
    ) {
    }

    public function send(MailMessage $message): void
    {
        $this->stream->open(
            $this->config->host,
            $this->config->port,
            $this->config->encryption === SmtpConfig::ENCRYPTION_TLS,
            $this->config->timeoutSeconds
        );

        try {
            $this->expect('greeting', [220]);
            $capabilities = $this->ehlo();

            if ($this->config->encryption === SmtpConfig::ENCRYPTION_STARTTLS) {
                if (!self::advertises($capabilities, 'STARTTLS')) {
                    throw new MailException(
                        'The mail server does not offer STARTTLS; refusing to continue in plaintext.'
                    );
                }
                $this->command('STARTTLS', 'STARTTLS', [220]);
                $this->stream->enableTls();
                // Capabilities seen before TLS must be discarded (RFC 3207 §4.2).
                $capabilities = $this->ehlo();
            }

            if ($this->config->username !== '') {
                $this->authenticate($capabilities);
            }

            $this->command('MAIL FROM:<' . $message->fromAddress . '>', 'MAIL FROM', [250]);
            foreach ($message->to as $recipient) {
                $this->command('RCPT TO:<' . $recipient . '>', 'RCPT TO', [250, 251]);
            }

            $this->command('DATA', 'DATA', [354]);
            $rendered = $message->render(new DateTimeImmutable(), $message->newMessageId());
            $this->stream->write(self::dotStuff($rendered) . "\r\n.\r\n");
            $this->expect('message body', [250]);

            $this->quit();
        } finally {
            $this->stream->close();
        }
    }

    /**
     * Normalise to CRLF and double any leading dot, so a body line that is
     * just "." cannot end the DATA section early (RFC 5321 §4.5.2).
     */
    public static function dotStuff(string $data): string
    {
        $data = (string) preg_replace('/\r\n|\r|\n/', "\r\n", $data);

        return (string) preg_replace('/^\./m', '..', $data);
    }

    /** @return list<string> the EHLO reply lines, code stripped */
    private function ehlo(): array
    {
        return $this->command('EHLO ' . $this->config->heloName, 'EHLO', [250]);
    }

    /** @param list<string> $capabilities */
    private function authenticate(array $capabilities): void
    {
        if ($this->config->encryption === SmtpConfig::ENCRYPTION_NONE) {
            throw new MailException('Refusing to send SMTP credentials over an unencrypted connection.');
        }
        if (!self::advertisesAuth($capabilities, 'LOGIN')) {
            throw new MailException('The mail server does not offer AUTH LOGIN.');
        }

        // The verb passed for error messages is always just "AUTH": the
        // lines themselves are the base64 username and password.
        $this->command('AUTH LOGIN', 'AUTH', [334]);
        $this->command(base64_encode($this->config->username), 'AUTH', [334]);
        $this->command(base64_encode($this->config->password), 'AUTH', [235]);
    }

    private function quit(): void
    {
        // The message is already accepted; a server that drops the line
        // instead of saying goodbye has not lost it.
        try {
            $this->command('QUIT', 'QUIT', [221]);
        } catch (MailException) {
        }
    }

    /**
     * @param list<int> $accepted
     * @return list<string>
     */
    private function command(string $line, string $verb, array $accepted): array
    {
        $this->stream->write($line . "\r\n");

        return $this->expect($verb, $accepted);
    }

    /**
     * Read one (possibly multi-line) reply and check its code.
     *
     * @param list<int> $accepted
     * @return list<string> reply text, one entry per line
     */
    private function expect(string $verb, array $accepted): array
    {
        $lines = [];
        $code = 0;

        // A server sending endless continuation lines must not hold the
        // request forever; no legitimate reply comes close to this.
        for ($i = 0; $i < 100; $i++) {
            $line = rtrim($this->stream->readLine(), "\r\n");

            if (preg_match('/^(\d{3})(?:([ -])(.*))?$/', $line, $match) !== 1) {
                throw new MailException('Unexpected reply to ' . $verb . ': ' . self::excerpt($line));
            }

            $code = (int) $match[1];
            $lines[] = $match[3] ?? '';

            if (($match[2] ?? '') !== '-') {
                if (!in_array($code, $accepted, true)) {
                    throw new MailException(
                        $verb . ' rejected by the mail server: ' . $code . ' ' . self::excerpt(implode(' ', $lines))
                    );
                }

                return $lines;
            }
        }

        throw new MailException('The mail server sent an over-long reply to ' . $verb . '.');
    }

    /** @param list<string> $capabilities */
    private static function advertises(array $capabilities, string $keyword): bool
    {
        foreach ($capabilities as $capability) {
            if (strcasecmp(strtok($capability, ' ') ?: '', $keyword) === 0) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $capabilities */
    private static function advertisesAuth(array $capabilities, string $mechanism): bool
    {
        foreach ($capabilities as $capability) {
            $parts = preg_split('/[\s=]+/', strtoupper(trim($capability))) ?: [];
            if (($parts[0] ?? '') === 'AUTH' && in_array($mechanism, $parts, true)) {
                return true;
            }
        }

        return false;
    }

    private static function excerpt(string $text): string
    {
        return mb_substr(MailMessage::headerText($text), 0, 200);
    }
}
