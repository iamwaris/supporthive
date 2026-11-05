<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Model;
use App\Services\Audit;
use App\Services\Payroll\PayrollSheetUpdate;
use App\Services\Payroll\PayrollSheetUpdatePlan;
use RuntimeException;

/**
 * One branch's salary notices for one month (PAY-1): the month, its note,
 * and the lines in payroll_run_lines. Branch-scoped through App\Core\Model,
 * so find($id) is the ownership check — another branch's run is not found.
 */
final class PayrollRun extends Model
{
    protected string $table = 'payroll_runs';

    /** @var list<string> */
    protected array $fillable = ['period_month', 'note', 'created_by'];

    /** @return array<string,mixed>|null */
    public function findForMonth(string $periodMonth): ?array
    {
        return $this->db()->first(
            'SELECT * FROM payroll_runs WHERE branch_id = :branch AND period_month = :month LIMIT 1',
            ['branch' => $this->requireBranchId(), 'month' => $periodMonth]
        );
    }

    /**
     * Newest month first, with how many lines stand where.
     *
     * @return list<array<string,mixed>>
     */
    public function listWithCounts(int $page, int $perPage): array
    {
        $perPage = max(1, min($perPage, 100));

        return $this->db()->all(
            "SELECT r.id, r.period_month, r.created_at,
                    COUNT(l.id) AS line_count,
                    COALESCE(SUM(l.status = 'confirmed'), 0) AS confirmed_count,
                    COALESCE(SUM(l.status = 'query'), 0) AS query_count,
                    COALESCE(SUM(l.status = 'sent'), 0) AS pending_count,
                    COALESCE(SUM(l.status = 'draft'), 0) AS draft_count
             FROM payroll_runs r
             LEFT JOIN payroll_run_lines l ON l.run_id = r.id AND l.branch_id = r.branch_id
             WHERE r.branch_id = :branch
             GROUP BY r.id, r.period_month, r.created_at
             ORDER BY r.period_month DESC
             LIMIT :take OFFSET :skip",
            ['branch' => $this->requireBranchId(), 'take' => $perPage, 'skip' => max(0, ($page - 1) * $perPage)]
        );
    }

    /**
     * True while any employee holds a notice of this run: a line awaiting a
     * response (live link) or already answered. That is what locks the
     * month's note and a full re-import. A line that was emailed but is a
     * draft again — its link cancelled by a correction or a withdrawal —
     * no longer counts; emailed_at stays on it as history.
     */
    public function hasOutstandingNotices(int $runId): bool
    {
        return (int) $this->db()->value(
            "SELECT COUNT(*) FROM payroll_run_lines
             WHERE run_id = :run AND branch_id = :branch AND status IN ('sent','confirmed','query')",
            ['run' => $runId, 'branch' => $this->requireBranchId()]
        ) > 0;
    }

    /** The name of whoever last withdrew the run, or null if never withdrawn (or that user is gone). */
    public function withdrawnByName(int $runId): ?string
    {
        $name = $this->db()->value(
            'SELECT u.name FROM payroll_runs r JOIN users u ON u.id = r.withdrawn_by
             WHERE r.id = :id AND r.branch_id = :branch',
            ['id' => $runId, 'branch' => $this->requireBranchId()]
        );

        return $name === null ? null : (string) $name;
    }

