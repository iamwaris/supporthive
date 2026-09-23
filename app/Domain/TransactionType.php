<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * What each kind of transaction means.
 *
 * This is the single authoritative answer to three questions that the rest of
 * the application keeps asking: does this move money in or out, does it belong
 * in the profit and loss, and what is it called on screen.
 *
 * Those answers are deliberately NOT repeated in queries. The spec's own
 * acceptance criteria hinge on getting them consistent:
 *
 *   - "Transfers do not inflate income/expenses" — both transfer legs answer
 *     false to affectsProfitAndLoss(), so no P&L aggregate can include them.
 *   - "Contributions and withdrawals remain separate from operating
 *     transactions" — partner capital moves the bank balance and touches the
 *     P&L not at all.
 *
 * Add a case here and every aggregate picks it up. Spell the same logic into a
 * WHERE clause instead and one of them will eventually disagree.
 */
enum TransactionType: string
{
    case Income = 'income';
    case Expense = 'expense';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';
    case PartnerContribution = 'partner_contribution';
    case PartnerWithdrawal = 'partner_withdrawal';
    case ProfitDistribution = 'profit_distribution';

    /** +1 increases the account balance, -1 decreases it. */
    public function direction(): int
    {
        return match ($this) {
            self::Income, self::TransferIn, self::PartnerContribution => 1,
            self::Expense, self::TransferOut, self::PartnerWithdrawal, self::ProfitDistribution => -1,
        };
    }

    /**
     * Does this belong in profit and loss?
     *
     * Only trading does. Moving your own money between your own accounts is
     * not revenue or cost; partner capital going in or out is a balance-sheet
     * movement; and distributing profit is paying out a result, not earning
     * or spending one.
     */
    public function affectsProfitAndLoss(): bool
    {
        return match ($this) {
            self::Income, self::Expense => true,
            self::TransferIn, self::TransferOut,
            self::PartnerContribution, self::PartnerWithdrawal,
            self::ProfitDistribution => false,
        };
    }

    public function isRevenue(): bool
    {
        return $this === self::Income;
    }

    public function isBusinessExpense(): bool
    {
        return $this === self::Expense;
    }

    public function isTransfer(): bool
    {
        return $this === self::TransferIn || $this === self::TransferOut;
    }

    /** Partner capital in or out — never revenue, never an operating expense. */
    public function isPartnerCapital(): bool
    {
        return $this === self::PartnerContribution || $this === self::PartnerWithdrawal;
    }

    public function requiresCategory(): bool
    {
        return $this === self::Income || $this === self::Expense;
    }

    public function requiresPartner(): bool
    {
        return $this->isPartnerCapital() || $this === self::ProfitDistribution;
    }

    public function label(): string
    {
        return match ($this) {
            self::Income => 'Income',
            self::Expense => 'Expense',
            self::TransferIn => 'Transfer in',
            self::TransferOut => 'Transfer out',
            self::PartnerContribution => 'Contribution',
            self::PartnerWithdrawal => 'Withdrawal',
            self::ProfitDistribution => 'Distribution',
        };
    }

    /** @return list<string> every type that belongs in the P&L */
    public static function profitAndLossValues(): array
    {
        return array_values(array_map(
            static fn (self $type): string => $type->value,
            array_filter(self::cases(), static fn (self $type): bool => $type->affectsProfitAndLoss())
        ));
    }

    /** @return list<string> */
    public static function revenueValues(): array
    {
        return array_values(array_map(
            static fn (self $type): string => $type->value,
            array_filter(self::cases(), static fn (self $type): bool => $type->isRevenue())
        ));
    }

    /** @return list<string> */
    public static function expenseValues(): array
    {
        return array_values(array_map(
            static fn (self $type): string => $type->value,
            array_filter(self::cases(), static fn (self $type): bool => $type->isBusinessExpense())
        ));
    }

    /** @return array<string,string> value => label, for form controls */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $type) {
            $options[$type->value] = $type->label();
        }

        return $options;
    }
}
