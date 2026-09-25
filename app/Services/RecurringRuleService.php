<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Models\Expense;
use App\Models\RecurringOccurrence;
use App\Models\RecurringRule;
use App\Models\Sale;
use InvalidArgumentException;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Generates draft `recurring_occurrences` rows from `recurring_rules` —
 * the nightly catch-up job, called per-branch from
 * scripts/generate-recurring-transactions.php.
 *
 * This deliberately does NOT touch `transactions`, does NOT call
 * TransactionService, and does NOT call Audit::record(). That is not an
 * oversight:
 *
 *   - A generated draft is not a ledger entry — see the migration's own
 *     comment on why `recurring_occurrences` is a separate table rather
 *     than a `transactions` row with a new status. Nothing here is allowed
 *     to write to `transactions` directly; only the (future) approval flow,
 *     going through TransactionService::post() like every other ledger
 *     write, is.
 *   - Audit::record() reads Auth::id()/Auth::branchId() from the session.
 *     This class runs from a CLI cron with no session at all, so both would
 *     be null, and every branch's generated drafts would be audited under
 *     the same attribution-free, branch-less row — corrupting the audit
 *     trail for every tenant at once instead of just being absent for this
 *     one system action. Logger::info()/Logger::error() below are the
 *     correct substitute for an unattended, cross-tenant process: they say
 *     what happened without pretending a human did it.
 */
