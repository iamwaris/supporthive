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
 *   - settled   -> whichever of the issue posting (issue_date) and the
 *                  settlement posting (settled_on) is still missing;
 *   - cancelled -> nothing.
 *
 * Every posting goes through the branch's Cash account — exactly the active
 * cash-type account named "Cash" (Account::cashAccountId()). A branch without
 * one is skipped and reported with its cash-type candidates; an account is
 * never guessed or invented.
 *
 * Idempotent: only entries still missing a posting are selected, each is
 * re-read under a row lock inside its own database transaction, posted as
 * the user who recorded it and linked to the entry by
 * TransactionService::postRegisterMovement(), with an audit row. A failing
 * entry rolls back alone and the run carries on. Running it again posts
 * nothing.
 */
final class ReceivablePayableBackfill
{
    /**
     * @param int|null $branchId limit the run to one branch; null = every branch
     * @return array{
     *     posted: list<array{id:int,branch_id:int,kind:string,status:string,account_id:int,account_name:string,
     *                        issue_transaction_id:?int,settlement_transaction_id:?int}>,
     *     failed: list<array{id:int,branch_id:int,kind:string,status:string,account_id:int,account_name:string,
     *                        error:string}>,
     *     branches: list<array{branch_id:int,branch_name:string,account_id:?int,account_name:?string,
     *                          entries:list<array{id:int,kind:string,status:string}>,
     *                          candidates:list<array{id:int,name:string,is_active:bool}>}>
     * }
     */
    public static function run(bool $dryRun = false, ?int $branchId = null): array
    {
        $report = ['posted' => [], 'failed' => [], 'branches' => []];
        $accounts = new Account();

        foreach (self::pendingEntriesByBranch($branchId) as $branch => $entries) {
            $cashAccountId = $accounts->cashAccountId($branch);
            $cashAccountName = null;
            $candidates = [];
            if ($cashAccountId !== null) {
                $cashAccountName = (string) Database::instance()->value(
                    'SELECT name FROM accounts WHERE id = :id',
                    ['id' => $cashAccountId]
                );
            } else {
                $candidates = array_map(static fn (array $account): array => [
                    'id' => (int) $account['id'],
                    'name' => (string) $account['name'],
                    'is_active' => (int) $account['is_active'] === 1,
                ], $accounts->cashTypeAccounts($branch));
            }

            $report['branches'][] = [
                'branch_id' => $branch,
                'branch_name' => (string) $entries[0]['branch_name'],
                'account_id' => $cashAccountId,
                'account_name' => $cashAccountName,
                'entries' => array_map(static fn (array $entry): array => [
                    'id' => (int) $entry['id'],
                    'kind' => (string) $entry['kind'],
                    'status' => (string) $entry['status'],
                ], $entries),
                'candidates' => $candidates,
            ];

            if ($cashAccountId === null || $cashAccountName === null) {
                continue;
            }

            foreach ($entries as $entry) {
                $summary = [
                    'id' => (int) $entry['id'],
                    'branch_id' => $branch,
                    'kind' => (string) $entry['kind'],
                    'status' => (string) $entry['status'],
                    'account_id' => $cashAccountId,
                    'account_name' => $cashAccountName,
                ];

                if ($dryRun) {
                    $report['posted'][] = $summary
                        + ['issue_transaction_id' => null, 'settlement_transaction_id' => null];
                    continue;
                }

                try {
                    $result = self::backfillEntry($summary['id'], $branch, (int) $entry['created_by'], $cashAccountId);
                    if ($result !== null) {
                        $report['posted'][] = $summary + $result;
                    }
                } catch (Throwable $e) {
                    $report['failed'][] = $summary + ['error' => $e->getMessage()];
                }
            }
        }

        return $report;
    }

    /** @return array<int,non-empty-list<array<string,mixed>>> branch id => its pending entries */
    private static function pendingEntriesByBranch(?int $branchId): array
    {
        $sql = "SELECT r.id, r.branch_id, r.kind, r.status, r.created_by, b.name AS branch_name
                FROM receivables_payables r
                JOIN branches b ON b.id = r.branch_id
                WHERE ((r.status = :open AND r.issue_transaction_id IS NULL)
                    OR (r.status = :settled
                        AND (r.issue_transaction_id IS NULL OR r.settlement_transaction_id IS NULL)))";
        $params = ['open' => ReceivablePayableRules::STATUS_OPEN, 'settled' => ReceivablePayableRules::STATUS_SETTLED];
        if ($branchId !== null) {
            $sql .= ' AND r.branch_id = :branch';
            $params['branch'] = $branchId;
        }

        $byBranch = [];
        foreach (Database::instance()->all($sql . ' ORDER BY r.branch_id, r.id', $params) as $entry) {
            $byBranch[(int) $entry['branch_id']][] = $entry;
        }

        return $byBranch;
    }

    /**
     * Post whatever this entry is still missing, as its creator, in one
     * database transaction with the links and an audit row.
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
