<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Domain\TransactionType;
use InvalidArgumentException;
use RuntimeException;

/**
 * The only way anything writes to the ledger.
 *
 * Nothing inserts into `transactions` directly. Every posting goes through
 * post() or postTransfer(), which means the invariants below hold for every
 * row in the table rather than for every row somebody remembered to check:
 *
 *   - amount is a positive decimal with at most two places, never a float;
 *   - direction comes from the type, not from the caller;
 *   - a category is present exactly when the type needs one, and likewise a
 *     partner;
 *   - the ledger row, its type-specific detail row and its audit entry are
 *     written in ONE database transaction, so a half-recorded movement is not
 *     a state the system can reach.
 */
final class TransactionService
{
    /**
     * Post a single movement.
     *
     * $writeDetail receives the new transaction id and runs inside the same
     * database transaction, so a satellite row (an expense's vendor and
     * receipt, a sale's customer and invoice) either lands with its ledger row
     * or not at all.
     *
     * @param array{
     *     type: TransactionType|string,
     *     amount: string|int,
     *     account_id: int,
     *     transaction_date: string,
     *     description: string,
     *     category_id?: int|null,
     *     partner_id?: int|null,
     *     reference_no?: string|null,
     *     transfer_group?: string|null
     * } $data
     */
    public static function post(array $data, ?callable $writeDetail = null): int
    {
        $type = self::resolveType($data['type']);
        $amount = self::normaliseAmount((string) $data['amount']);
        $date = self::normaliseDate((string) $data['transaction_date']);
        $description = trim((string) $data['description']);

        if ($description === '') {
            throw new InvalidArgumentException('A transaction needs a description.');
        }

        $accountId = (int) $data['account_id'];
        $categoryId = isset($data['category_id']) && (int) $data['category_id'] > 0
            ? (int) $data['category_id']
            : null;
        $partnerId = isset($data['partner_id']) && (int) $data['partner_id'] > 0
            ? (int) $data['partner_id']
            : null;

        self::assertAccountUsable($accountId);

        if ($type->requiresCategory()) {
            if ($categoryId === null) {
                throw new InvalidArgumentException($type->label() . ' needs a category.');
            }
            self::assertCategoryMatchesType($categoryId, $type);
        } else {
            // A transfer or a capital movement has no category by definition.
            // Silently keeping one would let it show up in a category report.
            $categoryId = null;
        }

        if ($type->requiresPartner() && $partnerId === null) {
            throw new InvalidArgumentException($type->label() . ' must name a partner.');
        }
        if (!$type->requiresPartner()) {
            $partnerId = null;
        }

        $userId = Auth::id();
        if ($userId === null) {
            throw new RuntimeException('A transaction must be attributed to a signed-in user.');
        }

        return (int) Database::instance()->transaction(
            static function (Database $db) use (
                $type,
                $amount,
                $date,
                $description,
                $accountId,
                $categoryId,
                $partnerId,
                $data,
                $userId,
                $writeDetail
            ): int {
                $id = $db->insert('transactions', [
                    'transaction_date' => $date,
                    'type' => $type->value,
                    'direction' => $type->direction(),
                    'amount' => $amount,
                    'account_id' => $accountId,
                    'category_id' => $categoryId,
                    'partner_id' => $partnerId,
                    'description' => $description,
                    'reference_no' => self::nullIfBlank($data['reference_no'] ?? null),
                    'status' => 'posted',
                    'transfer_group' => self::nullIfBlank($data['transfer_group'] ?? null),
                    'created_by' => $userId,
                ]);

                if ($writeDetail !== null) {
                    $writeDetail($id);
                }

                Audit::record('transaction.posted', 'transactions', $id, null, [
                    'type' => $type->value,
                    'amount' => $amount,
                    'date' => $date,
                    'account_id' => $accountId,
                    'description' => $description,
                ]);

                return $id;
            }
        );
    }

