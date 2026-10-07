<?php

declare(strict_types=1);

namespace App\Services\Mail;

use RuntimeException;

/**
 * A message could not be handed to the mail server.
 *
 * Messages carry the SMTP verb and the server's reply, never what was sent:
 * an AUTH exchange contains the credentials.
 */
final class MailException extends RuntimeException
{
}
