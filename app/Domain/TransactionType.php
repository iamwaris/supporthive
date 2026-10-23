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
    // Loans / advances, posted only by the receivables / payables register.
    case ReceivableIssued = 'receivable_issued';
    case ReceivableCollected = 'receivable_collected';
    case PayableReceived = 'payable_received';
    case PayableRepaid = 'payable_repaid';

    /** +1 increases the account balance, -1 decreases it. */
    public function direction(): int
    {
        return match ($this) {
            self::Income, self::TransferIn, self::PartnerContribution,
            self::ReceivableCollected, self::PayableReceived => 1,
            self::Expense, self::TransferOut, self::PartnerWithdrawal, self::ProfitDistribution,
            self::ReceivableIssued, self::PayableRepaid => -1,
        };
    }

    /**
     * Does this belong in profit and loss?
     *
     * Only trading does. Moving your own money between your own accounts is
     * not revenue or cost; partner capital going in or out is a balance-sheet
     * movement; and distributing profit is paying out a result, not earning
     * or spending one. Money lent or borrowed, and its repayment, is owed
     * back, so it is not revenue or cost either.
     */
    public function affectsProfitAndLoss(): bool
    {
        return match ($this) {
            self::Income, self::Expense => true,
            self::TransferIn, self::TransferOut,
            self::PartnerContribution, self::PartnerWithdrawal,
            self::ProfitDistribution,
            self::ReceivableIssued, self::ReceivableCollected,
            self::PayableReceived, self::PayableRepaid => false,
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

    /**
     * A receivables / payables movement. Only the register posts or voids
     * one, so a ledger row can never drift from the entry it belongs to.
     */
    public function isRegisterMovement(): bool
    {
        return match ($this) {
            self::ReceivableIssued, self::ReceivableCollected,
            self::PayableReceived, self::PayableRepaid => true,
            default => false,
        };
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
            self::ReceivableIssued => 'Receivable issued',
            self::ReceivableCollected => 'Receivable collected',
            self::PayableReceived => 'Payable received',
            self::PayableRepaid => 'Payable repaid',
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

    /**
     * Every type that puts money into an account: the cash-flow "money in"
     * side. Derived from direction(), so a new type cannot be left out.
     *
     * @return list<string>
     */
    public static function moneyInValues(): array
    {
        return self::valuesWithDirection(1);
    }

    /** @return list<string> every type that takes money out of an account */
    public static function moneyOutValues(): array
    {
        return self::valuesWithDirection(-1);
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

    /** @return list<string> */
    private static function valuesWithDirection(int $direction): array
    {
        return array_values(array_map(
            static fn (self $type): string => $type->value,
            array_filter(self::cases(), static fn (self $type): bool => $type->direction() === $direction)
        ));
    }
}
