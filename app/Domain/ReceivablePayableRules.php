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
     * May this entry's settlement be undone (back to open) for this reason?
     * Only a settled entry can be reopened.
     *
     * @return array<string,string> field => message; empty when allowed
     */
    public static function reopenErrors(string $status, string $reason): array
    {
        if ($status !== self::STATUS_SETTLED) {
            return ['status' => sprintf('Only a settled entry can be reopened; this one is %s.', $status)];
        }
        if (trim($reason) === '') {
            return ['reopen_reason' => 'Reopening an entry requires a reason.'];
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

    /**
     * Amount, issue date and account are fixed once the issue posting
     * exists: changing them would leave the ledger row saying something else.
     * A field that was not submitted (null) is not a change; a submitted
     * value that differs from the stored one is refused, never ignored.
     *
     * An entry recorded before the register posted to the ledger is not
     * posted yet: the one-time backfill posts it as stored (and sets its
     * account), so the same fields are refused with that explanation.
     *
     * @param array{amount:string,issue_date:string,account_id:?int,posted:bool} $stored
     * @param array{amount:?string,issue_date:?string,account_id:?int} $submitted
     * @return array<string,string> field => message; empty when nothing locked changed
     */
    public static function lockedFieldErrors(array $stored, array $submitted): array
    {
        $message = $stored['posted']
            ? 'This is locked once posted. To change it, cancel the entry and record it again.'
            : 'This is set when the one-time ledger backfill posts this entry, and cannot be changed by editing.';
        $errors = [];

        if ($submitted['amount'] !== null && bccomp($submitted['amount'], $stored['amount'], 2) !== 0) {
            $errors['amount'] = $message;
        }
        if ($submitted['issue_date'] !== null && $submitted['issue_date'] !== $stored['issue_date']) {
            $errors['issue_date'] = $message;
        }
        if ($submitted['account_id'] !== null && $submitted['account_id'] !== $stored['account_id']) {
            $errors['account_id'] = $message;
        }

        return $errors;
    }

    /**
     * Settling and cancelling act on the issue posting, so an entry recorded
     * before the register posted to the ledger must be backfilled first.
     *
     * @return array<string,string> field => message; empty when posted
     */
    public static function postingErrors(?int $issueTransactionId): array
    {
        return $issueTransactionId === null
            ? ['status' => 'This entry has not been posted to the ledger yet. Ask an administrator to run '
                . 'the receivables / payables backfill, then try again.']
            : [];
    }

    private static function notOpenMessage(string $status, string $verb): string
    {
        return sprintf('Only an open entry can be %s; this one is already %s.', $verb, $status);
    }
}
