<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\ReceivablePayableKind;
use App\Domain\ReceivablePayableRules;
use PHPUnit\Framework\TestCase;

/**
 * The register's state machine and date rules, at their boundaries: an entry
 * leaves 'open' exactly once, only an open entry changes, and overdue is
 * strictly "open and the due date is before today".
 */
final class ReceivablePayableRulesTest extends TestCase
{
    private const TODAY = '2026-10-17';

    public function testOverdueIsOpenAndStrictlyPastTheDueDate(): void
    {
        self::assertTrue(ReceivablePayableRules::isOverdue('open', '2026-10-16', self::TODAY));
        self::assertFalse(ReceivablePayableRules::isOverdue('open', self::TODAY, self::TODAY), 'due today is not late');
        self::assertFalse(ReceivablePayableRules::isOverdue('open', '2026-10-18', self::TODAY));
        self::assertFalse(ReceivablePayableRules::isOverdue('open', null, self::TODAY), 'no due date never overdue');
        self::assertFalse(ReceivablePayableRules::isOverdue('open', '', self::TODAY));
        self::assertFalse(ReceivablePayableRules::isOverdue('settled', '2026-01-01', self::TODAY));
        self::assertFalse(ReceivablePayableRules::isOverdue('cancelled', '2026-01-01', self::TODAY));
    }

    public function testOnlyAnOpenEntryIsChangeable(): void
    {
        self::assertTrue(ReceivablePayableRules::isChangeable('open'));
        self::assertFalse(ReceivablePayableRules::isChangeable('settled'));
        self::assertFalse(ReceivablePayableRules::isChangeable('cancelled'));
        self::assertFalse(ReceivablePayableRules::isChangeable('void'), 'unknown states fail closed');

        self::assertSame([], ReceivablePayableRules::editErrors('open'));
        self::assertArrayHasKey('status', ReceivablePayableRules::editErrors('settled'));
        self::assertArrayHasKey('status', ReceivablePayableRules::editErrors('cancelled'));
    }

    public function testEntryAmountMustBePositiveAndFitTheColumn(): void
    {
        self::assertSame([], ReceivablePayableRules::entryErrors('0.01', self::TODAY, null));
        $max = ReceivablePayableRules::MAX_AMOUNT;
        self::assertSame([], ReceivablePayableRules::entryErrors($max, self::TODAY, null));
        self::assertArrayHasKey('amount', ReceivablePayableRules::entryErrors('0', self::TODAY, null));
        self::assertArrayHasKey('amount', ReceivablePayableRules::entryErrors('0.00', self::TODAY, null));
        self::assertArrayHasKey('amount', ReceivablePayableRules::entryErrors('10000000000000', self::TODAY, null));
    }

    public function testDueDateMayEqualButNotPrecedeTheIssueDate(): void
    {
        self::assertSame([], ReceivablePayableRules::entryErrors('10', '2026-10-01', '2026-10-01'));
        self::assertSame([], ReceivablePayableRules::entryErrors('10', '2026-10-01', '2026-11-01'));
        self::assertSame([], ReceivablePayableRules::entryErrors('10', '2026-10-01', ''));
        self::assertSame(
            ['due_date' => 'The due date cannot be before the issue date.'],
            ReceivablePayableRules::entryErrors('10', '2026-10-01', '2026-09-30')
        );
    }

    public function testSettlementDateIsBetweenTheIssueDateAndTodayInclusive(): void
    {
        self::assertSame([], ReceivablePayableRules::settlementErrors('open', '2026-10-01', '2026-10-01', self::TODAY));
        self::assertSame([], ReceivablePayableRules::settlementErrors('open', '2026-10-01', self::TODAY, self::TODAY));
        self::assertArrayHasKey(
            'settled_on',
            ReceivablePayableRules::settlementErrors('open', '2026-10-01', '2026-09-30', self::TODAY)
        );
        self::assertSame(
            ['settled_on' => 'The settlement date cannot be in the future.'],
            ReceivablePayableRules::settlementErrors('open', '2026-10-01', '2026-10-18', self::TODAY)
        );
    }

