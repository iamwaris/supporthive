<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Model;

/**
 * A form a new joiner submitted through their branch's /join/{token} link,
 * waiting for an admin. Final once submitted: the joiner has no way to read
 * or change it. An admin approves it (which creates the employee through
 * App\Services\EmployeeOnboarding; the row is kept as the record of that)
 * or deletes it — a hard delete of a pending row, encrypted bank details
 * included. There is no rejected state.
 *
 * Bank details are stored only as App\Core\SecretBox ciphertext from the
 * moment of submission (App\Services\BankDetails), and only while the row
 * is pending: approval moves them to the employee and clears them here.
 *
 * Every admin read and write is scoped to the session's branch (Model). The
 * one write without a session branch, submit(), takes the branch the link
 * resolved to.
 */
final class JoinerSubmission extends Model
{
    public const STATUSES = ['pending', 'approved'];

    protected string $table = 'joiner_submissions';

    /** @var list<string> */
    protected array $fillable = [];

    /**
     * @param array{name:string,email:string,phone:?string,joining_date:?string} $fields validated
     * @param array<string,?string> $bankColumns BankDetails::encrypt() output
     */
    public static function submit(int $branchId, array $fields, array $bankColumns, string $ip): int
    {
        return Database::instance()->insert('joiner_submissions', [
            'branch_id' => $branchId,
            'name' => $fields['name'],
            'email' => $fields['email'],
            'phone' => $fields['phone'],
            'joining_date' => $fields['joining_date'],
            'bank_name_enc' => $bankColumns['bank_name_enc'] ?? null,
            'bank_account_number_enc' => $bankColumns['bank_account_number_enc'] ?? null,
            'bank_iban_enc' => $bankColumns['bank_iban_enc'] ?? null,
            'status' => 'pending',
            'submitted_ip' => $ip,
        ]);
    }

    /** @return list<array<string,mixed>> oldest first, so the longest-waiting joiner is on top */
    public function search(string $status, int $page, int $perPage): array
    {
        $perPage = max(1, min($perPage, 100));

        return $this->db()->all(
            'SELECT s.id, s.name, s.email, s.phone, s.joining_date, s.status, s.created_at, s.reviewed_at,
                    s.bank_account_number_enc, s.bank_iban_enc, r.name AS reviewed_by_name
             FROM joiner_submissions s
             LEFT JOIN users r ON r.id = s.reviewed_by
             WHERE s.branch_id = :branch AND s.status = :status
             ORDER BY s.created_at ASC, s.id ASC
             LIMIT :take OFFSET :skip',
            [
                'branch' => $this->requireBranchId(),
                'status' => $status,
                'take' => $perPage,
                'skip' => max(0, ($page - 1) * $perPage),
            ]
        );
    }

    public function countWithStatus(string $status): int
    {
        return $this->count('status = :status', ['status' => $status]);
    }

    /** @return array<string,mixed>|null this branch's submission with its reviewer's name */
    public function findWithReviewer(int $id): ?array
    {
        return $this->db()->first(
            'SELECT s.*, r.name AS reviewed_by_name
             FROM joiner_submissions s
             LEFT JOIN users r ON r.id = s.reviewed_by
             WHERE s.id = :id AND s.branch_id = :branch
             LIMIT 1',
            ['id' => $id, 'branch' => $this->requireBranchId()]
        );
    }

    /**
     * Inside a transaction: the submission if it is still pending, locked so
     * two admins cannot both approve (or approve and delete) it.
     *
     * @return array<string,mixed>|null
     */
    public function lockPending(int $id): ?array
    {
        return $this->db()->first(
            "SELECT * FROM joiner_submissions
             WHERE id = :id AND branch_id = :branch AND status = 'pending'
             FOR UPDATE",
            ['id' => $id, 'branch' => $this->requireBranchId()]
        );
    }

    /**
     * Also clears the submission's bank ciphertext: by now it has been
     * copied to the employee, which is the one place it lives from here on.
     * Keeping a second copy would leave a stale full IBAN here after any
     * later correction on the employee.
     */
    public function markApproved(int $id, int $employeeUserId): void
    {
        $this->db()->update(
            'joiner_submissions',
            [
                'status' => 'approved',
                'employee_user_id' => $employeeUserId,
                'bank_name_enc' => null,
                'bank_account_number_enc' => null,
                'bank_iban_enc' => null,
                'reviewed_by' => Auth::id(),
                'reviewed_at' => date('Y-m-d H:i:s'),
            ],
            "id = :id AND branch_id = :branch AND status = 'pending'",
            ['id' => $id, 'branch' => $this->requireBranchId()]
        );
    }

    /**
     * Permanently removes a pending submission of this branch, ciphertext
     * and all. Callers lock it with lockPending() first; the status
     * condition is a second guard so an approved row can never go this way.
     * Nothing references a submission, so there are no child rows to remove.
     */
    public function deletePending(int $id): void
    {
        $this->db()->delete(
            'joiner_submissions',
            "id = :id AND branch_id = :branch AND status = 'pending'",
            ['id' => $id, 'branch' => $this->requireBranchId()]
        );
    }
}
