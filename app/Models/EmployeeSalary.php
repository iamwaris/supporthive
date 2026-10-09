<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Model;
use App\Services\Audit;
use RuntimeException;

/**
 * An employee's salary as an effective-dated, append-only history.
 *
 * A change is always a new row (record()); there is deliberately no update
 * or delete method, so every past amount, its effective date and who
 * recorded it stay on file. The current salary is the latest row in effect
 * today; a row dated in the future is a scheduled change.
 *
 * Admin-only data. Nothing on the employee portal reads this model, and
 * EmployeeProfile::findWithUser() never joins it. Every query is scoped to
 * this branch AND to a user who is an employee of it, on top of the
 * controller resolving the id through findWithUser() first.
 */
final class EmployeeSalary extends Model
{
    /** Validator rule for a salary amount: digits, at most 2 decimals, within the form's cap. */
    public const AMOUNT_RULE = 'money|between:0,9999999999.99';

    /** The "Change salary" form on an employee's page. */
    public const CHANGE_RULES = [
        'amount' => 'required|' . self::AMOUNT_RULE,
        'effective_from' => 'required|ymd',
        'note' => 'nullable|max:255',
    ];

    /**
     * The same employee gate for every read: this branch, and a user who is an
     * employee of it. Joined, not trusted from the caller.
     */
    private const EMPLOYEE_JOIN = "JOIN employee_profiles p ON p.user_id = s.user_id AND p.branch_id = s.branch_id
             JOIN users u ON u.id = s.user_id AND u.role = 'employee'";

    protected string $table = 'employee_salaries';

    /** @var list<string> */
    protected array $fillable = ['user_id', 'amount', 'effective_from', 'note', 'created_by'];

    /**
     * Append a salary row and audit it as a change from the salary in effect
     * today. Joins the caller's transaction when there is one (employee
     * onboarding), so the employee and their starting salary land together.
     *
     * @param string $amount validated by AMOUNT_RULE
     * @param string $effectiveFrom validated YYYY-MM-DD
     */
    public function record(int $userId, string $amount, string $effectiveFrom, ?string $note): int
    {
        $amount = self::normaliseAmount($amount);
        $note = $note === null || trim($note) === '' ? null : trim($note);

        return (int) Database::instance()->transaction(
            function () use ($userId, $amount, $effectiveFrom, $note): int {
                if (!$this->isEmployeeOfThisBranch($userId)) {
                    throw new RuntimeException('Salary can only be recorded for an employee of this branch.');
                }

                $previous = $this->current($userId);

                $id = $this->create([
                    'user_id' => $userId,
                    'amount' => $amount,
                    'effective_from' => $effectiveFrom,
                    'note' => $note,
                    'created_by' => Auth::id(),
                ]);

                Audit::record(
                    'employee.salary_changed',
                    'users',
                    $userId,
                    $previous === null
                        ? null
                        : ['amount' => $previous['amount'], 'effective_from' => $previous['effective_from']],
                    ['salary_id' => $id, 'amount' => $amount, 'effective_from' => $effectiveFrom, 'note' => $note]
                );

                return $id;
            }
        );
    }

    /**
     * The salary in effect today: the latest effective_from on or before
     * today, and the most recently recorded row when two share a date.
     *
     * @return array<string,mixed>|null
     */
    public function current(int $userId): ?array
    {
        return $this->db()->first(
            'SELECT s.id, s.amount, s.effective_from, s.note, s.created_at
             FROM employee_salaries s
             ' . self::EMPLOYEE_JOIN . '
             WHERE s.user_id = :user AND s.branch_id = :branch AND s.effective_from <= :today
             ORDER BY s.effective_from DESC, s.id DESC
             LIMIT 1',
            ['user' => $userId, 'branch' => $this->requireBranchId(), 'today' => self::today()]
        );
    }