    /**
     * Record a transfer between two accounts as its two legs.
     *
     * Two rows rather than one: the money out of one account and into another
     * are separate movements, so an account balance stays a plain
     * SUM(amount * direction) with no special case. Neither leg touches the
     * P&L, which is what makes "transfers do not inflate income or expenses"
     * true by construction instead of by a filter somebody has to remember.
     *
     * @return string the transfer group id linking the two legs
     */
    public static function postTransfer(
        int $fromAccountId,
        int $toAccountId,
        string $amount,
        string $date,
        string $description,
        ?string $reference = null
    ): string {
        if ($fromAccountId === $toAccountId) {
            throw new InvalidArgumentException('A transfer needs two different accounts.');
        }

        $group = bin2hex(random_bytes(16));
        $normalised = self::normaliseAmount($amount);
        $when = self::normaliseDate($date);
        $text = trim($description);

        if ($text === '') {
            throw new InvalidArgumentException('A transfer needs a description.');
        }

        Database::instance()->transaction(static function () use (
            $fromAccountId,
            $toAccountId,
            $normalised,
            $when,
            $text,
            $reference,
            $group
        ): void {
            self::post([
                'type' => TransactionType::TransferOut,
                'amount' => $normalised,
                'account_id' => $fromAccountId,
                'transaction_date' => $when,
                'description' => $text,
                'reference_no' => $reference,
                'transfer_group' => $group,
            ]);

            self::post([
                'type' => TransactionType::TransferIn,
                'amount' => $normalised,
                'account_id' => $toAccountId,
                'transaction_date' => $when,
                'description' => $text,
                'reference_no' => $reference,
                'transfer_group' => $group,
            ]);
        });

        Logger::info('Transfer posted', ['group' => $group, 'amount' => $normalised]);

        return $group;
    }

