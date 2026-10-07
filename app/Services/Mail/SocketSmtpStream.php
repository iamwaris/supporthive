<?php

declare(strict_types=1);

namespace App\Services\Mail;

/**
 * SmtpStream over a real socket.
 *
 * Certificate verification is always on. A mail server whose certificate does
 * not match its name is exactly what a man-in-the-middle looks like, and the
 * AUTH exchange that follows would hand it the mailbox password.
 */
final class SocketSmtpStream implements SmtpStream
{
    /** @var resource|null */
    private $socket = null;

    public function open(string $host, int $port, bool $implicitTls, int $timeoutSeconds): void
    {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'peer_name' => $host,
                'SNI_enabled' => true,
            ],
        ]);

        $errorCode = 0;
        $errorMessage = '';
        $socket = @stream_socket_client(
            ($implicitTls ? 'tls://' : 'tcp://') . $host . ':' . $port,
            $errorCode,
            $errorMessage,
            (float) $timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            throw new MailException('Could not connect to the mail server: ' . trim($errorMessage));
        }

        stream_set_timeout($socket, $timeoutSeconds);
        $this->socket = $socket;
    }

    public function readLine(): string
    {
        $socket = $this->requireSocket();
        $line = fgets($socket, 1024);

        if ($line === false) {
            $meta = stream_get_meta_data($socket);
            throw new MailException(
                $meta['timed_out'] ? 'The mail server did not reply in time.' : 'The mail server closed the connection.'
            );
        }

        return $line;
    }

    public function write(string $bytes): void
    {
        $socket = $this->requireSocket();
        $remaining = $bytes;

        while ($remaining !== '') {
            $written = @fwrite($socket, $remaining);
            if ($written === false || $written === 0) {
                throw new MailException('Could not write to the mail server.');
            }
            $remaining = substr($remaining, $written);
        }
    }

    public function enableTls(): void
    {
        $enabled = @stream_socket_enable_crypto(
            $this->requireSocket(),
            true,
            STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
        );

        if ($enabled !== true) {
            throw new MailException('TLS negotiation with the mail server failed.');
        }
    }

    public function close(): void
    {
        if ($this->socket !== null) {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    /** @return resource */
    private function requireSocket()
    {
        if ($this->socket === null) {
            throw new MailException('The mail connection is not open.');
        }

        return $this->socket;
    }
}
