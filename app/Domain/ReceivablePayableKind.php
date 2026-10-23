<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The two sides of the receivables / payables register.
 *
 * One controller and one set of views serve both /receivables and /payables;
 * every word that differs between them is answered here, so the screens never
 * branch on a raw 'receivable' string.
 */
enum ReceivablePayableKind: string
{
    case Receivable = 'receivable';
    case Payable = 'payable';

    /** Section root, e.g. /receivables. */
    public function path(): string
    {
        return match ($this) {
            self::Receivable => '/receivables',
            self::Payable => '/payables',
        };
    }

    /** Sidebar key, matching the active-nav convention. */
    public function navKey(): string
    {
        return match ($this) {
            self::Receivable => 'receivables',
            self::Payable => 'payables',
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::Receivable => 'Receivables',
            self::Payable => 'Payables',
        };
    }

    public function singular(): string
    {
        return match ($this) {
            self::Receivable => 'receivable',
            self::Payable => 'payable',
        };
    }

    /** Free-text counterparty: who owes us, or whom we owe. */
    public function partyLabel(): string
    {
        return match ($this) {
            self::Receivable => 'Customer',
            self::Payable => 'Vendor',
        };
    }

    public function tagline(): string
    {
        return match ($this) {
            self::Receivable => 'Money lent or advanced by the business. Moves account balances, never profit or loss.',
            self::Payable => 'Money borrowed by the business. Moves account balances, never profit and loss.',
        };
    }

    /**
     * The ledger movement recording an entry posts: a receivable is money
     * going out (lent), a payable is money coming in (borrowed).
     */
    public function issueType(): TransactionType
    {
        return match ($this) {
            self::Receivable => TransactionType::ReceivableIssued,
            self::Payable => TransactionType::PayableReceived,
        };
    }

    /** The ledger movement settling an entry posts: the money coming back, or paid back. */
    public function settlementType(): TransactionType
    {
        return match ($this) {
            self::Receivable => TransactionType::ReceivableCollected,
            self::Payable => TransactionType::PayableRepaid,
        };
    }

    /** The section a ledger movement belongs to; null for any non-register type. */
    public static function fromMovement(TransactionType $type): ?self
    {
        return match ($type) {
            TransactionType::ReceivableIssued, TransactionType::ReceivableCollected => self::Receivable,
            TransactionType::PayableReceived, TransactionType::PayableRepaid => self::Payable,
            default => null,
        };
    }

    /** Label for the account the issue posting moves. */
    public function issueAccountLabel(): string
    {
        return match ($this) {
            self::Receivable => 'Paid out from',
            self::Payable => 'Received into',
        };
    }

    public function issueAccountHelp(): string
    {
        return match ($this) {
            self::Receivable => 'The money leaves this account when you record the entry.',
            self::Payable => 'The money arrives in this account when you record the entry.',
        };
    }

    /** Label for the account the settlement posting moves. */
    public function settlementAccountLabel(): string
    {
        return match ($this) {
            self::Receivable => 'Received into',
            self::Payable => 'Paid from',
        };
    }

    /** The settle action, phrased the way a person would say it. */
    public function settleLabel(): string
    {
        return match ($this) {
            self::Receivable => 'Mark received',
            self::Payable => 'Mark paid',
        };
    }

    /** The settled status, phrased the same way. */
    public function settledLabel(): string
    {
        return match ($this) {
            self::Receivable => 'Received',
            self::Payable => 'Paid',
        };
    }
}
