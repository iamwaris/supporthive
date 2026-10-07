<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The facts an "expense added" email reports, already formatted for reading.
 */
final class ExpenseNotice
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_RECURRING = 'recurring';

    public function __construct(
        public readonly int $transactionId,
        public readonly string $branchName,
        public readonly string $amount,
        public readonly string $currencySymbol,
        public readonly string $transactionDate,
        public readonly string $category,
        public readonly ?string $vendor,
        public readonly string $addedBy,
        public readonly string $source,
    ) {
    }

    /** @param array<string,mixed> $row from Expense::notificationDetails() */
    public static function fromRow(array $row, string $currencySymbol): self
    {
        $category = (string) ($row['category_name'] ?? '');
        $parent = (string) ($row['parent_category_name'] ?? '');
        $vendor = (string) ($row['vendor'] ?? '');

        return new self(
            (int) $row['id'],
            (string) $row['branch_name'],
            (string) $row['amount'],
            $currencySymbol,
            (string) $row['transaction_date'],
            $parent !== '' ? $parent . ' / ' . $category : $category,
            $vendor !== '' ? $vendor : null,
            (string) ($row['created_by_name'] ?? 'Unknown user'),
            (bool) ($row['from_recurring'] ?? false) ? self::SOURCE_RECURRING : self::SOURCE_MANUAL,
        );
    }

    /** Same shape as the rest of the app: symbol, space, two decimals with thousands separators. */
    public function formattedAmount(): string
    {
        return $this->currencySymbol . ' ' . number_format((float) $this->amount, 2);
    }

    public function sourceLabel(): string
    {
        return $this->source === self::SOURCE_RECURRING ? 'Approved recurring transaction' : 'Manual entry';
    }
}