    /**
     * Void a posted transaction.
     *
     * The row stays, with a reason and who did it. Nothing is deleted, and the
     * original figures remain readable.
     *
     * If the transaction is one leg of a transfer, BOTH legs are voided. Half
     * a voided transfer would show money leaving one account and never
     * arriving in the other — a balance that is simply wrong.
     *
     * @return int how many rows were voided
     */
    public static function void(int $transactionId, string $reason): int
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('Voiding a transaction requires a reason.');
        }
        if (mb_strlen($reason) > 255) {
            $reason = mb_substr($reason, 0, 255);
        }

        $db = Database::instance();
        $transaction = $db->first('SELECT * FROM transactions WHERE id = :id', ['id' => $transactionId]);

        if ($transaction === null) {
            throw new RuntimeException('That transaction no longer exists.');
        }
        if ((string) $transaction['status'] === 'void') {
            throw new RuntimeException('That transaction is already voided.');
        }

        $userId = Auth::id();
        if ($userId === null) {
            throw new RuntimeException('A void must be attributed to a signed-in user.');
        }

        $group = self::nullIfBlank($transaction['transfer_group'] ?? null);

        return (int) $db->transaction(static function (Database $db) use (
            $transaction,
            $transactionId,
            $reason,
            $userId,
            $group
        ): int {
            $voidData = [
                'status' => 'void',
                'void_reason' => $reason,
                'voided_by' => $userId,
                'voided_at' => date('Y-m-d H:i:s'),
            ];

            if ($group !== null) {
                $rows = $db->all(
                    "SELECT id FROM transactions WHERE transfer_group = :g AND status = 'posted'",
                    ['g' => $group]
                );

                foreach ($rows as $row) {
                    $db->update('transactions', $voidData, 'id = :id', ['id' => (int) $row['id']]);
                    Audit::record('transaction.voided', 'transactions', (int) $row['id'], null, [
                        'reason' => $reason,
                        'transfer_group' => $group,
                    ]);
                }

                Logger::security('Transfer voided', [
                    'group' => $group,
                    'legs' => count($rows),
                    'by' => $userId,
                ]);

                return count($rows);
            }

            $db->update('transactions', $voidData, 'id = :id', ['id' => $transactionId]);

            Audit::record('transaction.voided', 'transactions', $transactionId, [
                'amount' => $transaction['amount'],
                'type' => $transaction['type'],
            ], ['reason' => $reason]);

            Logger::security('Transaction voided', [
                'transaction_id' => $transactionId,
                'amount' => $transaction['amount'],
                'by' => $userId,
            ]);

            return 1;
        });
    }

    /**
     * An account's balance: its opening balance plus every posted movement.
     *
     * Derived on demand, never stored. A stored balance is a second source of
     * truth, and the moment it disagrees with the ledger there is no way to
     * tell which one is right.
     */
    public static function accountBalance(int $accountId, ?string $asAt = null): string
    {
        $db = Database::instance();

        $opening = $db->value('SELECT opening_balance FROM accounts WHERE id = :id', ['id' => $accountId]);
        if ($opening === null) {
            throw new RuntimeException('No such account.');
        }

        $query = LedgerQuery::posted()->account($accountId);
        if ($asAt !== null) {
            $query->between(null, $asAt);
        }

        // Added as decimal strings in SQL so the total never passes through a
        // float on the way to being displayed.
        $movement = $query->netAmount();

        $total = $db->value(
            'SELECT CAST(:opening AS DECIMAL(15,2)) + CAST(:movement AS DECIMAL(15,2))',
            ['opening' => (string) $opening, 'movement' => $movement]
        );

        return (string) $total;
    }

    // ---------------------------------------------------------------- guards

    private static function resolveType(TransactionType|string $type): TransactionType
    {
        if ($type instanceof TransactionType) {
            return $type;
        }

        $resolved = TransactionType::tryFrom($type);
        if ($resolved === null) {
            throw new InvalidArgumentException("Unknown transaction type: {$type}");
        }

        return $resolved;
    }

    /**
     * Amounts stay strings from form to column.
     *
     * Casting to float to "clean up" an amount is how 1234567.89 becomes
     * 1234567.8899999 and a report stops reconciling. The format is validated
     * and the string is passed through untouched.
     */
    private static function normaliseAmount(string $amount): string
    {
        $amount = trim(str_replace([',', ' '], '', $amount));

        if (preg_match('/^\d{1,13}(\.\d{1,2})?$/', $amount) !== 1) {
            throw new InvalidArgumentException(
                'Enter an amount as digits with at most two decimal places.'
            );
        }

        if (rtrim(rtrim($amount, '0'), '.') === '' || (float) $amount <= 0) {
            throw new InvalidArgumentException('An amount must be greater than zero.');
        }

        return $amount;
    }

    private static function normaliseDate(string $date): string
    {
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            throw new InvalidArgumentException("Not a valid date: {$date}");
        }

        return date('Y-m-d', $timestamp);
    }

    private static function assertAccountUsable(int $accountId): void
    {
        $account = Database::instance()->first(
            'SELECT id, name, is_active FROM accounts WHERE id = :id',
            ['id' => $accountId]
        );

        if ($account === null) {
            throw new InvalidArgumentException('Choose an account.');
        }
        if ((int) $account['is_active'] !== 1) {
            throw new InvalidArgumentException((string) $account['name'] . ' is closed.');
        }
    }

    /** An income category on an expense would quietly corrupt every report. */
    private static function assertCategoryMatchesType(int $categoryId, TransactionType $type): void
    {
        $category = Database::instance()->first(
            'SELECT id, name, type, is_active FROM categories WHERE id = :id',
            ['id' => $categoryId]
        );

        if ($category === null) {
            throw new InvalidArgumentException('Choose a category.');
        }
        if ((int) $category['is_active'] !== 1) {
            throw new InvalidArgumentException((string) $category['name'] . ' is no longer available.');
        }

        $expected = $type->isRevenue() ? 'income' : 'expense';
        if ((string) $category['type'] !== $expected) {
            throw new InvalidArgumentException(
                (string) $category['name'] . ' is an ' . (string) $category['type']
                . ' category and cannot be used on ' . strtolower($type->label()) . '.'
            );
        }
    }

    private static function nullIfBlank(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
