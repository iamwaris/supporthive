<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Logger;
use App\Models\Transaction;
use App\Services\Mail\MailMessage;
use App\Services\Mail\MailTransport;
use App\Services\Mail\Mailer;
use Throwable;

/**
 * Emails a branch's notification list whenever a transaction is voided.
 *
 * Uses the same list and switch as the expense-added email
 * (ExpenseNotifier::branchRecipients()) rather than a second one. Triggered
 * from TransactionService::void() through Database::afterCommit(), so it only
 * ever describes a void that is durably saved, and it never throws: a mail
 * server being down is logged, and the void still stands.
 */
final class TransactionVoidNotifier
{
    public function __construct(
        private readonly MailTransport $transport,
        private readonly string $fromAddress,
        private readonly string $fromName,
        private readonly string $appUrl,
    ) {
    }

    /**
     * After-commit entry point for one void action. Never throws.
     *
     * $rowsVoided is 2 when voiding one leg took its transfer partner with
     * it; the action is still one email, not one per leg.
     */
    public static function transactionVoided(int $transactionId, int $branchId, int $rowsVoided): void
    {
        try {
            $recipients = ExpenseNotifier::branchRecipients();
            if ($recipients === []) {
                return;
            }

            $details = Transaction::voidNotificationDetails($transactionId, $branchId);
            if ($details === null) {
                return;
            }

            $notifier = new self(
                Mailer::transport(),
                (string) Config::get('mail.from', ''),
                (string) Config::get('mail.from_name', ''),
                (string) Config::get('app.url', '')
            );
            $notifier->notify(
                TransactionVoidNotice::fromRow($details, Settings::string('currency_symbol', 'Rs'), $rowsVoided),
                $recipients
            );
        } catch (Throwable $e) {
            Logger::error('Void notification failed', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param list<string> $recipients
     * @return bool whether the transport accepted the message
     */
    public function notify(TransactionVoidNotice $notice, array $recipients): bool
    {
        if ($recipients === []) {
            return false;
        }

        try {
            $this->transport->send($this->compose($notice, $recipients));
            return true;
        } catch (Throwable $e) {
            Logger::error('Void notification email was not sent', [
                'transaction_id' => $notice->transactionId,
                'recipients' => count($recipients),
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /** @param list<string> $recipients */
    public function compose(TransactionVoidNotice $notice, array $recipients): MailMessage
    {
        $subject = 'Transaction voided: ' . $notice->typeLabel . ' ' . $notice->formattedAmount()
            . ' (' . $notice->branchName . ')';

        $timestamp = strtotime($notice->transactionDate);
        $date = $timestamp === false ? $notice->transactionDate : date('j M Y', $timestamp);
        $voidedStamp = strtotime($notice->voidedAt);
        $voidedAt = $voidedStamp === false ? $notice->voidedAt : date('j M Y, H:i', $voidedStamp);
        $branch = MailMessage::headerText($notice->branchName);

        // Body values pass through headerText() too: it strips CR/LF, so text
        // typed into a description or reason cannot fake extra lines below it.
        $lines = [
            'A transaction was voided in ' . $branch . '.',
            '',
            'Reference:   #' . $notice->transactionId
                . ($notice->referenceNo !== null ? ' (' . MailMessage::headerText($notice->referenceNo) . ')' : ''),
            'Type:        ' . MailMessage::headerText($notice->typeLabel),
            'Date:        ' . $date,
            'Amount:      ' . $notice->formattedAmount(),
        ];
        if ($notice->account !== null) {
            $lines[] = 'Account:     ' . MailMessage::headerText($notice->account);
        }
        if ($notice->category !== null) {
            $lines[] = 'Category:    ' . MailMessage::headerText($notice->category);
        }
        if ($notice->party !== null) {
            $lines[] = 'Party:       ' . MailMessage::headerText($notice->party);
        }
        $lines[] = 'Description: ' . MailMessage::headerText($notice->description);
        $lines[] = '';
        $lines[] = 'Voided by:   ' . MailMessage::headerText($notice->voidedBy);
        $lines[] = 'Voided at:   ' . $voidedAt;
        $lines[] = 'Reason:      ' . MailMessage::headerText($notice->reason);

        if ($notice->rowsVoided > 1) {
            $lines[] = '';
            $lines[] = 'This was one leg of a transfer, so both legs were voided.';
        }

        // Only an absolute base makes a link a mail client can open.
        if (preg_match('#^https?://#i', $this->appUrl) === 1 && $timestamp !== false) {
            $day = date('Y-m-d', $timestamp);
            $lines[] = '';
            $lines[] = 'View voided transactions: ' . rtrim($this->appUrl, '/') . '/transactions?'
                . http_build_query(['status' => 'void', 'from' => $day, 'to' => $day]);
        }

        $lines[] = '';
        $lines[] = 'The original row stays readable; nothing was deleted.';
        $lines[] = '';
        $lines[] = '--';
        $lines[] = 'You are on the notification list for ' . $branch . '.';
        $lines[] = 'An admin can change this list under Settings.';

        return new MailMessage($this->fromAddress, $this->fromName, $recipients, $subject, implode("\n", $lines));
    }
}
