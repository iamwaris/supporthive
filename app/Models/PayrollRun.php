<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Model;
use App\Services\Audit;
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

    public function hasEmailedLines(int $runId): bool
    {
        return (int) $this->db()->value(
            'SELECT COUNT(*) FROM payroll_run_lines
             WHERE run_id = :run AND branch_id = :branch AND emailed_at IS NOT NULL',
            ['run' => $runId, 'branch' => $this->requireBranchId()]
        ) > 0;
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
     * lines — only while none of them has been emailed, re-checked under a
     * row lock. Screenshot files of replaced lines are removed once the
     * change is durable. Returns the run id.
     *
     * @param list<array{
     *     user_id:int,name:string,employee_code:string,salary:string,absent_days:string,working_days:string
     * }> $lines
     * @throws RuntimeException when the existing run has emailed lines
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
                if ($this->hasEmailedLines($runId)) {
                    throw new RuntimeException('Notices for this month have already been emailed.');
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

    /** @return bool false once any line has been emailed, when the note is no longer editable */
    public function updateNote(int $runId, ?string $note): bool
    {
        return (bool) Database::instance()->transaction(function (Database $db) use ($runId, $note): bool {
            $run = $db->first(
                'SELECT id, note FROM payroll_runs WHERE id = :id AND branch_id = :branch FOR UPDATE',
                ['id' => $runId, 'branch' => $this->requireBranchId()]
            );
            if ($run === null || $this->hasEmailedLines($runId)) {
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
