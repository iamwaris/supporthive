<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Domain\ReceivablePayableKind;
use App\Domain\ReceivablePayableRules;
use App\Domain\ReceivablePayableRuleViolation;
use App\Domain\TransactionType;
use App\Models\Account;
use RuntimeException;

/**
 * Every write to the receivables / payables register, and the ledger
 * postings that go with it (decision 2026-10-23, docs/PLAN.md §5).
 *
 * Entries are loans / advances: recording one posts its issue movement
 * (a receivable takes money out of an account, a payable brings it in) and
 * settling posts the return movement. Neither touches the P&L — see
 * TransactionType::affectsProfitAndLoss(). Cancelling voids the issue
 * posting; reopening a settled entry voids its settlement posting so it can
 * be settled again. Every posting and void goes through TransactionService, in the
 * same database transaction as the register write and its audit row, so the
 * entry and the ledger can never disagree.
 *
 * Each change re-reads the row under a lock inside that transaction, and the
 * UPDATE itself still requires the expected status, so two simultaneous settles
 * (or a settle racing a cancel) leave exactly one winner, one posting and
 * one audit entry.
 */
final class ReceivablePayableService
{
    private const TABLE = 'receivables_payables';
    private const ENTITY = 'receivables_payables';

    /**
     * @param array{party_name:string,reference_no:?string,description:?string,
     *              amount:string,issue_date:string,due_date:?string,account_id:int} $fields
     */
    public static function record(ReceivablePayableKind $kind, array $fields): int
    {
        $entry = self::normalise($fields);
        $accountId = $fields['account_id'];
        self::assertNoErrors(ReceivablePayableRules::entryErrors(
            $entry['amount'],
            $entry['issue_date'],
            $entry['due_date']
        ));

        $userId = self::requireUserId();
        $branchId = self::requireBranchId();

        return (int) Database::instance()->transaction(static function (Database $db) use (
            $kind,
            $entry,
            $accountId,
            $userId,
            $branchId
        ): int {
            self::assertAccountUsable($accountId, 'account_id');

            $id = $db->insert(self::TABLE, $entry + [
                'branch_id' => $branchId,
                'kind' => $kind->value,
                'account_id' => $accountId,
                'status' => ReceivablePayableRules::STATUS_OPEN,
                'created_by' => $userId,
            ]);

            $transactionId = self::postMovement(
                $kind->issueType(),
                $id,
                $entry['party_name'],
                $entry['reference_no'],
                $entry['amount'],
                $accountId,
                $entry['issue_date']
            );

            Audit::record($kind->value . '.created', self::ENTITY, $id, null, $entry + [
                'account_id' => $accountId,
                'issue_transaction_id' => $transactionId,
            ]);

            return $id;
        });
    }

    /**
     * Only the descriptive fields change. Amount, issue date and account are
     * locked once posted: $locked carries whatever the request submitted for
     * them (null = not submitted), and any difference is refused.
     *
     * @param array{party_name:string,reference_no:?string,description:?string,due_date:?string} $fields
     * @param array{amount:?string,issue_date:?string,account_id:?int} $locked
     */
    public static function update(int $id, ReceivablePayableKind $kind, array $fields, array $locked): void
    {
        $after = [
            'party_name' => trim($fields['party_name']),
            'reference_no' => self::nullIfBlank($fields['reference_no']),
            'description' => self::nullIfBlank($fields['description']),
            'due_date' => self::nullIfBlank($fields['due_date']),
        ];

        $userId = self::requireUserId();

        Database::instance()->transaction(static function (Database $db) use (
            $id,
            $kind,
            $after,
            $locked,
            $userId
        ): void {
            $before = self::lockEntry($db, $id, $kind, ReceivablePayableRules::editErrors(...));

            self::assertNoErrors(ReceivablePayableRules::lockedFieldErrors([
                'amount' => (string) $before['amount'],
                'issue_date' => (string) $before['issue_date'],
                'account_id' => $before['account_id'] === null ? null : (int) $before['account_id'],
                'posted' => $before['issue_transaction_id'] !== null,
            ], $locked));
            self::assertNoErrors(ReceivablePayableRules::entryErrors(
                (string) $before['amount'],
                (string) $before['issue_date'],
                $after['due_date']
            ));

            self::updateWhileStatus($db, $id, ReceivablePayableRules::STATUS_OPEN, $after + [
                'modified_by' => $userId,
                'modified_at' => date('Y-m-d H:i:s'),
            ]);

            $diff = Audit::diff($before, $after);
            Audit::record($kind->value . '.updated', self::ENTITY, $id, $diff['before'], $diff['after']);
        });
    }

