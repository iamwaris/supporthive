<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Core\Auth;
use App\Core\Database;
use App\Domain\PayrollLineStatus;
use App\Models\Account;
use App\Models\Category;
use App\Services\Audit;
use App\Services\TransactionService;
use RuntimeException;

/**
 * "Mark as paid" on a confirmed salary notice line, and its undo (PAY-5).
 *
 * Paying posts the line's final salary as an ordinary expense in the
 * Salaries category, from the account and on the date the admin chose,
 * through TransactionService (which also links the expense to the line).
 * Undoing voids that expense through TransactionService's salary void path
 * and clears the link. Each runs in one database transaction with the line
 * row locked, so a double submit waits for the first and is then refused:
 * one expense, one audit row.
 */
final class SalaryPayment
{
    public const CATEGORY_NAME = 'Salaries';
    private const UNDO_REASON = 'Salary payment undone on the salary notice (Mark as unpaid).';

    /**
     * Throws SalaryPaymentRefused, with the message for the admin, when the
     * rules refuse the payment.
     *
     * @param string $paidOn YYYY-MM-DD, not in the future
     * @return int the expense's transaction id
     */
    public static function pay(int $runId, int $lineId, int $accountId, string $paidOn): int
    {
        if ($paidOn > date('Y-m-d')) {
            throw new SalaryPaymentRefused('The payment date cannot be in the future.', 'paid_at');
        }
        if ((new Account())->findActive($accountId) === null) {
            throw new SalaryPaymentRefused('Choose an active account in this branch.', 'account_id');
        }

        $branchId = self::requireBranchId();

        return (int) Database::instance()->transaction(static function (Database $db) use (
            $runId,
            $lineId,
            $accountId,
            $paidOn,
            $branchId
        ): int {
            $line = self::lockLine($db, $runId, $lineId, $branchId);
            if ($line['paid_transaction_id'] !== null) {
                throw new SalaryPaymentRefused('This salary is already marked as paid.');
            }
            if ((string) $line['status'] !== PayrollLineStatus::Confirmed->value) {
                throw new SalaryPaymentRefused('Only a notice the employee has confirmed can be marked as paid.');
            }

            $context = $db->first(
                'SELECT r.period_month, u.name FROM payroll_runs r JOIN users u ON u.id = :user
                 WHERE r.id = :run AND r.branch_id = :branch',
                ['user' => (int) $line['user_id'], 'run' => $runId, 'branch' => $branchId]
            );
            if ($context === null) {
                throw new RuntimeException('The salary notice run is missing.');
            }

            $categoryId = self::salaryCategoryId($db, $branchId);
            $amount = (string) $line['salary'];
            $transactionId = TransactionService::postSalaryPayment(
                $lineId,
                $amount,
                $accountId,
                $categoryId,
                $paidOn,
                self::description(
                    (string) $context['period_month'],
                    (string) $context['name'],
                    (string) $line['employee_code']
                )
            );

            Audit::record('payroll_line.paid', 'payroll_run_lines', $lineId, null, [
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'account_id' => $accountId,
                'category_id' => $categoryId,
                'paid_at' => $paidOn,
            ]);

            return $transactionId;
        });
    }

    /**
     * Void the line's expense and clear its paid columns. Throws
     * SalaryPaymentRefused when the line is not marked as paid.
     */
    public static function undo(int $runId, int $lineId): void
    {
        $branchId = self::requireBranchId();

        Database::instance()->transaction(static function (Database $db) use ($runId, $lineId, $branchId): void {
            $line = self::lockLine($db, $runId, $lineId, $branchId);
            if ($line['paid_transaction_id'] === null) {
                throw new SalaryPaymentRefused('This salary is not marked as paid.');
            }

            $transactionId = (int) $line['paid_transaction_id'];
            TransactionService::voidSalaryPayment($transactionId, self::UNDO_REASON);

            $db->update(
                'payroll_run_lines',
                ['paid_at' => null, 'paid_by' => null, 'paid_transaction_id' => null, 'paid_account_id' => null],
                'id = :id AND branch_id = :branch',
                ['id' => $lineId, 'branch' => $branchId]
            );

            Audit::record(
                'payroll_line.unpaid',
                'payroll_run_lines',
                $lineId,
                [
                    'transaction_id' => $transactionId,
                    'amount' => $line['salary'],
                    'account_id' => $line['paid_account_id'],
                    'paid_at' => $line['paid_at'],
                ],
                ['voided_transaction_id' => $transactionId]
            );
        });
    }

    /** "Salary October 2026 — Pay Ann (PAY-1)": the expense's description, also how the run page finds it. */
    public static function description(string $periodMonth, string $name, string $employeeCode): string
    {
        return 'Salary ' . PayrollNoticeSender::monthLabel($periodMonth) . ' — ' . $name . ' (' . $employeeCode . ')';
    }

    /**
     * The line, locked for this transaction. Not found here means another
     * branch's or another run's line: the controller has already resolved
     * it, so this only happens if it was deleted meanwhile.
     *
     * @return array<string,mixed>
     */
    private static function lockLine(Database $db, int $runId, int $lineId, int $branchId): array
    {
        $line = $db->first(
            'SELECT id, user_id, employee_code, status, salary, paid_at, paid_transaction_id, paid_account_id
             FROM payroll_run_lines
             WHERE id = :id AND run_id = :run AND branch_id = :branch
             FOR UPDATE',
            ['id' => $lineId, 'run' => $runId, 'branch' => $branchId]
        );
        if ($line === null) {
            throw new SalaryPaymentRefused('That salary notice line no longer exists.');
        }

        return $line;
    }

    /**
     * The branch's active expense category for salaries: "Salaries" (every
     * branch starts with it), else "Salary". If neither exists, "Salaries" is
     * created the way the Categories page creates one. A Salaries category
     * the admin switched off is not quietly switched back on.
     */
    private static function salaryCategoryId(Database $db, int $branchId): int
    {
        $id = $db->value(
            "SELECT id FROM categories
             WHERE branch_id = :branch AND type = 'expense' AND is_active = 1 AND name IN ('Salaries', 'Salary')
             ORDER BY name = 'Salaries' DESC, parent_id IS NOT NULL, id
             LIMIT 1",
            ['branch' => $branchId]
        );
        if ($id !== null) {
            return (int) $id;
        }

        $categories = new Category();
        if ($categories->exists(self::CATEGORY_NAME, 'expense', null)) {
            throw new SalaryPaymentRefused(
                'The "' . self::CATEGORY_NAME . '" expense category is switched off. '
                . 'Switch it back on under Categories, then mark the salary as paid.'
            );
        }

        $categoryData = ['name' => self::CATEGORY_NAME, 'type' => 'expense', 'parent_id' => null, 'sort_order' => 10];
        $categoryId = $categories->create($categoryData);
        Audit::record('category.created', 'categories', $categoryId, null, $categoryData);

        return $categoryId;
    }

    private static function requireBranchId(): int
    {
        $branchId = Auth::branchId();
        if ($branchId === null) {
            throw new RuntimeException('No active branch — cannot record a salary payment.');
        }

        return $branchId;
    }
}
