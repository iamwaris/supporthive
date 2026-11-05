<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Domain\PayrollLineStatus;

/**
 * "Upload sheet: add or update employees" (PAY-4): one file, in the import
 * format, that adds employees missing from a month and corrects the figures
 * of those already in it. Pure: sheet rows, the branch's employees and the
 * run's current lines in, a plan out — so every branch is unit-testable and
 * the same plan is recomputed under the run lock when it is applied.
 *
 * Each valid row is new (added as a draft), unchanged (figures numerically
 * equal: nothing is touched, a live link stays live), or an update. An update
 * to a confirmed line is an error for that row, and any error rejects the
 * whole file. Lines of the run that are not in the file are left alone.
 */
final class PayrollSheetUpdate
{
    private const FIGURES = ['salary', 'absent_days', 'working_days'];

    /**
     * @param array<int,list<string>> $rows sheet row number => cell values
     * @param list<array{user_id:int,name:string,employee_code:?string,active:bool}> $employees
     * @param list<array<string,mixed>> $runLines the run's current lines: id, user_id, status, the
     *        three figures and token_expires_at at least
     */
    public static function plan(array $rows, array $employees, array $runLines): PayrollSheetUpdatePlan
    {
        $state = self::stateOf($runLines);
        $sheet = PayrollImport::validate($rows, $employees);
        if (!$sheet->passes()) {
            return new PayrollSheetUpdatePlan($sheet->errors, [], [], 0, 0, $state, []);
        }

        $byUser = [];
        foreach ($runLines as $line) {
            $byUser[(int) $line['user_id']] = $line;
        }

        $errors = [];
        $added = [];
        $updated = [];
        $unchanged = 0;
        foreach ($sheet->lines as $new) {
            $current = $byUser[$new['user_id']] ?? null;
            unset($byUser[$new['user_id']]);

            if ($current === null) {
                $added[] = $new;
                continue;
            }

            $before = [];
            foreach (self::FIGURES as $figure) {
                $before[$figure] = (string) $current[$figure];
            }
            $after = [
                'salary' => $new['salary'],
                'absent_days' => $new['absent_days'],
                'working_days' => $new['working_days'],
            ];
            if (self::sameFigures($before, $after)) {
                $unchanged++;
                continue;
            }

            $status = PayrollLineStatus::from((string) $current['status']);
            if (!$status->canEdit()) {
                $errors[] = [
                    'row' => $sheet->rowOf[$new['user_id']] ?? 0,
                    'employee_code' => $new['employee_code'],
                    'message' => 'Employee ' . $new['employee_code']
                        . ' has already confirmed — withdraw the month to change their figures.',
                ];
                continue;
            }

            $updated[] = [
                'line_id' => (int) $current['id'],
                'user_id' => $new['user_id'],
                'name' => $new['name'],
                'employee_code' => $new['employee_code'],
                'status' => $status,
                'before' => $before,
                'after' => $after,
            ];
        }

        if ($errors !== []) {
            return new PayrollSheetUpdatePlan($errors, [], [], 0, 0, $state, []);
        }

        return new PayrollSheetUpdatePlan([], $added, $updated, $unchanged, count($byUser), $state, $sheet->lines);
    }

    /**
     * A fingerprint of everything about the run's lines the plan depends on.
     * The confirm step stores it; applying refuses if it no longer matches,
     * so the admin never applies a plan made against figures or statuses
     * that have since changed (an answer, a resend, an edit, another upload).
     *
     * @param list<array<string,mixed>> $runLines
     */
    public static function stateOf(array $runLines): string
    {
        $parts = [];
        foreach ($runLines as $line) {
            $parts[(int) $line['id']] = [
                (int) $line['user_id'],
                (string) $line['status'],
                (string) $line['salary'],
                (string) $line['absent_days'],
                (string) $line['working_days'],
                (string) ($line['token_expires_at'] ?? ''),
            ];
        }
        ksort($parts);

        return hash('sha256', (string) json_encode($parts));
    }

    /**
     * Figures compared as numbers: "35000" and "35000.00" are the same, so
     * an unchanged row never cancels a link.
     *
     * @param array<string,string> $before
     * @param array<string,string> $after
     */
    private static function sameFigures(array $before, array $after): bool
    {
        foreach (self::FIGURES as $figure) {
            if (self::canonical($before[$figure]) !== self::canonical($after[$figure])) {
                return false;
            }
        }

        return true;
    }

    /** "035000.50" -> "35000.5"; exact on strings, unlike float, for every DECIMAL(15,2) value. */
    private static function canonical(string $amount): string
    {
        [$whole, $fraction] = array_pad(explode('.', trim($amount), 2), 2, '');
        $whole = ltrim($whole, '0');
        $fraction = rtrim($fraction, '0');

        return ($whole === '' ? '0' : $whole) . ($fraction === '' ? '' : '.' . $fraction);
    }
}
