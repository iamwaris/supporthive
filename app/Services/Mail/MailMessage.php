<?php

declare(strict_types=1);

namespace App\Services\Mail;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * A plain-text email and its RFC 5322 rendering.
 *
 * Header values here routinely contain user input (a vendor name in the
 * subject), so every one of them passes through headerText(), which removes
 * CR and LF. Without that, "Acme\r\nBcc: someone@example.com" typed into the
 * vendor field would add a recipient. Addresses are validated at construction,
 * which also keeps them safe for the SMTP envelope (MAIL FROM / RCPT TO).
 */
final class MailMessage
{
    /** @var list<string> */
    public readonly array $to;

    /** @param list<string> $to */
    public function __construct(
        public readonly string $fromAddress,
        public readonly string $fromName,
        array $to,
        public readonly string $subject,
        public readonly string $textBody,
    ) {
        if ($to === []) {
            throw new InvalidArgumentException('A message needs at least one recipient.');
        }

        foreach ([$fromAddress, ...$to] as $address) {
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException('Not a valid email address.');
            }
        }

        $this->to = array_values($to);
    }

    /** Full message (headers, blank line, body) with CRLF line endings, not yet dot-stuffed. */
    public function render(DateTimeInterface $sentAt, string $messageId): string
    {
        $headers = [
            'Date: ' . $sentAt->format(DateTimeInterface::RFC2822),
            'From: ' . self::mailbox($this->fromName, $this->fromAddress),
            'To: ' . implode(', ', $this->to),
            'Subject: ' . self::encodeHeaderText($this->subject),
            'Message-ID: <' . self::headerText($messageId) . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'Auto-Submitted: auto-generated',
        ];

        $body = preg_replace('/\r\n|\r|\n/', "\r\n", $this->textBody) ?? $this->textBody;

        // base64 keeps every body line short and ASCII whatever the text holds,
        // so no line can exceed SMTP's 998-byte limit.
        return implode("\r\n", $headers) . "\r\n\r\n" . rtrim(chunk_split(base64_encode($body), 76, "\r\n"));
    }

    /** A Message-ID unique enough to thread on, scoped to the sender's domain. */
    public function newMessageId(): string
    {
        $domain = substr((string) strrchr($this->fromAddress, '@'), 1);

        return bin2hex(random_bytes(16)) . '@' . ($domain !== '' ? $domain : 'localhost');
    }

    /** A header value with every CR, LF and NUL replaced, so it cannot start a new header line. */
    public static function headerText(string $value): string
    {
        return trim((string) preg_replace('/[\r\n\0]+/', ' ', $value));
    }

    /** RFC 2047 encoding for a header value, applied only when plain ASCII will not do. */
    public static function encodeHeaderText(string $value): string
    {
        $value = self::headerText($value);

        if (preg_match('/^[\x20-\x7E]*$/', $value) === 1 && !str_contains($value, '=?')) {
            return $value;
        }

        return self::encodedWords($value);
    }

    /**
     * Base64 encoded-words, folded so none passes RFC 2047's 75 characters.
     * Splits on character boundaries: cutting a multibyte character in half
     * would garble it in the inbox.
     */
    private static function encodedWords(string $value): string
    {
        $words = [];
        $chunk = '';
        foreach (mb_str_split($value, 1, 'UTF-8') as $character) {
            // 39 raw bytes become 52 base64 characters, 64 with the =?UTF-8?B?...?=
            // wrapper, so even the first word after "Subject: " stays within 78.
            if (strlen($chunk . $character) > 39) {
                $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
                $chunk = '';
            }
            $chunk .= $character;
        }
        if ($chunk !== '') {
            $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
        }

        return implode("\r\n ", $words);
    }

    private static function mailbox(string $name, string $address): string
    {
        $name = self::headerText($name);
        if ($name === '') {
            return $address;
        }

        // Anything beyond letters, digits, spaces, dots and hyphens (a comma,
        // a quote, an accent) is encoded rather than escaped inside quotes.
        $display = preg_match('/^[A-Za-z0-9 .\-]+$/', $name) === 1
            ? '"' . $name . '"'
            : self::encodedWords($name);

        return $display . ' <' . $address . '>';
    }
}
