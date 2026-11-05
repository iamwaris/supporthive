<?php

declare(strict_types=1);

namespace App\Services\Payroll;

/**
 * Validates a month's payroll sheet (EmpID, Salary, Absent, WorkingDays)
 * against the branch's employees. Pure: rows in, result out, no database,
 * so every branch is unit-tested.
 *
 * All-or-nothing by design — the caller saves only an error-free result.
 * Salary is the FINAL amount to pay; Absent and WorkingDays are shown to the
 * employee as they are and nothing is calculated from them.
 */
final class PayrollImport
{
    public const MAX_ROWS = 2000;

    /** Highest salary a DECIMAL(15,2) column holds. */
    private const MAX_SALARY_DIGITS = 13;

    /** A month has at most 31 days, which also bounds the DECIMAL(5,1) columns. */
    private const MAX_DAYS = 31;

    /** @var array<string,list<string>> column => accepted headers, normalised (lowercase, no spaces/_/-/.) */
    private const HEADERS = [
        'employee_code' => ['empid', 'employeeid', 'empcode', 'employeecode'],
        'salary' => ['salary'],
        'absent_days' => ['absent', 'absentdays'],
        'working_days' => ['workingdays'],
    ];

    private const HEADER_LABELS = [
        'employee_code' => 'EmpID',
        'salary' => 'Salary',
        'absent_days' => 'Absent',
        'working_days' => 'WorkingDays',
    ];

    /**
     * @param array<int,list<string>> $rows sheet row number => cell values
     * @param list<array{user_id:int,name:string,employee_code:?string,active:bool}> $employees every employee
     *        of the branch, active or disabled
     */
    public static function validate(array $rows, array $employees): PayrollImportResult
    {
        $byCode = [];
        foreach ($employees as $employee) {
            if ($employee['employee_code'] !== null && $employee['employee_code'] !== '') {
                $byCode[self::codeKey($employee['employee_code'])] = $employee;
            }
        }

        $headerRow = null;
        foreach ($rows as $number => $cells) {
            if (!self::isBlank($cells)) {
                $headerRow = $number;
                break;
            }
        }
        if ($headerRow === null) {
            return new PayrollImportResult([], [self::error(1, '', 'The file is empty.')], [], []);
        }

        [$columns, $headerErrors] = self::mapHeader($rows[$headerRow], $headerRow);
        if ($headerErrors !== []) {
            return new PayrollImportResult([], $headerErrors, [], []);
        }

        $lines = [];
        $rowOf = [];
        $errors = [];
        $firstSeen = [];

        foreach ($rows as $number => $cells) {
            if ($number <= $headerRow || self::isBlank($cells)) {
                continue;
            }

            $code = trim($cells[$columns['employee_code']] ?? '');
            $rowErrors = [];

            $employee = null;
            if ($code === '') {
                $rowErrors[] = 'Employee ID is missing.';
            } elseif (isset($firstSeen[self::codeKey($code)])) {
                $rowErrors[] = 'Employee ID appears more than once (first on row '
                    . $firstSeen[self::codeKey($code)] . ').';
            } else {
                $firstSeen[self::codeKey($code)] = $number;
                $employee = $byCode[self::codeKey($code)] ?? null;
                if ($employee === null) {
                    $rowErrors[] = 'No employee with this Employee ID in this branch.';
                } elseif (!$employee['active']) {
                    $rowErrors[] = 'Employee ' . $code . ' is disabled.';
                    $employee = null;
                }
            }

            $salary = self::amount($cells[$columns['salary']] ?? '', 'Salary', 2, null, $rowErrors);
            $absent = self::amount($cells[$columns['absent_days']] ?? '', 'Absent', 1, '0', $rowErrors);
            $working = self::amount($cells[$columns['working_days']] ?? '', 'WorkingDays', 1, null, $rowErrors);

            if ($absent !== null && $working !== null && (float) $absent > (float) $working) {
                $rowErrors[] = 'Absent cannot be more than WorkingDays.';
            }

            foreach ($rowErrors as $message) {
                $errors[] = self::error($number, $code, $message);
            }

            if ($rowErrors === [] && $employee !== null && $salary !== null && $absent !== null && $working !== null) {
                $lines[] = [
                    'user_id' => $employee['user_id'],
                    'name' => $employee['name'],
                    'employee_code' => (string) $employee['employee_code'],
                    'salary' => $salary,
                    'absent_days' => $absent,
                    'working_days' => $working,
                ];
                $rowOf[$employee['user_id']] = $number;
            }
        }

        if ($lines === [] && $errors === []) {
            $errors[] = self::error($headerRow, '', 'There are no employee rows under the header.');
        }

        $missing = [];
        foreach ($employees as $employee) {
            $key = $employee['employee_code'] === null ? null : self::codeKey($employee['employee_code']);
            if ($employee['active'] && ($key === null || !isset($firstSeen[$key]))) {
                $missing[] = ['name' => $employee['name'], 'employee_code' => $employee['employee_code']];
            }
        }

        return $errors === []
            ? new PayrollImportResult($lines, [], $missing, $rowOf)
            : new PayrollImportResult([], $errors, $missing, []);
    }

