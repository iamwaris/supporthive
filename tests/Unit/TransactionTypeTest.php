<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\ReceivablePayableKind;
use App\Domain\TransactionType;
use PHPUnit\Framework\TestCase;

/**
 * The receivables / payables movements (AR/AP-3): which way each moves an
 * account, that none of them is ever profit or loss, and that the cash-flow
 * in/out lists are exactly the two directions.
 */
final class TransactionTypeTest extends TestCase
{
    public function testRegisterMovementsMoveBalancesInTheRightDirection(): void
    {
        self::assertSame(-1, TransactionType::ReceivableIssued->direction(), 'lending takes money out');
        self::assertSame(1, TransactionType::ReceivableCollected->direction(), 'collecting brings it back');
        self::assertSame(1, TransactionType::PayableReceived->direction(), 'borrowing brings money in');
        self::assertSame(-1, TransactionType::PayableRepaid->direction(), 'repaying takes it out');
    }

    public function testRegisterMovementsAreNeverProfitAndLoss(): void
    {
        foreach (self::registerTypes() as $type) {
            self::assertTrue($type->isRegisterMovement());
            self::assertFalse($type->affectsProfitAndLoss(), $type->value);
            self::assertFalse($type->isRevenue(), $type->value);
            self::assertFalse($type->isBusinessExpense(), $type->value);
            self::assertFalse($type->requiresCategory(), $type->value);
            self::assertFalse($type->requiresPartner(), $type->value);
            self::assertNotContains($type->value, TransactionType::profitAndLossValues());
            self::assertNotSame($type->value, $type->label(), 'a readable label');
        }

        self::assertSame(['income', 'expense'], TransactionType::profitAndLossValues());
        self::assertFalse(TransactionType::Expense->isRegisterMovement());
        self::assertFalse(TransactionType::TransferOut->isRegisterMovement());
    }

    public function testMoneyInAndOutPartitionEveryTypeByDirection(): void
    {
        $in = TransactionType::moneyInValues();
        $out = TransactionType::moneyOutValues();

        self::assertSame(
            ['income', 'transfer_in', 'partner_contribution', 'receivable_collected', 'payable_received'],
            $in
        );
        self::assertSame(
            [
                'expense', 'transfer_out', 'partner_withdrawal', 'profit_distribution',
                'receivable_issued', 'payable_repaid',
            ],
            $out
        );
        self::assertSame([], array_intersect($in, $out));
        self::assertCount(count(TransactionType::cases()), [...$in, ...$out]);
    }

    public function testEachKindMapsToItsTwoMovementsAndBack(): void
    {
        self::assertSame(TransactionType::ReceivableIssued, ReceivablePayableKind::Receivable->issueType());
        self::assertSame(TransactionType::ReceivableCollected, ReceivablePayableKind::Receivable->settlementType());
        self::assertSame(TransactionType::PayableReceived, ReceivablePayableKind::Payable->issueType());
        self::assertSame(TransactionType::PayableRepaid, ReceivablePayableKind::Payable->settlementType());

        foreach (ReceivablePayableKind::cases() as $kind) {
            self::assertSame($kind, ReceivablePayableKind::fromMovement($kind->issueType()));
            self::assertSame($kind, ReceivablePayableKind::fromMovement($kind->settlementType()));
        }
        self::assertNull(ReceivablePayableKind::fromMovement(TransactionType::Income));
    }

    /** @return list<TransactionType> */
    private static function registerTypes(): array
    {
        return [
            TransactionType::ReceivableIssued,
            TransactionType::ReceivableCollected,
            TransactionType::PayableReceived,
            TransactionType::PayableRepaid,
        ];
    }
}
