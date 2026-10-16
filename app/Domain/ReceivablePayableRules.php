<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The state rules of a receivables / payables entry, as pure functions.
 *
 * An entry starts 'open' and leaves it exactly once — settled in full, or
 * cancelled with a reason — and only an open entry may be edited. Overdue is
 * derived (open and past its due date), never stored.
 *
 * Every function takes "today" as an argument rather than reading the clock,
 * so the date rules are unit-testable at any boundary. Dates are YYYY-MM-DD
 * strings (Validator's `ymd` rule guarantees the shape), which compare
 * correctly as plain strings.
 */
final class ReceivablePayableRules
{
    public const STATUS_OPEN = 'open';
    public const STATUS_SETTLED = 'settled';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_SETTLED, self::STATUS_CANCELLED];

    /** The largest value DECIMAL(15,2) can hold. */
    public const MAX_AMOUNT = '9999999999999.99';

    public static function isOverdue(string $status, ?string $dueDate, string $today): bool
    {
        return $status === self::STATUS_OPEN && $dueDate !== null && $dueDate !== '' && $dueDate < $today;
    }

    /** Edit, settle and cancel are all only possible while the entry is open. */
    public static function isChangeable(string $status): bool
    {
        return $status === self::STATUS_OPEN;
    }

    /**
     * Cross-field rules for a new or edited entry. The Validator has already
     * checked each value's shape (money, ymd).
     *
     * @return array<string,string> field => message; empty when valid
     */
    public static function entryErrors(string $amount, string $issueDate, ?string $dueDate): array
    {
        $errors = [];

        if (bccomp($amount, '0', 2) <= 0) {
            $errors['amount'] = 'The amount must be greater than zero.';
        } elseif (bccomp($amount, self::MAX_AMOUNT, 2) > 0) {
            $errors['amount'] = 'The amount is too large.';
        }

        if ($dueDate !== null && $dueDate !== '' && $dueDate < $issueDate) {
            $errors['due_date'] = 'The due date cannot be before the issue date.';
        }

        return $errors;
    }

    /**
     * May an entry in this state be settled on this date?
     *
     * @return array<string,string> field => message; empty when allowed
     */
    public static function settlementErrors(string $status, string $issueDate, string $settledOn, string $today): array
    {
        if (!self::isChangeable($status)) {
            return ['status' => self::notOpenMessage($status, 'settled')];
        }
        if ($settledOn < $issueDate) {
            return ['settled_on' => 'The settlement date cannot be before the issue date.'];
        }
        if ($settledOn > $today) {
            return ['settled_on' => 'The settlement date cannot be in the future.'];
        }

        return [];
    }

    /**
     * May an entry in this state be cancelled for this reason?
     *
     * @return array<string,string> field => message; empty when allowed
     */
    public static function cancellationErrors(string $status, string $reason): array
    {
        if (!self::isChangeable($status)) {
            return ['status' => self::notOpenMessage($status, 'cancelled')];
        }
        if (trim($reason) === '') {
            return ['cancel_reason' => 'Cancelling an entry requires a reason.'];
        }

        return [];
    }

    /**
     * May an entry in this state be edited?
     *
     * @return array<string,string> field => message; empty when allowed
     */
    public static function editErrors(string $status): array
    {
        return self::isChangeable($status) ? [] : ['status' => self::notOpenMessage($status, 'edited')];
    }

    private static function notOpenMessage(string $status, string $verb): string
    {
        return sprintf('Only an open entry can be %s; this one is already %s.', $verb, $status);
    }
}