    /**
     * Header text as compared: "Emp ID", "EMPID" and "emp_id" are the same.
     */
    public static function normaliseHeader(string $header): string
    {
        return (string) preg_replace('/[\s_.\-]+/u', '', mb_strtolower(trim($header)));
    }

    /**
     * @param list<string> $cells
     * @return array{0:array<string,int>,1:list<array{row:int,employee_code:string,message:string}>}
     */
    private static function mapHeader(array $cells, int $row): array
    {
        $columns = [];
        $errors = [];

        foreach ($cells as $index => $cell) {
            $normalised = self::normaliseHeader($cell);
            foreach (self::HEADERS as $column => $accepted) {
                if (!in_array($normalised, $accepted, true)) {
                    continue;
                }
                if (isset($columns[$column])) {
                    $errors[] = self::error($row, '', 'The ' . self::HEADER_LABELS[$column] . ' column appears twice.');
                }
                $columns[$column] = $index;
            }
        }

        foreach (self::HEADER_LABELS as $column => $label) {
            if (!isset($columns[$column])) {
                $errors[] = self::error(
                    $row,
                    '',
                    'Missing column: ' . $label
                    . '. The first row must name the columns EmpID, Salary, Absent and WorkingDays.'
                );
            }
        }

        return [$columns, $errors];
    }

    /**
     * A non-negative number with at most $decimals places, as a fixed-point
     * string ("45000.00"). Thousands separators are accepted. A blank cell
     * is $blank (null = required).
     *
     * @param list<string> $errors
     */
    private static function amount(string $raw, string $label, int $decimals, ?string $blank, array &$errors): ?string
    {
        $value = str_replace([',', ' ', "\u{00A0}"], '', trim($raw));
        if ($value === '') {
            if ($blank === null) {
                $errors[] = $label . ' is missing.';
                return null;
            }
            $value = $blank;
        }

        if (preg_match('/^-?\d+(\.\d+)?$/', $value) !== 1) {
            $errors[] = $label . ' must be a number.';
            return null;
        }
        if (str_starts_with($value, '-') && preg_match('/[1-9]/', $value) === 1) {
            $errors[] = $label . ' cannot be negative.';
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '-')), 2, '');
        $fraction = rtrim($fraction, '0');
        if (strlen($fraction) > $decimals) {
            $errors[] = $label . ' may have at most ' . $decimals
                . ' decimal place' . ($decimals === 1 ? '' : 's') . '.';
            return null;
        }

        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        $fixed = $whole . '.' . str_pad($fraction, $decimals, '0');

        if ($label === 'Salary' ? strlen($whole) > self::MAX_SALARY_DIGITS : (float) $fixed > self::MAX_DAYS) {
            $errors[] = $label === 'Salary'
                ? 'Salary is too large.'
                : $label . ' cannot be more than ' . self::MAX_DAYS . '.';
            return null;
        }

        return $fixed;
    }

    /** @param list<string> $cells */
    private static function isBlank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim($cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /** Employee IDs compare the way the column's case-insensitive collation does. */
    private static function codeKey(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    /** @return array{row:int,employee_code:string,message:string} */
    private static function error(int $row, string $code, string $message): array
    {
        return ['row' => $row, 'employee_code' => $code, 'message' => $message];
    }
}