    /**
     * Withdraw every notice of the run (PAY-3), in one transaction with the
     * run row locked: each line's prior state — status, figures, signature
     * or query, when it was emailed and answered — goes to the audit log,
     * then every line becomes a draft with no link and no response. The
     * screenshot and emailed_at stay. The run records when, by whom and
     * why; every notice sent afterwards is a correction.
     *
     * Returns the counts of lines by prior status, or null when nothing was
     * out with employees (never sent, or already withdrawn — a second
     * concurrent withdrawal lands here once the first commits).
     *
     * @return array{confirmed:int,query:int,sent:int,draft:int}|null
     */
    public function withdraw(int $runId, string $reason): ?array
    {
        $branchId = $this->requireBranchId();

        /** @var array{confirmed:int,query:int,sent:int,draft:int}|null */
        return Database::instance()->transaction(function (Database $db) use ($runId, $branchId, $reason): ?array {
            $run = $db->first(
                'SELECT id, period_month FROM payroll_runs WHERE id = :id AND branch_id = :branch FOR UPDATE',
                ['id' => $runId, 'branch' => $branchId]
            );
            if ($run === null) {
                return null;
            }

            $lines = $db->all(
                'SELECT id, employee_code, status, salary, absent_days, working_days, signed_name, responded_at,
                        response_ip, query_text, emailed_at, token_expires_at
                 FROM payroll_run_lines WHERE run_id = :run AND branch_id = :branch
                 ORDER BY id FOR UPDATE',
                ['run' => $runId, 'branch' => $branchId]
            );

            $counts = ['confirmed' => 0, 'query' => 0, 'sent' => 0, 'draft' => 0];
            foreach ($lines as $line) {
                $counts[(string) $line['status']]++;
            }
            if ($counts['confirmed'] + $counts['query'] + $counts['sent'] === 0) {
                return null;
            }

            foreach ($lines as $line) {
                Audit::record(
                    'payroll_line.withdrawn',
                    'payroll_run_lines',
                    (int) $line['id'],
                    [
                        'status' => $line['status'],
                        'salary' => $line['salary'],
                        'absent_days' => $line['absent_days'],
                        'working_days' => $line['working_days'],
                        'signed_name' => $line['signed_name'],
                        'responded_at' => $line['responded_at'],
                        'response_ip' => $line['response_ip'],
                        'query_text' => $line['query_text'],
                        'emailed_at' => $line['emailed_at'],
                        'link_expires_at' => $line['token_expires_at'],
                    ],
                    ['status' => 'draft']
                );
            }

            $db->update(
                'payroll_run_lines',
                [
                    'status' => 'draft',
                    'token_hash' => null,
                    'token_expires_at' => null,
                    'signed_name' => null,
                    'responded_at' => null,
                    'response_ip' => null,
                    'response_user_agent' => null,
                    'query_text' => null,
                ],
                'run_id = :run AND branch_id = :branch',
                ['run' => $runId, 'branch' => $branchId]
            );
            $db->update(
                'payroll_runs',
                ['withdrawn_at' => date('Y-m-d H:i:s'), 'withdrawn_by' => Auth::id(), 'withdraw_reason' => $reason],
                'id = :id AND branch_id = :branch',
                ['id' => $runId, 'branch' => $branchId]
            );

            Audit::record('payroll_run.withdrawn', 'payroll_runs', $runId, null, [
                'period_month' => $run['period_month'],
                'reason' => $reason,
                'lines' => count($lines),
            ] + $counts);

            return $counts;
        });
    }

    /**
     * Every employee of this branch with their Employee ID and whether they
     * are active, for PayrollImport.
     *
     * @return list<array{user_id:int,name:string,employee_code:?string,active:bool}>
     */
    public function branchEmployees(): array
    {
        $rows = $this->db()->all(
            "SELECT u.id, u.name, u.status, p.employee_code
             FROM employee_profiles p
             JOIN users u ON u.id = p.user_id AND u.role = 'employee'
             WHERE p.branch_id = :branch
             ORDER BY u.name",
            ['branch' => $this->requireBranchId()]
        );

        return array_map(static fn (array $row): array => [
            'user_id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'employee_code' => $row['employee_code'] === null ? null : (string) $row['employee_code'],
            'active' => (string) $row['status'] === 'active',
        ], $rows);
    }

