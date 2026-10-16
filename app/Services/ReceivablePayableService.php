<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Domain\ReceivablePayableKind;
use App\Domain\ReceivablePayableRules;
use App\Domain\ReceivablePayableRuleViolation;
use RuntimeException;

/**
 * Every write to the receivables / payables register.
 *
 * Independent of the ledger by decision (docs/PLAN.md §5, 2026-10-17):
 * nothing here touches `transactions`, TransactionService, balances or
 * reports. Settling an entry records that it was settled — it does not post
 * the money.
 *
 * Each change re-reads the row under a lock inside the same database
 * transaction as its audit row, and the UPDATE itself still requires
 * status = 'open', so two simultaneous settles (or a settle racing a cancel)
 * leave exactly one winner and one audit entry.
 */
final class ReceivablePayableService
{
    private const TABLE = 'receivables_payables';
    private const ENTITY = 'receivables_payables';

    /**
     * @param array{party_name:string,reference_no:?string,description:?string,
     *              amount:string,issue_date:string,due_date:?string} $fields
     */
    public static function record(ReceivablePayableKind $kind, array $fields): int
    {
        $entry = self::normalise($fields);
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
            $userId,
            $branchId
        ): int {
            $id = $db->insert(self::TABLE, $entry + [
                'branch_id' => $branchId,
                'kind' => $kind->value,
                'status' => ReceivablePayableRules::STATUS_OPEN,
                'created_by' => $userId,
            ]);
            Audit::record($kind->value . '.created', self::ENTITY, $id, null, $entry);

            return $id;
        });
    }

    /**
     * @param array{party_name:string,reference_no:?string,description:?string,
     *              amount:string,issue_date:string,due_date:?string} $fields
     */
    public static function update(int $id, ReceivablePayableKind $kind, array $fields): void
    {
        $after = self::normalise($fields);
        self::assertNoErrors(ReceivablePayableRules::entryErrors(
            $after['amount'],
            $after['issue_date'],
            $after['due_date']
        ));

        $userId = self::requireUserId();

        Database::instance()->transaction(static function (Database $db) use ($id, $kind, $after, $userId): void {
            $before = self::lockOpenEntry($db, $id, $kind, ReceivablePayableRules::editErrors(...));

            self::updateWhileOpen($db, $id, $after + [
                'modified_by' => $userId,
                'modified_at' => date('Y-m-d H:i:s'),
            ]);

            $diff = Audit::diff($before, $after);
            Audit::record($kind->value . '.updated', self::ENTITY, $id, $diff['before'], $diff['after']);
        });
    }

    public static function settle(
        int $id,
        ReceivablePayableKind $kind,
        string $settledOn,
        ?string $note,
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
            $today,
            $userId
        ): void {
            self::lockOpenEntry(
                $db,
                $id,
                $kind,
                static fn (string $status, array $row): array => ReceivablePayableRules::settlementErrors(
                    $status,
                    (string) $row['issue_date'],
                    $settledOn,
                    $today
                )
            );

            $settlement = [
                'status' => ReceivablePayableRules::STATUS_SETTLED,
                'settled_on' => $settledOn,
                'settlement_note' => $note,
                'settled_by' => $userId,
                'settled_at' => date('Y-m-d H:i:s'),
            ];
            self::updateWhileOpen($db, $id, $settlement);

            Audit::record(
                $kind->value . '.settled',
                self::ENTITY,
                $id,
                ['status' => ReceivablePayableRules::STATUS_OPEN],
                ['status' => ReceivablePayableRules::STATUS_SETTLED, 'settled_on' => $settledOn, 'note' => $note]
            );
        });
    }

    public static function cancel(int $id, ReceivablePayableKind $kind, string $reason): void
    {
        $reason = mb_substr(trim($reason), 0, 255);
        $userId = self::requireUserId();

        Database::instance()->transaction(static function (Database $db) use ($id, $kind, $reason, $userId): void {
            self::lockOpenEntry(
                $db,
                $id,
                $kind,
                static fn (string $status): array => ReceivablePayableRules::cancellationErrors($status, $reason)
            );

            self::updateWhileOpen($db, $id, [
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
                ['status' => ReceivablePayableRules::STATUS_CANCELLED, 'reason' => $reason]
            );
        });
    }

    /**
     * Read the row under a lock and ask the given rule whether it may change.
     *
     * @param callable(string,array<string,mixed>):array<string,string> $rule
     * @return array<string,mixed>
     */
    private static function lockOpenEntry(Database $db, int $id, ReceivablePayableKind $kind, callable $rule): array
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
     * The row is already locked FOR UPDATE and checked open by
     * lockOpenEntry(), so no concurrent change can slip in between; the
     * status condition is belt and braces. Zero affected rows is NOT a
     * conflict: MySQL counts changed rows, so an identical re-save (a double
     * submit within the same second) legitimately affects none.
     *
     * @param array<string,mixed> $data
     */
    private static function updateWhileOpen(Database $db, int $id, array $data): void
    {
        $db->update(
            self::TABLE,
            $data,
            'id = :id AND branch_id = :branch AND status = :open',
            ['id' => $id, 'branch' => self::requireBranchId(), 'open' => ReceivablePayableRules::STATUS_OPEN]
        );
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
            // Scale 2, so an unchanged "1500" never shows as a change from
            // the stored "1500.00" in the audit diff.
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
