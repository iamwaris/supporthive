<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Logger;
use App\Domain\TransactionType;
use App\Models\Expense;
use App\Services\Mail\MailMessage;
use App\Services\Mail\MailTransport;
use App\Services\Mail\Mailer;
use App\Services\Mail\RecipientList;
use Throwable;

/**
 * Emails a branch's chosen addresses whenever an expense lands in its ledger.
 *
 * Triggered from TransactionService::post() through Database::afterCommit(),
 * so it only ever describes an expense that is durably saved, and it never
 * throws: a mail server being down is logged, and the person who recorded
 * the expense still sees it saved.
 */
final class ExpenseNotifier
{
    public const SETTING_ENABLED = 'expense_notify_enabled';
    public const SETTING_RECIPIENTS = 'expense_notify_recipients';

    public function __construct(
        private readonly MailTransport $transport,
        private readonly string $fromAddress,
        private readonly string $fromName,
        private readonly string $appUrl,
    ) {
    }

    public static function appliesTo(TransactionType $type): bool
    {
        return $type === TransactionType::Expense;
    }

    /** After-commit entry point for one posted expense. Never throws. */
    public static function expensePosted(int $transactionId, int $branchId): void
    {
        try {
            $recipients = self::branchRecipients();
            if ($recipients === []) {
                return;
            }

            $details = Expense::notificationDetails($transactionId, $branchId);
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
                ExpenseNotice::fromRow($details, Settings::string('currency_symbol', 'Rs')),
                $recipients
            );
        } catch (Throwable $e) {
            Logger::error('Expense notification failed', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The active branch's notification list, as configured under Settings.
     *
     * Shared with TransactionVoidNotifier: one list per branch covers every
     * ledger email, so an admin never has to keep two lists in step.
     *
     * @return list<string>
     */
    public static function branchRecipients(): array
    {
        return self::recipientsFor(
            Settings::get(self::SETTING_ENABLED, false),
            Settings::string(self::SETTING_RECIPIENTS)
        );
    }

    /**
     * Who to email, given the branch's two settings. Empty means "don't send":
     * switched off, nothing listed, or nothing valid listed.
     *
     * @return list<string>
     */
    public static function recipientsFor(mixed $enabled, string $rawList): array
    {
        if ($enabled !== true) {
            return [];
        }

        // Saved lists were validated on the way in; re-parsing still drops
        // anything a direct database edit slipped past that check.
        return array_slice(RecipientList::parse($rawList)->emails, 0, RecipientList::MAX_RECIPIENTS);
    }

    /**
     * @param list<string> $recipients
     * @return bool whether the transport accepted the message
     */
    public function notify(ExpenseNotice $notice, array $recipients): bool
    {
        if ($recipients === []) {
            return false;
        }

        try {
            $this->transport->send($this->compose($notice, $recipients));
            return true;
        } catch (Throwable $e) {
            Logger::error('Expense notification email was not sent', [
                'transaction_id' => $notice->transactionId,
                'recipients' => count($recipients),
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /** @param list<string> $recipients */
    public function compose(ExpenseNotice $notice, array $recipients): MailMessage
    {
        $subject = 'Expense added: ' . $notice->formattedAmount() . ' - '
            . ($notice->vendor ?? $notice->category) . ' (' . $notice->branchName . ')';

        $timestamp = strtotime($notice->transactionDate);
        $date = $timestamp === false ? $notice->transactionDate : date('j M Y', $timestamp);
        $branch = MailMessage::headerText($notice->branchName);

        $lines = [
            'A new expense was recorded in ' . $branch . '.',
            '',
            'Amount:    ' . $notice->formattedAmount(),
            'Date:      ' . $date,
            'Category:  ' . MailMessage::headerText($notice->category),
            'Vendor:    ' . MailMessage::headerText($notice->vendor ?? '-'),
            'Added by:  ' . MailMessage::headerText($notice->addedBy),
            'How:       ' . $notice->sourceLabel(),
            'Reference: #' . $notice->transactionId,
        ];

        // Only an absolute base makes a link a mail client can open.
        if (preg_match('#^https?://#i', $this->appUrl) === 1 && $timestamp !== false) {
            $lines[] = '';
            $lines[] = 'View expenses: ' . rtrim($this->appUrl, '/') . '/expenses?month=' . date('Y-m', $timestamp);
        }

        $lines[] = '';
        $lines[] = '--';
        $lines[] = 'You are on the notification list for ' . $branch . '.';
        $lines[] = 'An admin can change this list under Settings.';

        return new MailMessage($this->fromAddress, $this->fromName, $recipients, $subject, implode("\n", $lines));
    }
}
