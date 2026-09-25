<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/**
 * A single generated draft sitting in the recurring-transactions approval
 * queue. See the `recurring_occurrences` migration for the exact columns —
 * each row is a snapshot of its owning rule's entry fields at generation
 * time, not a live join back to `recurring_rules`.
 *
 * Two call paths, same as RecurringRule:
 *
 *   - CLI path: createForBranch(), used only by the generator
 *     (RecurringRuleService::generateForBranch()), which runs with no
 *     session and takes $branchId explicitly.
 *   - Web path: everything else below — the inherited, tenant-scoped
 *     find()/updateById() (Auth::branchId() via $tenantScoped, which stays
 *     at its default of true) plus pendingForBranch()/pendingCount(). A
 *     Branch B occurrence id is simply not found from a Branch A session,
 *     which is exactly the ownership check RecurringRuleService::approve()/
 *     reject() rely on.
 *
 * $fillable includes the review-outcome columns (status/transaction_id/
 * reviewed_by/reviewed_at/reject_reason) alongside the snapshot fields:
 * createForBranch() never passes them (a freshly generated draft always
 * starts at the schema default, 'pending_review'), but updateById() — used
 * only by the approve/reject web path — needs them to go through.
 */
final class RecurringOccurrence extends Model
{
    protected string $table = 'recurring_occurrences';

    /** @var list<string> */
    protected array $fillable = [
        'rule_id', 'occurrence_date', 'type', 'amount', 'account_id', 'category_id',
        'description', 'vendor', 'customer_id', 'reference_no', 'notes',
        'status', 'transaction_id', 'reviewed_by', 'reviewed_at', 'reject_reason',
    ];

    /**
     * CLI-safe / explicit-branch: insert one draft occurrence with an
     * explicit branch_id, bypassing Model::create()'s
     * Auth::branchId()-via-requireBranchId() stamp. The generator that calls
     * this runs with no session at all — see RecurringRule's class doc for
     * why the CLI path never touches Auth.
     *
     * `status` is intentionally not accepted here: every occurrence this
     * inserts starts life as the schema default ('pending_review'), and the
     * approval flow — the only thing ever allowed to change it — is the
     * next task's web-path write.
     *
     * @param array<string,mixed> $data
     */
    public function createForBranch(int $branchId, array $data): int
    {
        $data = $this->filter($data);
        $data['branch_id'] = $branchId;

        return $this->db()->insert($this->table, $data);
    }

    // ------------------------------------------------------------- web path

    /**
     * Web path: every pending draft for the current branch, oldest occurrence
     * first — the approval queue's own listing. Joined to accounts/categories
     * for display names, one query rather than N+1 per row; no join back to
     * recurring_rules is needed since `description` (and every other entry
     * field) is already a snapshot copied onto the occurrence itself at
     * generation time — see the migration's comment on why.
     *
     * @return list<array<string,mixed>>
     */
    public function pendingForBranch(): array
    {
        return $this->db()->all(
            "SELECT o.*, a.name AS account_name, c.name AS category_name
             FROM recurring_occurrences o
             JOIN accounts a ON a.id = o.account_id
             JOIN categories c ON c.id = o.category_id
             WHERE o.branch_id = :branch_id AND o.status = 'pending_review'
             ORDER BY o.occurrence_date ASC, o.id ASC",
            ['branch_id' => $this->requireBranchId()]
        );
    }

    /** Web path: how many drafts are waiting on this branch — the dashboard tile. */
    public function pendingCount(): int
    {
        return $this->count("status = 'pending_review'");
    }
}
