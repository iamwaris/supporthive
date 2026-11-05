<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use RuntimeException;

/**
 * A salary payment (or its undo) that the rules refuse. The message is
 * written for the admin; $field names the form field it belongs to, or is
 * null when it concerns the line as a whole.
 */
final class SalaryPaymentRefused extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }
}