    /**
     * The next scheduled change: the earliest future effective_from, and the
     * most recently recorded row for that date.
     *
     * @return array<string,mixed>|null
     */
    public function upcoming(int $userId): ?array
    {
        return $this->db()->first(
            'SELECT s.id, s.amount, s.effective_from, s.note, s.created_at
             FROM employee_salaries s
             ' . self::EMPLOYEE_JOIN . '
             WHERE s.user_id = :user AND s.branch_id = :branch AND s.effective_from > :today
             ORDER BY s.effective_from ASC, s.id DESC
             LIMIT 1',
            ['user' => $userId, 'branch' => $this->requireBranchId(), 'today' => self::today()]
        );
    }

    /**
     * Every row, newest effective date first. Each row carries `change`, the
     * difference from the row before it in effective order (null for the
     * first), and `status`: scheduled, current or past.
     *
     * @return list<array<string,mixed>>
     */
    public function history(int $userId): array
    {
        $rows = $this->db()->all(
            'SELECT s.id, s.amount, s.effective_from, s.note, s.created_at, c.name AS created_by_name
             FROM employee_salaries s
             ' . self::EMPLOYEE_JOIN . '
             LEFT JOIN users c ON c.id = s.created_by
             WHERE s.user_id = :user AND s.branch_id = :branch
             ORDER BY s.effective_from DESC, s.id DESC',
            ['user' => $userId, 'branch' => $this->requireBranchId()]
        );

        $today = self::today();
        $currentFound = false;
        $count = count($rows);
        foreach ($rows as $index => $row) {
            $older = $index + 1 < $count ? $rows[$index + 1] : null;
            $rows[$index]['change'] = $older === null
                ? null
                : bcsub((string) $row['amount'], (string) $older['amount'], 2);

            if ((string) $row['effective_from'] > $today) {
                $rows[$index]['status'] = 'scheduled';
            } elseif (!$currentFound) {
                $rows[$index]['status'] = 'current';
                $currentFound = true;
            } else {
                $rows[$index]['status'] = 'past';
            }
        }

        return $rows;
    }

    /**
     * Each listed employee's salary in effect today, in one query for a page
     * of the employee list. Employees with none are absent from the result.
     *
     * @param list<int> $userIds
     * @return array<int,string> user id => amount
     */
    public function currentForUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        // One generated placeholder per id; every value is bound.
        $placeholders = [];
        $params = ['branch' => $this->requireBranchId(), 'today' => self::today()];
        foreach (array_values($userIds) as $index => $userId) {
            $placeholders[] = ':u' . $index;
            $params['u' . $index] = $userId;
        }

        $rows = $this->db()->all(
            'SELECT ranked.user_id, ranked.amount FROM (
                 SELECT s.user_id, s.amount,
                        ROW_NUMBER() OVER (PARTITION BY s.user_id ORDER BY s.effective_from DESC, s.id DESC) AS position
                 FROM employee_salaries s
                 ' . self::EMPLOYEE_JOIN . '
                 WHERE s.branch_id = :branch AND s.effective_from <= :today
                   AND s.user_id IN (' . implode(', ', $placeholders) . ')
             ) ranked
             WHERE ranked.position = 1',
            $params
        );

        $amounts = [];
        foreach ($rows as $row) {
            $amounts[(int) $row['user_id']] = (string) $row['amount'];
        }

        return $amounts;
    }

    private function isEmployeeOfThisBranch(int $userId): bool
    {
        return $this->db()->value(
            "SELECT 1 FROM users u
             JOIN employee_profiles p ON p.user_id = u.id
             WHERE u.id = :id AND u.role = 'employee' AND p.branch_id = :branch",
            ['id' => $userId, 'branch' => $this->requireBranchId()]
        ) !== null;
    }

    /** "0045000.5" → "45000.50": the canonical form for the audit trail. Never via float. */
    private static function normaliseAmount(string $amount): string
    {
        [$whole, $fraction] = array_pad(explode('.', trim($amount), 2), 2, '');
        $whole = ltrim($whole, '0');

        return ($whole === '' ? '0' : $whole) . '.' . str_pad($fraction, 2, '0');
    }

    private static function today(): string
    {
        return date('Y-m-d');
    }
}
