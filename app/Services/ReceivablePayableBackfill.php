<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Domain\ReceivablePayableKind;
use App\Domain\ReceivablePayableRules;
use App\Models\Account;
use Throwable;

/**
 * One-time posting of register entries recorded before AR/AP-3 put the
 * register on the ledger (scripts/backfill-receivables-payables.php).
 *
 *   - open      -> the issue posting, dated issue_date;
 *   - settled   -> the issue posting on issue_date AND the settlement
 *                  posting on settled_on;
 *   - cancelled -> nothing.
 *
 * Every posting goes through the branch's Cash account
 * (Account::cashAccountId(): an active cash-type account named "Cash", else
 * the lowest-id active cash-type account). A branch with none is skipped and
 * reported — an account is never invented.
 *
 * Idempotent: only entries still missing a posting are selected, each is
 * re-read under a row lock inside its own database transaction, and it is
 * posted as the user who recorded it, with an audit row, exactly as
 * TransactionService posts anything else. Running it again posts nothing.
 */
final class ReceivablePayableBackfill
{
    /**
     * @return array{
     *     posted: list<array{id:int,branch_id:int,kind:string,status:string,
     *                        issue_transaction_id:?int,settlement_transaction_id:?int}>,
     *     skipped: list<array{id:int,branch_id:int,branch_name:string,kind:string,status:string,reason:string}>,
     *     failed: list<array{id:int,branch_id:int,kind:string,status:string,error:string}>
     * }
     */
    public static function run(bool $dryRun = false): array
    {
        $report = ['posted' => [], 'skipped' => [], 'failed' => []];
        $cashAccounts = [];

        foreach (self::pendingEntries() as $entry) {
            $id = (int) $entry['id'];
            $branchId = (int) $entry['branch_id'];
            $summary = [
                'id' => $id,
                'branch_id' => $branchId,
                'kind' => (string) $entry['kind'],
                'status' => (string) $entry['status'],
            ];

            if (!array_key_exists($branchId, $cashAccounts)) {
                $cashAccounts[$branchId] = (new Account())->cashAccountId($branchId);
            }
            $cashAccountId = $cashAccounts[$branchId];

            if ($cashAccountId === null) {
                $report['skipped'][] = $summary + [
                    'branch_name' => (string) $entry['branch_name'],
                    'reason' => 'the branch has no active cash-type account',
                ];
                continue;
            }

            if ($dryRun) {
                $report['posted'][] = $summary + ['issue_transaction_id' => null, 'settlement_transaction_id' => null];
                continue;
            }

            try {
                $result = self::backfillEntry($id, $branchId, (int) $entry['created_by'], $cashAccountId);
                if ($result !== null) {
                    $report['posted'][] = $summary + $result;
                }
            } catch (Throwable $e) {
                $report['failed'][] = $summary + ['error' => $e->getMessage()];
            }
        }

        return $report;
    }

    /** @return list<array<string,mixed>> */
    private static function pendingEntries(): array
    {
        return Database::instance()->all(
            "SELECT r.id, r.branch_id, r.kind, r.status, r.created_by, b.name AS branch_name
             FROM receivables_payables r
             JOIN branches b ON b.id = r.branch_id
             WHERE (r.status = :open AND r.issue_transaction_id IS NULL)
                OR (r.status = :settled
                    AND (r.issue_transaction_id IS NULL OR r.settlement_transaction_id IS NULL))
             ORDER BY r.branch_id, r.id",
            ['open' => ReceivablePayableRules::STATUS_OPEN, 'settled' => ReceivablePayableRules::STATUS_SETTLED]
        );
    }

    /**
     * Post whatever this entry is still missing, as its creator, in one
     * database transaction with the link columns and an audit row.
     *
     * @return array{issue_transaction_id:?int,settlement_transaction_id:?int}|null null when nothing was missing
     */
    private static function backfillEntry(int $id, int $branchId, int $createdBy, int $cashAccountId): ?array
    {
        $post = static function () use ($id, $branchId, $cashAccountId): ?array {
            return Database::instance()->transaction(static function (Database $db) use (
                $id,
                $branchId,
                $cashAccountId
            ): ?array {
                $row = $db->first(
                    'SELECT * FROM receivables_payables WHERE id = :id AND branch_id = :branch FOR UPDATE',
                    ['id' => $id, 'branch' => $branchId]
                );
                if ($row === null) {
                    return null;
                }

                $kind = ReceivablePayableKind::from((string) $row['kind']);
                $status = (string) $row['status'];
                $partyName = (string) $row['party_name'];
                $reference = $row['reference_no'] === null ? null : (string) $row['reference_no'];
                $amount = (string) $row['amount'];
                $changes = [];

                $isPostable = $status === ReceivablePayableRules::STATUS_OPEN
                    || $status === ReceivablePayableRules::STATUS_SETTLED;

                if ($isPostable && $row['issue_transaction_id'] === null) {
                    $accountId = $row['account_id'] === null ? $cashAccountId : (int) $row['account_id'];
                    $changes['account_id'] = $accountId;
                    $changes['issue_transaction_id'] = ReceivablePayableService::postMovement(
                        $kind->issueType(),
                        $id,
                        $partyName,
                        $reference,
                        $amount,
                        $accountId,
                        (string) $row['issue_date']
                    );
                }

                if ($status === ReceivablePayableRules::STATUS_SETTLED && $row['settlement_transaction_id'] === null) {
                    $accountId = $row['settlement_account_id'] === null
                        ? $cashAccountId
                        : (int) $row['settlement_account_id'];
                    $changes['settlement_account_id'] = $accountId;
                    $changes['settlement_transaction_id'] = ReceivablePayableService::postMovement(
                        $kind->settlementType(),
                        $id,
                        $partyName,
                        $reference,
                        $amount,
                        $accountId,
                        (string) $row['settled_on']
                    );
                }

                if ($changes === []) {
                    return null;
                }

                $db->update('receivables_payables', $changes, 'id = :id', ['id' => $id]);
                Audit::record($kind->value . '.backfilled', 'receivables_payables', $id, null, $changes);

                return [
                    'issue_transaction_id' => $changes['issue_transaction_id'] ?? null,
                    'settlement_transaction_id' => $changes['settlement_transaction_id'] ?? null,
                ];
            });
        };

        /** @var array{issue_transaction_id:?int,settlement_transaction_id:?int}|null $result */
        $result = Auth::actAsInCli($createdBy, $branchId, $post);

        return $result;
    }
}
