<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Domain\PayrollLineStatus;

/**
 * What an "add or update" sheet would do to a month (PAY-4): see
 * PayrollSheetUpdate::plan(). Holds no changes at all whenever there are
 * errors.
 */
final class PayrollSheetUpdatePlan
{
    /**
     * @param list<array{row:int,employee_code:string,message:string}> $errors
     * @param list<array{
     *     user_id:int,name:string,employee_code:string,salary:string,absent_days:string,working_days:string
     * }> $added new draft lines
     * @param list<array{
     *     line_id:int,user_id:int,name:string,employee_code:string,status:PayrollLineStatus,
     *     before:array<string,string>,after:array<string,string>
     * }> $updated lines whose figures change
     * @param int $unchanged rows whose figures already match their line
     * @param int $leftOut lines of the month that are not in the file
     * @param string $state PayrollSheetUpdate::stateOf() of the lines this plan was made against
     * @param list<array{
     *     user_id:int,name:string,employee_code:string,salary:string,absent_days:string,working_days:string
     * }> $sheetLines every valid row of the file
     */
    public function __construct(
        public readonly array $errors,
        public readonly array $added,
        public readonly array $updated,
        public readonly int $unchanged,
        public readonly int $leftOut,
        public readonly string $state,
        public readonly array $sheetLines,
    ) {
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    /** Sent lines whose link this plan cancels: the reason to confirm before applying. */
    public function linksCancelled(): int
    {
        return count(array_filter(
            $this->updated,
            static fn (array $change): bool => $change['status']->editCancelsLink()
        ));
    }

    /**
     * The file reduced to its valid rows, in the import format, to keep
     * between the summary and Apply. Applying validates these again, against
     * the employees and lines as they are then.
     *
     * @return array<int,list<string>>
     */
    public function sheetRows(): array
    {
        $rows = [1 => ['EmpID', 'Salary', 'Absent', 'WorkingDays']];
        foreach ($this->sheetLines as $line) {
            $rows[] = [$line['employee_code'], $line['salary'], $line['absent_days'], $line['working_days']];
        }

        return $rows;
    }

    /** "Added 1, updated 2 (1 link cancelled — resend those), unchanged 3." plus who was left out. */
    public function summary(): string
    {
        $cancelled = $this->linksCancelled();

        return 'Added ' . count($this->added) . ', updated ' . count($this->updated)
            . ($cancelled === 0
                ? ''
                : ' (' . $cancelled . ' ' . ($cancelled === 1 ? 'link' : 'links') . ' cancelled — resend '
                    . ($cancelled === 1 ? 'that' : 'those') . ')')
            . ', unchanged ' . $this->unchanged . '.'
            . ($this->leftOut === 0
                ? ''
                : ' ' . $this->leftOut . ' ' . ($this->leftOut === 1 ? 'employee' : 'employees')
                    . ' in this month ' . ($this->leftOut === 1 ? 'was' : 'were')
                    . ' not in the file and ' . ($this->leftOut === 1 ? 'was' : 'were') . ' left as they are.');
    }
}
