<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Models\EmployeeProfile;
use App\Models\EmployeeSalary;
use App\Models\User;
use PDOException;

/**
 * The one way an employee login is created, whichever screen asked for it
 * (EmployeeController@store, the Users screen's "Add user" with role
 * employee, or approving a new joiner in JoinerController@approve). All
 * must write the login and its profile together and leave the same
 * security-log and audit trail, so none of those controllers does it itself.
 * An optional starting salary and any bank details are written in the same
 * transaction, so an employee is never left half-created.
 */
final class EmployeeOnboarding
{
    /**
     * Owner's defaults (2026-11-05) for every new employee: prefilled, and
     * editable, on each admin creation form and on joiner approval. Never
     * shown on or accepted from the public joiner form.
     */
    public const DEFAULT_DESIGNATION = 'Fronter';
    public const DEFAULT_STARTING_SALARY = '35000';

    /** Validator rules for the profile half of an employee; name and email are each screen's own. */
    public const PROFILE_RULES = [
        'employee_code' => 'nullable|max:30|regex:/^[A-Za-z0-9-]+$/',
        'phone' => 'nullable|max:30|regex:/^[0-9+()\s-]{7,30}$/',
        'designation' => 'required|max:120',
        'joining_date' => 'nullable|ymd',
    ];

    /**
     * The optional starting salary — creation screens only. The edit form
     * never takes a salary: a change goes through EmployeeController@changeSalary
     * so it always gets an effective date and its own audit entry.
     */
    public const STARTING_SALARY_RULES = [
        'salary' => 'nullable|' . EmployeeSalary::AMOUNT_RULE,
    ];

    /**
     * @param array<string,mixed> $clean Validator::validated() output covering name, email and PROFILE_RULES
     * @return array{
     *     name:string,email:string,phone:?string,designation:string,joining_date:?string,employee_code:?string
     * }
     */
    public static function normalise(array $clean): array
    {
        return [
            'name' => trim((string) $clean['name']),
            'email' => strtolower(trim((string) $clean['email'])),
            'phone' => ($clean['phone'] ?? null) === null ? null : trim((string) $clean['phone']),
            'designation' => trim((string) $clean['designation']),
            'joining_date' => ($clean['joining_date'] ?? null) === null ? null : (string) $clean['joining_date'],
            'employee_code' => ($clean['employee_code'] ?? null) === null
                ? null
                : trim((string) $clean['employee_code']),
        ];
    }

    /**
     * @param array<string,mixed> $clean Validator::validated() output covering STARTING_SALARY_RULES
     */
    public static function startingSalary(array $clean): ?string
    {
        return ($clean['salary'] ?? null) === null ? null : (string) $clean['salary'];
    }

    /**
     * Field errors for values another record already holds: the email (any
     * login, any branch — it is the sign-in name) and the Employee ID (unique
     * within this branch). Checked before writing so each shows next to its
     * field; the database's unique keys remain the final guard.
     *
     * @param array{email:string,employee_code:?string} $fields
     * @return array<string,string> field => message
     */
    public static function conflicts(array $fields, ?int $exceptUserId = null): array
    {
        $conflicts = [];
        if ((new User())->emailExists($fields['email'], $exceptUserId)) {
            $conflicts['email'] = 'That email is already in use by another account.';
        }
        if (
            $fields['employee_code'] !== null
            && (new EmployeeProfile())->employeeCodeExists($fields['employee_code'], $exceptUserId)
        ) {
            $conflicts['employee_code'] = 'Another employee in this branch already has that Employee ID.';
        }

        return $conflicts;
    }

    /**
     * The race conflicts() cannot close: two requests both pass the check,
     * and the second write hits a unique key (users.email or the branch's
     * Employee ID). Turns that into the same field errors conflicts() would
     * have given, instead of an error page. Anything else — another kind of
     * failure, or a duplicate that is not one of these fields — is rethrown.
     *
     * @param array{email:string,employee_code:?string} $fields
     * @return array<string,string> field => message, never empty
     * @throws PDOException
     */
    public static function conflictsFromDuplicate(PDOException $e, array $fields, ?int $exceptUserId = null): array
    {
        // 1062 is "duplicate entry" on both MySQL and MariaDB; SQLSTATE
        // 23000 alone would also match foreign-key and NOT NULL failures.
        if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
            throw $e;
        }

        $conflicts = self::conflicts($fields, $exceptUserId);
        if ($conflicts === []) {
            throw $e;
        }

        return $conflicts;
    }

    /**
     * Callers check conflicts() first, so they can show them as field
     * errors. The temporary password is returned for the one-time notice and
     * is never logged. A starting salary takes effect from the joining date,
     * or today when there is none; without one, no salary row is written.
     *
     * @param array{
     *     name:string,email:string,phone:?string,designation:string,joining_date:?string,employee_code?:?string
     * } $fields
     * @param array<string,?string> $bankColumns BankDetails::from() output, or none
     * @return array{id:int,temporaryPassword:string}
     */
    public static function create(array $fields, ?string $startingSalary = null, array $bankColumns = []): array
    {
        $temporaryPassword = bin2hex(random_bytes(9));
        $passwordHash = Auth::hash($temporaryPassword);

        $id = (int) Database::instance()->transaction(
            static function () use ($fields, $passwordHash, $startingSalary, $bankColumns): int {
                $id = (new EmployeeProfile())->createWithLogin($fields, $passwordHash, $bankColumns);

                $audited = [
                    'name' => $fields['name'],
                    'email' => $fields['email'],
                    'designation' => $fields['designation'],
                ];
                if (($fields['employee_code'] ?? null) !== null) {
                    $audited['employee_code'] = $fields['employee_code'];
                }
                if (array_filter($bankColumns) !== []) {
                    // That they exist, never what they are.
                    $audited['bank_details'] = 'on file';
                }
                Audit::record('employee.created', 'users', $id, null, $audited);

                if ($startingSalary !== null) {
                    (new EmployeeSalary())->record(
                        $id,
                        $startingSalary,
                        $fields['joining_date'] ?? date('Y-m-d'),
                        null
                    );
                }

                return $id;
            }
        );

        Logger::security('Employee created', ['user_id' => $id, 'by' => Auth::id()]);

        return ['id' => $id, 'temporaryPassword' => $temporaryPassword];
    }

    /** The flash shown once after creation — the only place the temporary password ever appears. */
    public static function credentialsNotice(string $name, string $email, string $temporaryPassword): string
    {
        return $name . ' added. Login: ' . $email . ' / temporary password: ' . $temporaryPassword
            . ' — this is shown once; the employee must change it on first sign-in.';
    }
}
