<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TransactionVoidNotice;
use App\Services\TransactionVoidNotifier;
use PHPUnit\Framework\TestCase;
use Tests\Support\RecordingMailTransport;

/**
 * What the void email says and how the notifier treats its transport:
 * the key facts are present, user-typed text cannot add lines, the link only
 * appears with an absolute base, and a failing mail server is swallowed.
 */
final class TransactionVoidNotifierTest extends TestCase
{
    /** @param array<string,mixed> $overrides */
    private function notice(array $overrides = []): TransactionVoidNotice
    {
        $row = $overrides + [
            'id' => 42,
            'type' => 'expense',
            'amount' => '1250.5',
            'transaction_date' => '2026-09-14',
            'description' => 'Printer paper',
            'reference_no' => 'INV-77',
            'void_reason' => 'Entered twice',
            'voided_at' => '2026-10-09 15:04:00',
            'branch_name' => 'Main Branch',
            'account_name' => 'HBL Current',
            'category_name' => 'Stationery',
            'parent_category_name' => 'Office',
            'partner_name' => null,
            'customer_name' => null,
            'vendor' => 'ABC Traders',
            'voided_by_name' => 'Asha Admin',
        ];

        return TransactionVoidNotice::fromRow($row, 'Rs', (int) ($overrides['rows'] ?? 1));
    }

    private function notifier(
        RecordingMailTransport $transport,
        string $appUrl = 'https://ledger.example.com'
    ): TransactionVoidNotifier {
        return new TransactionVoidNotifier($transport, 'no-reply@example.com', 'LedgerHive', $appUrl);
    }

    public function testMessageCarriesTheVoidFacts(): void
    {
        $message = $this->notifier(new RecordingMailTransport())->compose($this->notice(), ['a@example.com']);

        self::assertSame('Transaction voided: Expense Rs 1,250.50 (Main Branch)', $message->subject);
        foreach (
            [
                'Main Branch', '#42 (INV-77)', 'Expense', '14 Sep 2026', 'Rs 1,250.50', 'HBL Current',
                'Office / Stationery', 'ABC Traders', 'Printer paper', 'Asha Admin', '9 Oct 2026, 15:04',
                'Entered twice', 'https://ledger.example.com/transactions?status=void&from=2026-09-14&to=2026-09-14',
            ] as $expected
        ) {
            self::assertStringContainsString($expected, $message->textBody);
        }
        self::assertStringNotContainsString('both legs', $message->textBody);
    }

    public function testPartnerTypeShowsThePartnerAndNoCategory(): void
    {
        $message = $this->notifier(new RecordingMailTransport())->compose($this->notice([
            'type' => 'partner_withdrawal',
            'category_name' => null,
            'parent_category_name' => null,
            'vendor' => null,
            'partner_name' => 'Bilal Partner',
        ]), ['a@example.com']);

        self::assertStringStartsWith('Transaction voided: Withdrawal Rs 1,250.50', $message->subject);
        self::assertStringContainsString('Bilal Partner', $message->textBody);
        self::assertStringNotContainsString('Category:', $message->textBody);
    }

    public function testTransferSaysBothLegsWereVoided(): void
    {
        $message = $this->notifier(new RecordingMailTransport())
            ->compose($this->notice(['type' => 'transfer_out', 'rows' => 2]), ['a@example.com']);

        self::assertStringContainsString('Transfer out', $message->subject);
        self::assertStringContainsString('both legs were voided', $message->textBody);
    }

    public function testTypedTextCannotAddLinesToTheBody(): void
    {
        $message = $this->notifier(new RecordingMailTransport())->compose(
            $this->notice(['void_reason' => "Typo\nVoided by: Someone Else", 'description' => "Paper\r\nAmount: Rs 0"]),
            ['a@example.com']
        );

        self::assertStringContainsString('Reason:      Typo Voided by: Someone Else', $message->textBody);
        self::assertStringContainsString('Description: Paper Amount: Rs 0', $message->textBody);
        self::assertSame(1, substr_count($message->textBody, "\nVoided by:"));
    }

    public function testNoLinkWithoutAnAbsoluteAppUrl(): void
    {
        $message = $this->notifier(new RecordingMailTransport(), '/SupportHive/public')
            ->compose($this->notice(), ['a@example.com']);

        self::assertStringNotContainsString('View voided transactions', $message->textBody);
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
}
