<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Mail\MailException;
use App\Services\Mail\SmtpStream;

/**
 * A scripted SMTP server: hands back queued reply lines in order and records
 * everything the client wrote, so a test can assert the exact dialogue.
 */
final class FakeSmtpStream implements SmtpStream
{
    /** @var list<string> */
    private array $replies;

    /** @var list<string> */
    public array $writes = [];

    public bool $opened = false;
    public bool $implicitTls = false;
    public bool $tlsEnabled = false;
    public bool $closed = false;

    /** @param list<string> $replies one line each, without CRLF */
    public function __construct(array $replies)
    {
        $this->replies = $replies;
    }

    public function open(string $host, int $port, bool $implicitTls, int $timeoutSeconds): void
    {
        $this->opened = true;
        $this->implicitTls = $implicitTls;
    }

    public function readLine(): string
    {
        if ($this->replies === []) {
            throw new MailException('The mail server closed the connection.');
        }

        return array_shift($this->replies) . "\r\n";
    }

    public function write(string $bytes): void
    {
        $this->writes[] = $bytes;
    }

    public function enableTls(): void
    {
        $this->tlsEnabled = true;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    /** @return list<string> each command line written, CRLF stripped, excluding the DATA payload */
    public function commands(): array
    {
        return array_values(array_map(
            static fn (string $write): string => rtrim($write, "\r\n"),
            array_filter($this->writes, static fn (string $write): bool => !str_ends_with($write, "\r\n.\r\n"))
        ));
    }

    public function dataPayload(): ?string
    {
        foreach ($this->writes as $write) {
            if (str_ends_with($write, "\r\n.\r\n")) {
                return $write;
            }
        }

        return null;
    }
}
