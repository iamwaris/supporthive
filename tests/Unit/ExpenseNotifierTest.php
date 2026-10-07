<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\TransactionType;
use App\Services\ExpenseNotice;
use App\Services\ExpenseNotifier;
use PHPUnit\Framework\TestCase;
use Tests\Support\RecordingMailTransport;

/**
 * The decisions the notifier makes on its own: which ledger types it covers,
 * when the branch settings mean "send", what the email says, and that a
 * failing mail server is swallowed rather than surfaced.
 */
final class ExpenseNotifierTest extends TestCase
{
    private function notice(
        ?string $vendor = 'ABC Traders',
        string $source = ExpenseNotice::SOURCE_MANUAL
    ): ExpenseNotice {
        return new ExpenseNotice(
            42,
            'Main Branch',
            '1250.5',
            'Rs',
            '2026-09-14',
            'Office / Stationery',
            $vendor,
            'Asha Admin',
            $source
        );
    }

    private function notifier(
        RecordingMailTransport $transport,
        string $appUrl = 'https://ledger.example.com'
    ): ExpenseNotifier {
        return new ExpenseNotifier($transport, 'no-reply@example.com', 'LedgerHive', $appUrl);
    }

    public function testOnlyExpensesAreCovered(): void
    {
        foreach (TransactionType::cases() as $type) {
            self::assertSame(
                $type === TransactionType::Expense,
                ExpenseNotifier::appliesTo($type),
                $type->value . ' coverage'
            );
        }
    }

    public function testNothingIsSentWhileSwitchedOff(): void
    {
        self::assertSame([], ExpenseNotifier::recipientsFor(false, 'a@example.com'));
        self::assertSame([], ExpenseNotifier::recipientsFor(null, 'a@example.com'));
        // A string "1" left by a mistyped row is not an explicit yes.
        self::assertSame([], ExpenseNotifier::recipientsFor('1', 'a@example.com'));
    }

    public function testNothingIsSentToAnEmptyList(): void
    {
        self::assertSame([], ExpenseNotifier::recipientsFor(true, ''));
        self::assertSame([], ExpenseNotifier::recipientsFor(true, "not-an-email\n"));
    }

    public function testEnabledListYieldsItsValidAddresses(): void
    {
        self::assertSame(
            ['a@example.com', 'b@example.com'],
            ExpenseNotifier::recipientsFor(true, "a@example.com\nbroken\nb@example.com\nA@example.com")
        );
    }

    public function testNotifySendsOneMessageToEveryRecipient(): void
    {
        $transport = new RecordingMailTransport();

        self::assertTrue($this->notifier($transport)->notify($this->notice(), ['a@example.com', 'b@example.com']));

        self::assertCount(1, $transport->sent);
        self::assertSame(['a@example.com', 'b@example.com'], $transport->sent[0]->to);
    }

    public function testEmptyRecipientListSendsNothing(): void
    {
        $transport = new RecordingMailTransport();

        self::assertFalse($this->notifier($transport)->notify($this->notice(), []));
        self::assertSame(0, $transport->attempts);
    }

    public function testTransportFailureIsSwallowed(): void
    {
        $transport = new RecordingMailTransport(failing: true);

        self::assertFalse($this->notifier($transport)->notify($this->notice(), ['a@example.com']));
        self::assertSame(1, $transport->attempts);
    }

    public function testMessageCarriesTheExpenseFacts(): void
    {
        $message = $this->notifier(new RecordingMailTransport())->compose($this->notice(), ['a@example.com']);

        self::assertSame('Expense added: Rs 1,250.50 - ABC Traders (Main Branch)', $message->subject);
        foreach (
            [
                'Main Branch', 'Rs 1,250.50', '14 Sep 2026', 'Office / Stationery', 'ABC Traders',
                'Asha Admin', 'Manual entry', '#42',
                'https://ledger.example.com/expenses?month=2026-09',
            ] as $expected
        ) {
            self::assertStringContainsString($expected, $message->textBody);
        }
    }

    public function testRecurringSourceAndCategoryFallbackInSubject(): void
    {
        $message = $this->notifier(new RecordingMailTransport())
            ->compose($this->notice(null, ExpenseNotice::SOURCE_RECURRING), ['a@example.com']);

        self::assertStringContainsString('Office / Stationery (Main Branch)', $message->subject);
        self::assertStringContainsString('Approved recurring transaction', $message->textBody);
    }

    public function testNoLinkWithoutAnAbsoluteAppUrl(): void
    {
        $message = $this->notifier(new RecordingMailTransport(), '/SupportHive/public')
            ->compose($this->notice(), ['a@example.com']);

        self::assertStringNotContainsString('View expenses', $message->textBody);
    }
}
