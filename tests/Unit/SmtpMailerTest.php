<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Mail\MailException;
use App\Services\Mail\MailMessage;
use App\Services\Mail\SmtpConfig;
use App\Services\Mail\SmtpMailer;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeSmtpStream;

/**
 * The SMTP dialogue against a scripted server: order of commands, reply-code
 * checking, TLS handling, and that credentials never leak into errors.
 */
final class SmtpMailerTest extends TestCase
{
    private const PASSWORD = 'pw-never-in-errors';

    private function config(
        string $encryption = SmtpConfig::ENCRYPTION_STARTTLS,
        string $username = 'mailer'
    ): SmtpConfig {
        return new SmtpConfig(
            'smtp.example.com',
            $encryption === SmtpConfig::ENCRYPTION_TLS ? 465 : 587,
            $username,
            self::PASSWORD,
            $encryption,
            'no-reply@example.com',
            'LedgerHive',
            5,
            'app.example.com'
        );
    }

    private function message(string $body = "Hello\nWorld"): MailMessage
    {
        return new MailMessage(
            'no-reply@example.com',
            'LedgerHive',
            ['a@example.com', 'b@example.com'],
            'Expense added',
            $body
        );
    }

    /** @return list<string> */
    private function happyStartTlsReplies(): array
    {
        return [
            '220 smtp.example.com ESMTP ready',
            '250-smtp.example.com',
            '250-PIPELINING',
            '250 STARTTLS',
            '220 Go ahead',
            '250-smtp.example.com',
            '250-AUTH PLAIN LOGIN',
            '250 8BITMIME',
            '334 VXNlcm5hbWU6',
            '334 UGFzc3dvcmQ6',
            '235 Authenticated',
            '250 Sender OK',
            '250 Recipient OK',
            '250 Recipient OK',
            '354 End data with <CR><LF>.<CR><LF>',
            '250 Queued as ABC123',
            '221 Bye',
        ];
    }

    public function testFullStartTlsDialogueRunsInOrder(): void
    {
        $stream = new FakeSmtpStream($this->happyStartTlsReplies());

        (new SmtpMailer($stream, $this->config()))->send($this->message());

        self::assertTrue($stream->opened);
        self::assertFalse($stream->implicitTls, 'port 587 connects in plaintext and upgrades');
        self::assertTrue($stream->tlsEnabled, 'STARTTLS must actually upgrade the stream');
        self::assertTrue($stream->closed);
        self::assertSame([
            'EHLO app.example.com',
            'STARTTLS',
            'EHLO app.example.com',
            'AUTH LOGIN',
            base64_encode('mailer'),
            base64_encode(self::PASSWORD),
            'MAIL FROM:<no-reply@example.com>',
            'RCPT TO:<a@example.com>',
            'RCPT TO:<b@example.com>',
            'DATA',
            'QUIT',
        ], $stream->commands());

        $payload = (string) $stream->dataPayload();
        self::assertStringEndsWith("\r\n.\r\n", $payload);
        self::assertStringContainsString("Subject: Expense added\r\n", $payload);
    }

    public function testImplicitTlsSkipsStartTls(): void
    {
        $stream = new FakeSmtpStream([
            '220 ready',
            '250-smtp.example.com',
            '250 AUTH LOGIN',
            '334 VXNlcm5hbWU6',
            '334 UGFzc3dvcmQ6',
            '235 ok',
            '250 ok',
            '250 ok',
            '250 ok',
            '354 go',
            '250 queued',
            '221 bye',
        ]);

        (new SmtpMailer($stream, $this->config(SmtpConfig::ENCRYPTION_TLS)))->send($this->message());

        self::assertTrue($stream->implicitTls);
        self::assertFalse($stream->tlsEnabled);
        self::assertNotContains('STARTTLS', $stream->commands());
    }

    public function testRefusesToContinueWhenStartTlsIsNotOffered(): void
    {
        $stream = new FakeSmtpStream(['220 ready', '250-smtp.example.com', '250 AUTH LOGIN']);

        try {
            (new SmtpMailer($stream, $this->config()))->send($this->message());
            self::fail('a server without STARTTLS must not receive credentials in plaintext');
        } catch (MailException $e) {
            self::assertStringContainsString('STARTTLS', $e->getMessage());
        }

        self::assertNotContains('AUTH LOGIN', $stream->commands());
        self::assertTrue($stream->closed);
    }

    public function testRefusesCredentialsOnAnUnencryptedConnection(): void
    {
        $stream = new FakeSmtpStream(['220 ready', '250 AUTH LOGIN']);

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('unencrypted');

        (new SmtpMailer($stream, $this->config(SmtpConfig::ENCRYPTION_NONE)))->send($this->message());
    }

    public function testUnencryptedWithoutCredentialsSendsForALocalCatcher(): void
    {
        $stream = new FakeSmtpStream([
            '220 ready', '250 localhost', '250 ok', '250 ok', '250 ok', '354 go', '250 queued', '221 bye',
        ]);

        (new SmtpMailer($stream, $this->config(SmtpConfig::ENCRYPTION_NONE, '')))->send($this->message());

        self::assertSame('MAIL FROM:<no-reply@example.com>', $stream->commands()[1]);
    }

    public function testRejectedRecipientThrowsWithTheServerReplyAndClosesTheStream(): void
    {
        $replies = $this->happyStartTlsReplies();
        $replies[12] = '550 5.1.1 Mailbox unavailable';
        $stream = new FakeSmtpStream(array_slice($replies, 0, 13));

        try {
            (new SmtpMailer($stream, $this->config()))->send($this->message());
            self::fail('a 550 on RCPT TO must fail the send');
        } catch (MailException $e) {
            self::assertStringContainsString('RCPT TO', $e->getMessage());
            self::assertStringContainsString('550', $e->getMessage());
        }

        self::assertTrue($stream->closed, 'the connection is closed even when the send fails');
        self::assertNotContains('DATA', $stream->commands());
    }

    public function testFailedAuthenticationNeverExposesTheCredentials(): void
    {
        $replies = array_slice($this->happyStartTlsReplies(), 0, 10);
        $replies[] = '535 5.7.8 Authentication failed for ' . base64_encode(self::PASSWORD);
        $stream = new FakeSmtpStream($replies);

        try {
            (new SmtpMailer($stream, $this->config()))->send($this->message());
            self::fail('a 535 must fail the send');
        } catch (MailException $e) {
            self::assertStringStartsWith('AUTH rejected', $e->getMessage());
            self::assertStringNotContainsString(self::PASSWORD, $e->getMessage());
        }
    }

    public function testGarbledReplyIsAnError(): void
    {
        $stream = new FakeSmtpStream(['hello there']);

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('Unexpected reply');

        (new SmtpMailer($stream, $this->config()))->send($this->message());
    }

    public function testServerDroppingTheLineAfterAcceptanceStillCountsAsSent(): void
    {
        $replies = $this->happyStartTlsReplies();
        array_pop($replies); // no 221 to QUIT

        $stream = new FakeSmtpStream($replies);
        (new SmtpMailer($stream, $this->config()))->send($this->message());

        self::assertContains('QUIT', $stream->commands());
    }

    public function testDotStuffingDoublesLeadingDotsAndNormalisesLineEndings(): void
    {
        self::assertSame(
            "line one\r\n..\r\n..hidden\r\nmid.dot\r\n",
            SmtpMailer::dotStuff("line one\n.\r\n.hidden\rmid.dot\n")
        );
        self::assertSame('..starts', SmtpMailer::dotStuff('.starts'));
    }
}