    /** Settle in full: posts the money coming back (receivable) or paid back (payable). */
    public static function settle(
        int $id,
        ReceivablePayableKind $kind,
        string $settledOn,
        ?string $note,
        int $settlementAccountId,
        ?string $today = null
    ): void {
        $today ??= date('Y-m-d');
        $note = self::nullIfBlank($note);
        $userId = self::requireUserId();

        Database::instance()->transaction(static function (Database $db) use (
            $id,
            $kind,
            $settledOn,
            $note,
            $settlementAccountId,
            $today,
            $userId
        ): void {
            $row = self::lockEntry(
                $db,
                $id,
                $kind,
                static fn (string $status, array $row): array => ReceivablePayableRules::settlementErrors(
                    $status,
                    (string) $row['issue_date'],
                    $settledOn,
                    $today
                ) ?: ReceivablePayableRules::postingErrors(
                    $row['issue_transaction_id'] === null ? null : (int) $row['issue_transaction_id']
                )
            );
            self::assertAccountUsable($settlementAccountId, 'settlement_account_id');

            // Status first: the posting's link sets settlement_transaction_id,
            // which chk_rp_settlement_posting only allows on a settled row.
            self::updateWhileStatus($db, $id, ReceivablePayableRules::STATUS_OPEN, [
                'status' => ReceivablePayableRules::STATUS_SETTLED,
                'settled_on' => $settledOn,
                'settlement_note' => $note,
                'settled_by' => $userId,
                'settled_at' => date('Y-m-d H:i:s'),
            ]);

            $transactionId = self::postMovement(
                $kind->settlementType(),
                $id,
                (string) $row['party_name'],
                $row['reference_no'] === null ? null : (string) $row['reference_no'],
                (string) $row['amount'],
                $settlementAccountId,
                $settledOn
            );

            Audit::record(
                $kind->value . '.settled',
                self::ENTITY,
                $id,
                ['status' => ReceivablePayableRules::STATUS_OPEN],
                [
                    'status' => ReceivablePayableRules::STATUS_SETTLED,
                    'settled_on' => $settledOn,
                    'note' => $note,
                    'settlement_account_id' => $settlementAccountId,
                    'settlement_transaction_id' => $transactionId,
                ]
            );
        });
    }

    /**
     * Cancel an open entry and void its issue posting with the same reason,
     * which restores the account balance. An entry recorded before the
     * register posted to the ledger has nothing to void.
     */
    public static function cancel(int $id, ReceivablePayableKind $kind, string $reason): void
    {
        $reason = mb_substr(trim($reason), 0, 255);
        $userId = self::requireUserId();

        Database::instance()->transaction(static function (Database $db) use ($id, $kind, $reason, $userId): void {
            $row = self::lockEntry(
                $db,
                $id,
                $kind,
                static fn (string $status): array => ReceivablePayableRules::cancellationErrors($status, $reason)
            );

            $issueTransactionId = $row['issue_transaction_id'] === null ? null : (int) $row['issue_transaction_id'];
            if ($issueTransactionId !== null) {
                TransactionService::voidRegisterPosting($issueTransactionId, $reason);
            }

            self::updateWhileStatus($db, $id, ReceivablePayableRules::STATUS_OPEN, [
                'status' => ReceivablePayableRules::STATUS_CANCELLED,
                'cancel_reason' => $reason,
                'cancelled_by' => $userId,
                'cancelled_at' => date('Y-m-d H:i:s'),
            ]);

            Audit::record(
                $kind->value . '.cancelled',
                self::ENTITY,
                $id,
                ['status' => ReceivablePayableRules::STATUS_OPEN],
                [
                    'status' => ReceivablePayableRules::STATUS_CANCELLED,
                    'reason' => $reason,
                    'voided_transaction_id' => $issueTransactionId,
                ]
            );
        });
    }

    /**
     * Undo a settlement: void the settlement posting with the reason, clear
     * the settlement, and put the entry back to open — so a settle into the
     * wrong account or on the wrong date can be corrected by settling again.
     * The issue posting is untouched.
     */
    public static function reopen(int $id, ReceivablePayableKind $kind, string $reason): void
    {
        $reason = mb_substr(trim($reason), 0, 255);
        $userId = self::requireUserId();

        Database::instance()->transaction(static function (Database $db) use ($id, $kind, $reason, $userId): void {
            $row = self::lockEntry(
                $db,
                $id,
                $kind,
                static fn (string $status): array => ReceivablePayableRules::reopenErrors($status, $reason)
            );

            $settlementTransactionId = $row['settlement_transaction_id'] === null
                ? null
                : (int) $row['settlement_transaction_id'];
            if ($settlementTransactionId !== null) {
                TransactionService::voidRegisterPosting($settlementTransactionId, $reason);
            }

            self::updateWhileStatus($db, $id, ReceivablePayableRules::STATUS_SETTLED, [
                'status' => ReceivablePayableRules::STATUS_OPEN,
                'settled_on' => null,
                'settlement_account_id' => null,
                'settlement_transaction_id' => null,
                'settlement_note' => null,
                'settled_by' => null,
                'settled_at' => null,
                'modified_by' => $userId,
                'modified_at' => date('Y-m-d H:i:s'),
            ]);

            Audit::record(
                $kind->value . '.reopened',
                self::ENTITY,
                $id,
                [
                    'status' => ReceivablePayableRules::STATUS_SETTLED,
                    'settled_on' => $row['settled_on'],
                    'settlement_account_id' => $row['settlement_account_id'],
                    'settlement_transaction_id' => $settlementTransactionId,
                ],
                ['status' => ReceivablePayableRules::STATUS_OPEN, 'reason' => $reason]
            );
        });
    }