final class RecurringRuleService
{
    /**
     * Generate every occurrence a branch's active rules are due for, as of
     * $asOf. Returns how many occurrence rows were actually inserted.
     *
     * Each rule's inserts and cursor advance run inside one
     * Database::transaction(), so a rule either fully generates or not at
     * all; one rule failing (an unexpected error, not the ordinary
     * duplicate-key case handled below) is logged and does not stop the
     * remaining rules in this branch from generating.
     */
    public static function generateForBranch(int $branchId, string $asOf): int
    {
        $ruleModel = new RecurringRule();
        $occurrenceModel = new RecurringOccurrence();
        $db = Database::instance();

        $generated = 0;

        foreach ($ruleModel->activeForBranch($branchId) as $rule) {
            $ruleId = (int) $rule['id'];
            $effectiveFrom = self::effectiveFrom((string) $rule['start_date'], (string) $rule['created_at']);

            $dueDates = RecurringSchedule::occurrencesDue(
                (string) $rule['frequency'],
                $rule['day_of_month'] !== null ? (int) $rule['day_of_month'] : null,
                $rule['day_of_week'] !== null ? (int) $rule['day_of_week'] : null,
                (string) $rule['start_date'],
                $effectiveFrom,
                $rule['end_date'] !== null ? (string) $rule['end_date'] : null,
                $rule['last_generated_date'] !== null ? (string) $rule['last_generated_date'] : null,
                $asOf
            );

            if ($dueDates === []) {
                continue;
            }

            try {
                $generated += (int) $db->transaction(
                    static function (Database $db) use (
                        $rule,
                        $ruleId,
                        $branchId,
                        $dueDates,
                        $occurrenceModel,
                        $ruleModel
                    ): int {
                        $insertedCount = 0;

                        foreach ($dueDates as $occurrenceDate) {
                            try {
                                $occurrenceModel->createForBranch($branchId, [
                                    'rule_id' => $ruleId,
                                    'occurrence_date' => $occurrenceDate,
                                    'type' => $rule['type'],
                                    'amount' => $rule['amount'],
                                    'account_id' => $rule['account_id'],
                                    'category_id' => $rule['category_id'],
                                    'description' => $rule['description'],
                                    'vendor' => $rule['vendor'],
                                    'customer_id' => $rule['customer_id'],
                                    'reference_no' => $rule['reference_no'],
                                    'notes' => $rule['notes'],
                                ]);
                                $insertedCount++;
                            } catch (PDOException $e) {
                                if (!self::isOccurrenceAlreadyGenerated($e)) {
                                    throw $e;
                                }

                                // The idempotency backstop, not an error: a
                                // duplicate here means this exact rule/date
                                // was already generated (the cursor was
                                // wrong, or this run overlaps a prior one).
                                // Skip it and keep going with the rest of
                                // this rule's due dates.
                                Logger::info('Recurring occurrence already generated, skipping', [
                                    'rule_id' => $ruleId,
                                    'occurrence_date' => $occurrenceDate,
                                ]);
                            }
                        }

                        // Advance to the last due date regardless of whether
                        // it was newly inserted or already existed — either
                        // way it is covered, and the cursor's job is to mark
                        // the catch-up point, not to count new rows.
                        $ruleModel->advanceCursor($branchId, $ruleId, $dueDates[array_key_last($dueDates)]);

                        return $insertedCount;
                    }
                );
            } catch (Throwable $e) {
                Logger::error('Recurring rule generation failed', [
                    'branch_id' => $branchId,
                    'rule_id' => $ruleId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $generated;
    }

    /**
     * GREATEST(start_date, DATE(created_at)) — the "no backfill on
     * creation" floor RecurringSchedule::occurrencesDue() expects its caller
     * to compute. Both inputs are already Y-m-d-prefixed strings straight
     * from DATE/DATETIME columns, so a plain string comparison is exact; no
     * date parsing (i.e. no reimplementing anything RecurringSchedule
     * already owns) is needed to take the later of the two.
     */
    private static function effectiveFrom(string $startDate, string $createdAt): string
    {
        $createdDate = substr($createdAt, 0, 10);

        return $startDate > $createdDate ? $startDate : $createdDate;
    }

    /**
     * True only for the specific unique-key violation
     * `uq_occurrence_rule_date` is there to catch (MySQL error 1062,
     * "Duplicate entry"), never for any other constraint failure — those
     * must still surface as real errors instead of being silently
     * swallowed.
     */
    private static function isOccurrenceAlreadyGenerated(PDOException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062
            && str_contains($e->getMessage(), 'uq_occurrence_rule_date');
    }

    // ------------------------------------------------------------- web path
    //
    // Everything below runs inside an authenticated request (Auth::branchId()
    // is available), unlike generateForBranch() above — it backs the rule
    // CRUD screen, not the cron.

    /**
     * Validate that account_id/category_id (and customer_id, when given)
     * belong to the current branch, are active, and the category's type
     * matches the rule's type. The same ownership + type-match guarantee
     * TransactionService::post() gives every one-off entry — a recurring
     * rule is a template for exactly that entry, so it must not be allowed
     * to reference another branch's master data or a mismatched category.
     *
     * Throws InvalidArgumentException with a user-facing message on failure;
     * the caller flashes it the same way ExpenseController/IncomeController
     * flash a TransactionService failure.
     */
    public static function assertReferencesUsable(
        int $accountId,
        int $categoryId,
        string $type,
        ?int $customerId
    ): void {
        $branchId = self::requireWebBranchId();
        $db = Database::instance();

        $account = $db->first(
            'SELECT id, name, is_active FROM accounts WHERE id = :id AND branch_id = :branch',
            ['id' => $accountId, 'branch' => $branchId]
        );
        if ($account === null) {
            throw new InvalidArgumentException('Choose an account.');
        }
        if ((int) $account['is_active'] !== 1) {
            throw new InvalidArgumentException((string) $account['name'] . ' is closed.');
        }

        $category = $db->first(
            'SELECT id, name, type, is_active FROM categories WHERE id = :id AND branch_id = :branch',
            ['id' => $categoryId, 'branch' => $branchId]
        );
        if ($category === null) {
            throw new InvalidArgumentException('Choose a category.');
        }
        if ((int) $category['is_active'] !== 1) {
            throw new InvalidArgumentException((string) $category['name'] . ' is no longer available.');
        }
        if ((string) $category['type'] !== $type) {
            throw new InvalidArgumentException(
                (string) $category['name'] . ' is a ' . (string) $category['type']
                . ' category and cannot be used on a ' . $type . ' rule.'
            );
        }

        if ($customerId !== null) {
            $customer = $db->first(
                'SELECT id FROM customers WHERE id = :id AND branch_id = :branch',
                ['id' => $customerId, 'branch' => $branchId]
            );
            if ($customer === null) {
                throw new InvalidArgumentException('Choose a customer.');
            }
        }
    }

    /**
     * Pause or resume a rule. Resuming (inactive -> active) also advances
     * last_generated_date to today via RecurringRule::resume(), so the
     * paused window is never backfilled by the next generator run.
     *
     * @return array{is_active:int,last_generated_date?:string} the new state, for the caller's audit diff
     */
    public static function toggleActive(RecurringRule $rules, int $id, bool $currentlyActive): array
    {
        if ($currentlyActive) {
            $rules->pause($id);

            return ['is_active' => 0];
        }

        $today = date('Y-m-d');
        $rules->resume($id);

        return ['is_active' => 1, 'last_generated_date' => $today];
    }

    /**
     * The next occurrence date a rule owes, for the rule-list screen's
     * "next due" column — cheap because it reuses RecurringSchedule's own
     * bounded stepping rather than any new date math, over a fixed
     * 400-day horizon (long enough to always surface at least one date for
     * every supported frequency, including yearly). Returns null for a
     * rule with nothing due in that horizon (e.g. already past its
     * end_date).
     *
     * Deliberately NOT reused by generateForBranch(): that method's
     * $asOf is the real catch-up ceiling ("what is due to be generated
     * today"), while this one is a display-only lookahead and must never
     * influence what actually gets generated.
     *
     * @param array<string,mixed> $rule
     */
    public static function nextOccurrenceDate(array $rule, string $asOf): ?string
    {
        $effectiveFrom = self::effectiveFrom((string) $rule['start_date'], (string) $rule['created_at']);

        $horizon = date('Y-m-d', strtotime($asOf . ' +400 days'));
        if ($rule['end_date'] !== null && (string) $rule['end_date'] < $horizon) {
            $horizon = (string) $rule['end_date'];
        }
        if ($horizon < $asOf) {
            // end_date has already passed — nothing left to show.
            return null;
        }

        $dueDates = RecurringSchedule::occurrencesDue(
            (string) $rule['frequency'],
            $rule['day_of_month'] !== null ? (int) $rule['day_of_month'] : null,
            $rule['day_of_week'] !== null ? (int) $rule['day_of_week'] : null,
            (string) $rule['start_date'],
            $effectiveFrom,
            $rule['end_date'] !== null ? (string) $rule['end_date'] : null,
            $rule['last_generated_date'] !== null ? (string) $rule['last_generated_date'] : null,
            $horizon
        );

        return $dueDates[0] ?? null;
    }

    /**
     * Approve one draft: post it to the ledger via TransactionService::post()
     * — so every invariant that guards a manual expense/income entry
     * (account/category ownership and status, a positive two-decimal amount)
     * guards an approved draft too — then mark the occurrence reviewed.
     *
     * $edited carries whatever the approver changed on the review form.
     * amount/transaction_date/description each fall back to the occurrence's
     * own generated snapshot when the corresponding key is absent, which is
     * exactly how approveBulk() below posts "as generated" through this same
     * method without editing anything, rather than needing a second code
     * path for the un-edited case.
     *
     * The occurrence is loaded tenant-scoped (RecurringOccurrence::find(),
     * inherited from Model) — that IS the ownership check: another branch's
     * occurrence id is simply not found, the same guarantee every other
     * tenant-scoped write in this app relies on.
     *
     * @param array{amount?:string,transaction_date?:string,description?:string} $edited
     * @return int the id of the newly posted transaction
     */
    public static function approve(int $occurrenceId, array $edited): int
    {
        $occurrences = new RecurringOccurrence();
        $occurrence = $occurrences->find($occurrenceId);

        if ($occurrence === null) {
            throw new RuntimeException('That draft no longer exists.');
        }
        if ((string) $occurrence['status'] !== 'pending_review') {
            throw new RuntimeException('That draft has already been reviewed.');
        }

        $type = (string) $occurrence['type'];
        $amount = $edited['amount'] ?? (string) $occurrence['amount'];
        $date = $edited['transaction_date'] ?? (string) $occurrence['occurrence_date'];
        $description = $edited['description'] ?? (string) $occurrence['description'];

        $vendor = $occurrence['vendor'] === null ? null : (string) $occurrence['vendor'];
        $customerId = $occurrence['customer_id'] === null ? null : (int) $occurrence['customer_id'];
        $notes = $occurrence['notes'] === null ? null : (string) $occurrence['notes'];
        $referenceNo = $occurrence['reference_no'] === null ? null : (string) $occurrence['reference_no'];

        // Expense::write()/Sale::write() are the same detail writers
        // ExpenseController/IncomeController use for a manual entry.
        // Sale::write() always gets a null invoice number here: a recurring
        // rule has no invoice-number field to draw one from (unlike a manual
        // income entry), so nothing is fabricated.
        $writeDetail = $type === 'expense'
            ? static function (int $transactionId) use ($vendor, $notes): void {
                Expense::write($transactionId, $vendor, $notes);
            }
            : static function (int $transactionId) use ($customerId, $notes): void {
                Sale::write($transactionId, $customerId, null, $notes);
            };

        return (int) Database::instance()->transaction(static function () use (
            $occurrences,
            $occurrence,
            $occurrenceId,
            $type,
            $amount,
            $date,
            $description,
            $referenceNo,
            $writeDetail
        ): int {
            $transactionId = TransactionService::post([
                'type' => $type,
                'amount' => $amount,
                'account_id' => (int) $occurrence['account_id'],
                'category_id' => (int) $occurrence['category_id'],
                'transaction_date' => $date,
                'description' => $description,
                'reference_no' => $referenceNo,
            ], $writeDetail);

            $occurrences->updateById($occurrenceId, [
                'status' => 'approved',
                'transaction_id' => $transactionId,
                'reviewed_by' => Auth::id(),
                'reviewed_at' => date('Y-m-d H:i:s'),
            ]);

            Audit::record(
                'recurring_occurrence.approved',
                'recurring_occurrences',
                $occurrenceId,
                null,
                ['transaction_id' => $transactionId] + self::editedSnapshot($occurrence, $amount, $date, $description)
            );

            return $transactionId;
        });
    }

    /**
     * Reject one draft. Tenant-scoped load is the ownership check, same as
     * approve() above. Never touches `transactions` — a rejected draft is
     * simply marked reviewed with a reason; nothing was ever posted for
     * there to be anything to undo.
     */
    public static function reject(int $occurrenceId, ?string $reason): void
    {
        $occurrences = new RecurringOccurrence();
        $occurrence = $occurrences->find($occurrenceId);

        if ($occurrence === null) {
            throw new RuntimeException('That draft no longer exists.');
        }
        if ((string) $occurrence['status'] !== 'pending_review') {
            throw new RuntimeException('That draft has already been reviewed.');
        }

        $reason = $reason === null ? null : trim($reason);
        if ($reason === '') {
            $reason = null;
        }
        if ($reason !== null && mb_strlen($reason) > 255) {
            $reason = mb_substr($reason, 0, 255);
        }

        Database::instance()->transaction(static function () use ($occurrences, $occurrenceId, $reason): void {
            $occurrences->updateById($occurrenceId, [
                'status' => 'rejected',
                'reviewed_by' => Auth::id(),
                'reviewed_at' => date('Y-m-d H:i:s'),
                'reject_reason' => $reason,
            ]);

            Audit::record(
                'recurring_occurrence.rejected',
                'recurring_occurrences',
                $occurrenceId,
                null,
                ['reject_reason' => $reason]
            );
        });
    }

    /**
     * "I was away 3 months, clear the queue": approve every listed draft
     * exactly as generated — no per-row editing, achieved by reusing
     * approve() with an empty $edited so every field falls back to the
     * occurrence's own snapshot. One bad row (already reviewed, an account
     * closed since generation, whatever TransactionService::post() rejects)
     * is skipped rather than aborting the rest, since the entire point of
     * this entry point is clearing a queue in one action.
     *
     * Capped at 50 ids per call regardless of how many are passed — a
     * silent, defensive ceiling on how much one request can do, not a
     * validation error the caller needs to handle specially.
     *
     * @param list<int> $occurrenceIds
     * @return array{approved:int,skipped:list<string>}
     */
    public static function approveBulk(array $occurrenceIds): array
    {
        $occurrenceIds = array_slice(
            array_values(array_unique(array_filter(
                $occurrenceIds,
                static fn (int $id): bool => $id > 0
            ))),
            0,
            50
        );

        $approved = 0;
        $skipped = [];

        foreach ($occurrenceIds as $occurrenceId) {
            try {
                self::approve($occurrenceId, []);
                $approved++;
            } catch (Throwable $e) {
                $skipped[] = '#' . $occurrenceId . ': ' . $e->getMessage();
                Logger::info('Bulk recurring approval skipped one draft', [
                    'occurrence_id' => $occurrenceId,
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        return ['approved' => $approved, 'skipped' => $skipped];
    }

    /**
     * The audit payload for approve(): always just the posted transaction id
     * unless something was actually edited, in which case each changed field
     * carries both the value the rule generated and the value that was
     * posted instead — so an edited amount, date or description stays
     * traceable back to what was originally scheduled.
     *
     * @param array<string,mixed> $occurrence
     * @return array<string,mixed>
     */
    private static function editedSnapshot(array $occurrence, string $amount, string $date, string $description): array
    {
        $edited = [];

        if ((string) $occurrence['amount'] !== $amount) {
            $edited['amount'] = ['original' => $occurrence['amount'], 'posted' => $amount];
        }
        if ((string) $occurrence['occurrence_date'] !== $date) {
            $edited['transaction_date'] = ['original' => $occurrence['occurrence_date'], 'posted' => $date];
        }
        if ((string) $occurrence['description'] !== $description) {
            $edited['description'] = ['original' => $occurrence['description'], 'posted' => $description];
        }

        return $edited === [] ? [] : ['edited' => $edited];
    }

    private static function requireWebBranchId(): int
    {
        $branchId = Auth::branchId();
        if ($branchId === null) {
            throw new RuntimeException('No active branch — cannot validate recurring rule references.');
        }

        return $branchId;
    }
}