    public function testSettledOrCancelledEntriesCannotBeSettledAgain(): void
    {
        foreach (['settled', 'cancelled'] as $status) {
            $errors = ReceivablePayableRules::settlementErrors($status, '2026-10-01', self::TODAY, self::TODAY);
            self::assertSame(['status'], array_keys($errors), $status . ' must refuse settlement');
            self::assertStringContainsString('already ' . $status, $errors['status']);
        }
    }

    public function testCancellationNeedsAnOpenEntryAndAReason(): void
    {
        self::assertSame([], ReceivablePayableRules::cancellationErrors('open', 'Raised in error'));
        self::assertArrayHasKey('cancel_reason', ReceivablePayableRules::cancellationErrors('open', ''));
        self::assertArrayHasKey('cancel_reason', ReceivablePayableRules::cancellationErrors('open', "  \t "));
        self::assertArrayHasKey('status', ReceivablePayableRules::cancellationErrors('settled', 'Too late'));
        self::assertArrayHasKey('status', ReceivablePayableRules::cancellationErrors('cancelled', 'Twice'));
    }

    public function testEachKindHasItsOwnSectionAndWording(): void
    {
        self::assertSame('/receivables', ReceivablePayableKind::Receivable->path());
        self::assertSame('/payables', ReceivablePayableKind::Payable->path());
        self::assertSame('Customer', ReceivablePayableKind::Receivable->partyLabel());
        self::assertSame('Vendor', ReceivablePayableKind::Payable->partyLabel());
        self::assertSame('Received', ReceivablePayableKind::Receivable->settledLabel());
        self::assertSame('Paid', ReceivablePayableKind::Payable->settledLabel());
        self::assertNull(ReceivablePayableKind::tryFrom('transaction'));
    }

    public function testLockedFieldsRefuseAnyDifferenceButIgnoreWhatWasNotSent(): void
    {
        $stored = ['amount' => '1500.00', 'issue_date' => '2026-10-01', 'account_id' => 7, 'posted' => true];
        $nothingSent = ['amount' => null, 'issue_date' => null, 'account_id' => null];

        self::assertSame([], ReceivablePayableRules::lockedFieldErrors($stored, $nothingSent));
        self::assertSame([], ReceivablePayableRules::lockedFieldErrors($stored, [
            'amount' => '1500',
            'issue_date' => '2026-10-01',
            'account_id' => 7,
        ]), 'the same values in another spelling are not a change');

        self::assertSame(['amount'], array_keys(ReceivablePayableRules::lockedFieldErrors(
            $stored,
            ['amount' => '1500.01'] + $nothingSent
        )));
        self::assertSame(['issue_date'], array_keys(ReceivablePayableRules::lockedFieldErrors(
            $stored,
            ['issue_date' => '2026-10-02'] + $nothingSent
        )));
        self::assertSame(['account_id'], array_keys(ReceivablePayableRules::lockedFieldErrors(
            $stored,
            ['account_id' => 8] + $nothingSent
        )));
        self::assertStringContainsString('locked once posted', ReceivablePayableRules::lockedFieldErrors(
            $stored,
            ['account_id' => 8] + $nothingSent
        )['account_id']);

        $unposted = ReceivablePayableRules::lockedFieldErrors(
            ['account_id' => null, 'posted' => false] + $stored,
            ['account_id' => 7, 'amount' => '1'] + $nothingSent
        );
        self::assertSame(['amount', 'account_id'], array_keys($unposted), 'an unposted entry is not edited either');
        self::assertStringContainsString('backfill', $unposted['account_id'], 'and the message says why');
        self::assertStringNotContainsString('locked once posted', $unposted['account_id']);
    }

    public function testOnlyASettledEntryCanBeReopenedAndOnlyWithAReason(): void
    {
        self::assertSame([], ReceivablePayableRules::reopenErrors('settled', 'Wrong account'));
        self::assertArrayHasKey('reopen_reason', ReceivablePayableRules::reopenErrors('settled', '  '));
        self::assertArrayHasKey('status', ReceivablePayableRules::reopenErrors('open', 'Wrong account'));
        self::assertArrayHasKey('status', ReceivablePayableRules::reopenErrors('cancelled', 'Wrong account'));
    }

    public function testSettlingNeedsTheIssuePosting(): void
    {
        self::assertSame([], ReceivablePayableRules::postingErrors(42));
        self::assertArrayHasKey('status', ReceivablePayableRules::postingErrors(null));
    }
}
