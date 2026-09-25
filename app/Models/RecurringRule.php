<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/**
 * A recurring schedule (frequency + anchor day + entry fields to snapshot
 * onto each generated draft). See the `recurring_rules` migration for the
 * exact columns and CHECK constraints this must respect.
 *
 * Two call paths, and the distinction is load-bearing:
 *
 *   - Web path: the ordinary `App\Core\Model` methods this class inherits
 *     (find()/create()/updateById()/etc.), tenant-scoped via
 *     Auth::branchId() from the session. Used by the future rule
 *     management screens.
 *   - CLI path: activeForBranch() and advanceCursor() below. The nightly
 *     generator (`scripts/generate-recurring-transactions.php`) runs with
 *     no session at all — there is no logged-in user, so Auth::branchId()
 *     has nothing to read and Model's own tenant-scoped methods would
 *     throw via requireBranchId(). These two methods take $branchId as an
 *     explicit parameter and run their own parameterized SQL instead, so
 *     the generator can loop over every branch in one process without ever
 *     touching Auth or the session.
 *
 * last_generated_date is deliberately left out of $fillable: it is the
 * generator's own catch-up cursor, not a user-editable field, and is only
 * ever written through advanceCursor()'s explicit SQL.
 */
final class RecurringRule extends Model
{
    protected string $table = 'recurring_rules';

    /** @var list<string> */
    protected array $fillable = [
        'type', 'description', 'amount', 'account_id', 'category_id', 'vendor',
        'customer_id', 'reference_no', 'notes', 'frequency', 'day_of_month',
        'day_of_week', 'start_date', 'end_date', 'is_active', 'created_by',
    ];

    /**
     * CLI-safe / explicit-branch: every active, not-yet-expired rule for one
     * branch. Never call this from a web request — use the inherited
     * tenant-scoped methods there instead.
     *
     * Expiry is also re-checked by RecurringSchedule::occurrencesDue() itself
     * (via each rule's end_date), so this WHERE clause is an optimisation,
     * not the source of truth: it just keeps rules that can never generate
     * another occurrence out of the loop entirely, cheaply, via the existing
     * (branch_id, is_active) index.
     *
     * @return list<array<string,mixed>>
     */
    public function activeForBranch(int $branchId): array
    {
        return $this->db()->all(
            'SELECT * FROM recurring_rules
             WHERE branch_id = :branch_id AND is_active = 1
               AND (end_date IS NULL OR end_date >= CURDATE())
             ORDER BY id ASC',
            ['branch_id' => $branchId]
        );
    }

    /**
     * CLI-safe / explicit-branch: advance one rule's catch-up cursor after a
     * generation run. Scoped by both id AND branch_id in the WHERE clause —
     * not just id — for the same reason every tenant-scoped write in this
     * app double-checks ownership: a rule id alone is not proof it belongs
     * to the branch the caller thinks it does.
     */
    public function advanceCursor(int $branchId, int $ruleId, string $date): void
    {
        $this->db()->update(
            'recurring_rules',
            ['last_generated_date' => $date],
            'id = :id AND branch_id = :branch_id',
            ['id' => $ruleId, 'branch_id' => $branchId]
        );
    }

    /**
     * Web path: every rule for the current branch with its account/category/
     * customer names attached, for the rule-list screen. One query rather
     * than N+1 lookups per row.
     *
     * @return list<array<string,mixed>>
     */
    public function allWithDetails(): array
    {
        return $this->db()->all(
            'SELECT r.*, a.name AS account_name, c.name AS category_name, cu.name AS customer_name
             FROM recurring_rules r
             JOIN accounts a ON a.id = r.account_id
             JOIN categories c ON c.id = r.category_id
             LEFT JOIN customers cu ON cu.id = r.customer_id
             WHERE r.branch_id = :branch_id
             ORDER BY r.created_at DESC, r.id DESC',
            ['branch_id' => $this->requireBranchId()]
        );
    }

    /**
     * Web path: pause a rule (is_active -> 0). A plain fillable column, so
     * this could go through updateById() directly — kept as its own method
     * only for symmetry with resume() below, which cannot.
     */
    public function pause(int $id): void
    {
        $this->updateById($id, ['is_active' => 0]);
    }

    /**
     * Web path: resume a rule (is_active -> 1) AND advance
     * last_generated_date to today, so the paused window is never
     * backfilled by the next generator run (see the migration's comment on
     * this column, and advanceCursor() above for its CLI-path
     * counterpart). last_generated_date is deliberately excluded from
     * $fillable — updateById() would silently drop it — so this writes
     * both columns with its own explicit SQL, tenant-scoped the same way
     * every other write in this class is.
     */
    public function resume(int $id): void
    {
        $this->db()->update(
            'recurring_rules',
            ['is_active' => 1, 'last_generated_date' => date('Y-m-d')],
            'id = :id AND branch_id = :branch_id',
            ['id' => $id, 'branch_id' => $this->requireBranchId()]
        );
    }
}