    /**
     * Post one register movement for entry $entryId and link it to that
     * entry (TransactionService::postRegisterMovement() fills the issue or
     * settlement slot in the same database transaction, or rolls back). The
     * description names the entry so the ledger row also reads back to it.
     */
    public static function postMovement(
        TransactionType $type,
        int $entryId,
        string $partyName,
        ?string $reference,
        string $amount,
        int $accountId,
        string $date
    ): int {
        return TransactionService::postRegisterMovement(
            $type,
            $entryId,
            $amount,
            $accountId,
            $date,
            sprintf('%s: %s (register #%d)', $type->label(), $partyName, $entryId),
            $reference
        );
    }

    /**
     * Read the row under a lock and ask the given rule whether it may change.
     *
     * @param callable(string,array<string,mixed>):array<string,string> $rule
     * @return array<string,mixed>
     */
    private static function lockEntry(Database $db, int $id, ReceivablePayableKind $kind, callable $rule): array
    {
        $row = $db->first(
            'SELECT * FROM receivables_payables WHERE id = :id AND branch_id = :branch AND kind = :kind FOR UPDATE',
            ['id' => $id, 'branch' => self::requireBranchId(), 'kind' => $kind->value]
        );

        if ($row === null) {
            throw new ReceivablePayableRuleViolation(['status' => 'That entry no longer exists.']);
        }

        self::assertNoErrors($rule((string) $row['status'], $row));

        return $row;
    }

    /**
     * The row is already locked FOR UPDATE and its status checked by
     * lockEntry(), so no concurrent change can slip in between; the status
     * condition is belt and braces. Zero affected rows is NOT a conflict:
     * MySQL counts changed rows, so an identical re-save (a double submit
     * within the same second) legitimately affects none.
     *
     * @param array<string,mixed> $data
     */
    private static function updateWhileStatus(Database $db, int $id, string $status, array $data): void
    {
        $db->update(
            self::TABLE,
            $data,
            'id = :id AND branch_id = :branch AND status = :expected',
            ['id' => $id, 'branch' => self::requireBranchId(), 'expected' => $status]
        );
    }

    /**
     * The account must be this branch's and still open. Checked here, not
     * only in TransactionService, so the refusal lands next to the field.
     */
    private static function assertAccountUsable(int $accountId, string $field): void
    {
        if ((new Account())->findActive($accountId) === null) {
            throw new ReceivablePayableRuleViolation([$field => 'Choose an active account in this branch.']);
        }
    }

    /**
     * @param array{party_name:string,reference_no:?string,description:?string,
     *              amount:string,issue_date:string,due_date:?string} $fields
     * @return array{party_name:string,reference_no:?string,description:?string,
     *               amount:string,issue_date:string,due_date:?string}
     */
    private static function normalise(array $fields): array
    {
        return [
            'party_name' => trim($fields['party_name']),
            'reference_no' => self::nullIfBlank($fields['reference_no']),
            'description' => self::nullIfBlank($fields['description']),
            // Scale 2, so "1500" is stored and audited as "1500.00".
            'amount' => bcadd(trim($fields['amount']), '0', 2),
            'issue_date' => $fields['issue_date'],
            'due_date' => self::nullIfBlank($fields['due_date']),
        ];
    }

    /** @param array<string,string> $errors */
    private static function assertNoErrors(array $errors): void
    {
        if ($errors !== []) {
            throw new ReceivablePayableRuleViolation($errors);
        }
    }

    private static function nullIfBlank(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === null || $value === '' ? null : $value;
    }

    private static function requireUserId(): int
    {
        $userId = Auth::id();
        if ($userId === null) {
            throw new RuntimeException('A register change must be attributed to a signed-in user.');
        }

        return $userId;
    }

    private static function requireBranchId(): int
    {
        $branchId = Auth::branchId();
        if ($branchId === null) {
            throw new RuntimeException('No active branch — cannot write the receivables / payables register.');
        }

        return $branchId;
    }
}
