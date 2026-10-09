<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\TransactionType;

/**
 * The facts a "transaction voided" email reports, already formatted for reading.
 */
final class TransactionVoidNotice
{
    public function __construct(
        public readonly int $transactionId,
        public readonly string $branchName,
        public readonly string $typeLabel,
        public readonly string $amount,
        public readonly string $currencySymbol,
        public readonly string $transactionDate,
        public readonly string $description,
        public readonly ?string $referenceNo,
        public readonly ?string $account,
        public readonly ?string $category,
        public readonly ?string $party,
        public readonly string $voidedBy,
        public readonly string $voidedAt,
        public readonly string $reason,
        public readonly int $rowsVoided,
    ) {
    }

    /** @param array<string,mixed> $row from Transaction::voidNotificationDetails() */
    public static function fromRow(array $row, string $currencySymbol, int $rowsVoided): self
    {
        $type = TransactionType::tryFrom((string) $row['type']);
        $category = self::textOrNull($row['category_name'] ?? null);
        $parent = self::textOrNull($row['parent_category_name'] ?? null);

        return new self(
            (int) $row['id'],
            (string) $row['branch_name'],
            $type !== null ? $type->label() : (string) $row['type'],
            (string) $row['amount'],
            $currencySymbol,
            (string) $row['transaction_date'],
            (string) $row['description'],
            self::textOrNull($row['reference_no'] ?? null),
            self::textOrNull($row['account_name'] ?? null),
            $category !== null && $parent !== null ? $parent . ' / ' . $category : $category,
            self::textOrNull($row['partner_name'] ?? null)
                ?? self::textOrNull($row['customer_name'] ?? null)
                ?? self::textOrNull($row['vendor'] ?? null),
            self::textOrNull($row['voided_by_name'] ?? null) ?? 'Unknown user',
            (string) ($row['voided_at'] ?? ''),
            (string) ($row['void_reason'] ?? ''),
            $rowsVoided,
        );
    }

    /** Same shape as the expense email and the rest of the app: symbol, space, two decimals. */
    public function formattedAmount(): string
    {
        return $this->currencySymbol . ' ' . number_format((float) $this->amount, 2);
    }

    private static function textOrNull(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
