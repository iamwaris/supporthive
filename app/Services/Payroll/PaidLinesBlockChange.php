<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use RuntimeException;

/**
 * A month-wide change (withdraw, full re-import, sheet update) refused
 * because a line of the month is marked as paid (PAY-5): its expense is in
 * the books, so the line must not be reset or rewritten under it.
 */
final class PaidLinesBlockChange extends RuntimeException
{
    public const MESSAGE = 'Some salaries for this month are marked as paid. Undo those payments first '
        . '(Mark as unpaid on each paid line), then try again.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
