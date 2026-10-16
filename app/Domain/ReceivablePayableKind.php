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
            self::Receivable => 'Money owed to the business. Tracked here only — never posted to the ledger.',
            self::Payable => 'Money the business owes. Tracked here only — never posted to the ledger.',
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
