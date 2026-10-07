<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Mail\MailMessage;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Rendering and, above all, header-injection resistance: the subject carries
 * a user-typed vendor name.
 */
final class MailMessageTest extends TestCase
{
    private function render(MailMessage $message): string
    {
        return $message->render(new DateTimeImmutable('2026-10-06 09:30:00 +00:00'), 'abc123@example.com');
    }

    /** @return array<string,string> header name => value, folded lines joined */
    private function headers(string $rendered): array
    {
        [$head] = explode("\r\n\r\n", $rendered, 2);
        $unfolded = (string) preg_replace("/\r\n[ \t]+/", ' ', $head);

        $headers = [];
        foreach (explode("\r\n", $unfolded) as $line) {
            [$name, $value] = explode(':', $line, 2);
            $headers[$name] = trim($value);
        }

        return $headers;
    }

    public function testCrLfInTheSubjectCannotInjectAHeader(): void
    {
        $message = new MailMessage(
            'no-reply@example.com',
            "Ledger\r\nBcc: evil@example.net",
            ['a@example.com'],
            "Expense added: Acme\r\nBcc: evil@example.net\nX-Injected: yes",
            'body'
        );

        $headers = $this->headers($this->render($message));

        self::assertArrayNotHasKey('Bcc', $headers);
        self::assertArrayNotHasKey('X-Injected', $headers);
        self::assertSame('Expense added: Acme Bcc: evil@example.net X-Injected: yes', $headers['Subject']);
    }

    public function testRequiredHeadersArePresent(): void
    {
        $headers = $this->headers($this->render(
            new MailMessage('no-reply@example.com', 'LedgerHive', ['a@example.com', 'b@example.com'], 'Hi', 'x')
        ));

        self::assertSame('Tue, 06 Oct 2026 09:30:00 +0000', $headers['Date']);
        self::assertSame('<abc123@example.com>', $headers['Message-ID']);
        self::assertSame('"LedgerHive" <no-reply@example.com>', $headers['From']);
        self::assertSame('a@example.com, b@example.com', $headers['To']);
        self::assertSame('text/plain; charset=UTF-8', $headers['Content-Type']);
        self::assertSame('base64', $headers['Content-Transfer-Encoding']);
    }

    public function testNonAsciiSubjectIsRfc2047EncodedAndFolded(): void
    {
        $subject = 'Expense added: Rs 1,250.00 - Café Zürich ' . str_repeat('ü', 40);
        $rendered = $this->render(new MailMessage('no-reply@example.com', '', ['a@example.com'], $subject, 'x'));

        $encoded = $this->headers($rendered)['Subject'];
        self::assertStringStartsWith('=?UTF-8?B?', $encoded);
        self::assertSame($subject, mb_decode_mimeheader($encoded));

        foreach (explode("\r\n", explode("\r\n\r\n", $rendered, 2)[0]) as $line) {
            self::assertLessThanOrEqual(78, strlen($line), 'header lines stay foldable-length');
        }
    }

    public function testBodyIsBase64WithCrLfLineEndings(): void
    {
        $rendered = $this->render(new MailMessage('no-reply@example.com', '', ['a@example.com'], 'Hi', "One\nTwo ü"));

        [, $body] = explode("\r\n\r\n", $rendered, 2);
        self::assertSame("One\r\nTwo ü", base64_decode(str_replace("\r\n", '', $body), true));
    }

    public function testInvalidRecipientIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MailMessage('no-reply@example.com', '', ["a@example.com>\r\nRCPT TO:<x@y.z"], 'Hi', 'x');
    }

    public function testAtLeastOneRecipientIsRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MailMessage('no-reply@example.com', '', [], 'Hi', 'x');
    }
}