    /**
     * Create the month's run with these lines, or replace an existing run's
     * lines — only while no notice is out with an employee (see
     * hasOutstandingNotices(); after a withdrawal, none is), re-checked
     * under a row lock. Screenshot files of replaced lines are removed once the
     * change is durable. Returns the run id.
     *
     * @param list<array{
     *     user_id:int,name:string,employee_code:string,salary:string,absent_days:string,working_days:string
     * }> $lines
     * @throws RuntimeException when the existing run has a notice out with an employee
     */
    public function import(string $periodMonth, ?string $note, array $lines): int
    {
        $branchId = $this->requireBranchId();

        return (int) Database::instance()->transaction(function (Database $db) use (
            $branchId,
            $periodMonth,
            $note,
            $lines
        ): int {
            $existing = $db->first(
                'SELECT id FROM payroll_runs WHERE branch_id = :branch AND period_month = :month FOR UPDATE',
                ['branch' => $branchId, 'month' => $periodMonth]
            );

            $replacedFiles = [];
            if ($existing === null) {
                $runId = $this->create(['period_month' => $periodMonth, 'note' => $note, 'created_by' => Auth::id()]);
            } else {
                $runId = (int) $existing['id'];
                if ($this->hasOutstandingNotices($runId)) {
                    throw new RuntimeException('Notices for this month are out with employees.');
                }
                $replacedFiles = array_values(array_filter(array_map(
                    static fn (array $row): ?string => $row['screenshot_filename'] === null
                        ? null
                        : (string) $row['screenshot_filename'],
                    $db->all(
                        'SELECT screenshot_filename FROM payroll_run_lines WHERE run_id = :run AND branch_id = :branch',
                        ['run' => $runId, 'branch' => $branchId]
                    )
                )));
                $db->delete('payroll_run_lines', 'run_id = :run AND branch_id = :branch', [
                    'run' => $runId,
                    'branch' => $branchId,
                ]);
                $this->updateById($runId, ['note' => $note]);
            }

            self::insertDraftLines($db, $branchId, $runId, $lines);

            Audit::record(
                $existing === null ? 'payroll_run.imported' : 'payroll_run.reimported',
                'payroll_runs',
                $runId,
                null,
                ['period_month' => $periodMonth, 'lines' => count($lines)]
            );

            $db->afterCommit(static function () use ($replacedFiles, $runId): void {
                foreach ($replacedFiles as $filename) {
                    PayrollRunLine::removeScreenshotFile($filename, $runId);
                }
            });

            return $runId;
        });
    }

    /**
     * Apply an "add or update" sheet (PAY-4) in one transaction with the run
     * row and its lines locked. The plan is made again here, from the lines
     * as locked and the branch's employees as they are now, and applied only
     * if it is error-free and was made against the same state the admin saw
     * ($expectedState); otherwise nothing is written and null is returned.
     *
     * New employees become draft lines. Each update goes through
     * PayrollRunLine::updateFigures(), the manual edit's own path: the same
     * figures audit, and a sent line's link cancelled the same way. Unchanged
     * and left-out lines are not written at all. One audit row for the upload
     * carries the counts.
     *
     * @param array<int,list<string>> $rows the sheet, as for PayrollImport::validate()
     */
    public function updateFromSheet(int $runId, array $rows, string $expectedState): ?PayrollSheetUpdatePlan
    {
        $branchId = $this->requireBranchId();

        /** @var PayrollSheetUpdatePlan|null */
        return Database::instance()->transaction(function (Database $db) use (
            $branchId,
            $runId,
            $rows,
            $expectedState
        ): ?PayrollSheetUpdatePlan {
            $run = $db->first(
                'SELECT id FROM payroll_runs WHERE id = :id AND branch_id = :branch FOR UPDATE',
                ['id' => $runId, 'branch' => $branchId]
            );
            if ($run === null) {
                return null;
            }

            $lines = $db->all(
                'SELECT id, user_id, status, salary, absent_days, working_days, token_expires_at
                 FROM payroll_run_lines WHERE run_id = :run AND branch_id = :branch
                 ORDER BY id FOR UPDATE',
                ['run' => $runId, 'branch' => $branchId]
            );
            $plan = PayrollSheetUpdate::plan($rows, $this->branchEmployees(), $lines);
            if (!$plan->passes() || !hash_equals($expectedState, $plan->state)) {
                return null;
            }

            self::insertDraftLines($db, $branchId, $runId, $plan->added);

            $runLines = new PayrollRunLine();
            foreach ($plan->updated as $change) {
                $figures = [
                    'salary' => $change['after']['salary'],
                    'absent_days' => $change['after']['absent_days'],
                    'working_days' => $change['after']['working_days'],
                ];
                if (!$runLines->updateFigures($runId, $change['line_id'], $figures)) {
                    throw new RuntimeException('A line being updated is no longer editable.');
                }
            }

            Audit::record('payroll_run.sheet_updated', 'payroll_runs', $runId, null, [
                'added' => count($plan->added),
                'updated' => count($plan->updated),
                'links_cancelled' => $plan->linksCancelled(),
                'unchanged' => $plan->unchanged,
                'left_out' => $plan->leftOut,
                'added_codes' => array_map(static fn (array $line): string => $line['employee_code'], $plan->added),
                'updated_codes' => array_map(
                    static fn (array $change): string => $change['employee_code'],
                    $plan->updated
                ),
            ]);

            return $plan;
        });
    }

    /**
     * Active employees of this branch with no line in the run: they get no
     * notice this month unless they are added.
     *
     * @return list<array{name:string,employee_code:?string}>
     */
    public function activeEmployeesWithoutLine(int $runId): array
    {
        $rows = $this->db()->all(
            "SELECT u.name, p.employee_code
             FROM employee_profiles p
             JOIN users u ON u.id = p.user_id AND u.role = 'employee' AND u.status = 'active'
             WHERE p.branch_id = :branch
               AND NOT EXISTS (
                   SELECT 1 FROM payroll_run_lines l
                   WHERE l.run_id = :run AND l.branch_id = :line_branch AND l.user_id = u.id
               )
             ORDER BY u.name",
            ['branch' => $this->requireBranchId(), 'run' => $runId, 'line_branch' => $this->requireBranchId()]
        );

        return array_map(static fn (array $row): array => [
            'name' => (string) $row['name'],
            'employee_code' => $row['employee_code'] === null ? null : (string) $row['employee_code'],
        ], $rows);
    }

    /**
     * @param list<array{
     *     user_id:int,name:string,employee_code:string,salary:string,absent_days:string,working_days:string
     * }> $lines
     */
    private static function insertDraftLines(Database $db, int $branchId, int $runId, array $lines): void
    {
        foreach ($lines as $line) {
            $db->insert('payroll_run_lines', [
                'branch_id' => $branchId,
                'run_id' => $runId,
                'user_id' => $line['user_id'],
                'employee_code' => $line['employee_code'],
                'salary' => $line['salary'],
                'absent_days' => $line['absent_days'],
                'working_days' => $line['working_days'],
                'status' => 'draft',
            ]);
        }
    }

    /** @return bool false while any notice is out with an employee, when the note is no longer editable */
    public function updateNote(int $runId, ?string $note): bool
    {
        return (bool) Database::instance()->transaction(function (Database $db) use ($runId, $note): bool {
            $run = $db->first(
                'SELECT id, note FROM payroll_runs WHERE id = :id AND branch_id = :branch FOR UPDATE',
                ['id' => $runId, 'branch' => $this->requireBranchId()]
            );
            if ($run === null || $this->hasOutstandingNotices($runId)) {
                return false;
            }

            $this->updateById($runId, ['note' => $note]);
            Audit::record('payroll_run.note_updated', 'payroll_runs', $runId, null, null);

            return true;
        });
    }

    /** Whole-branch deletion only (BranchController::destroy()), inside its transaction. */
    public static function deleteForBranch(Database $db, int $branchId): void
    {
        $files = array_map(
            static fn (array $row): string => (string) $row['screenshot_filename'],
            $db->all(
                'SELECT screenshot_filename FROM payroll_run_lines
                 WHERE branch_id = :id AND screenshot_filename IS NOT NULL',
                ['id' => $branchId]
            )
        );
        $db->delete('payroll_run_lines', 'branch_id = :id', ['id' => $branchId]);
        $db->delete('payroll_runs', 'branch_id = :id', ['id' => $branchId]);
        $db->afterCommit(static function () use ($files): void {
            foreach ($files as $filename) {
                PayrollRunLine::removeScreenshotFile($filename, null);
            }
        });
        Logger::info('Payroll runs deleted with branch', ['branch_id' => $branchId, 'screenshots' => count($files)]);
    }
}
