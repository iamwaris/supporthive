<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Models\EmployeeProfile;
use App\Models\EmployeeSalary;

/**
 * The one way an employee login is created, whichever screen asked for it
 * (EmployeeController@store or the Users screen's "Add user" with role
 * employee). Both must write the login and its profile together and leave
 * the same security-log and audit trail, so neither controller does it
 * itself. An optional starting salary is written in the same transaction,
 * so an employee is never left half-created with or without it.
 */
final class EmployeeOnboarding
{
    /** Validator rules for the profile half of an employee; name and email are each screen's own. */
    public const PROFILE_RULES = [
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
     * @return array{name:string,email:string,phone:?string,designation:string,joining_date:?string}
     */
    public static function normalise(array $clean): array
    {
        return [
            'name' => trim((string) $clean['name']),
            'email' => strtolower(trim((string) $clean['email'])),
            'phone' => ($clean['phone'] ?? null) === null ? null : trim((string) $clean['phone']),
            'designation' => trim((string) $clean['designation']),
            'joining_date' => ($clean['joining_date'] ?? null) === null ? null : (string) $clean['joining_date'],
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
     * Callers check email uniqueness first, so they can show it as a field
     * error. The temporary password is returned for the one-time notice and
     * is never logged. A starting salary takes effect from the joining date,
     * or today when there is none; without one, no salary row is written.
     *
     * @param array{name:string,email:string,phone:?string,designation:string,joining_date:?string} $fields
     * @return array{id:int,temporaryPassword:string}
     */
    public static function create(array $fields, ?string $startingSalary = null): array
    {
        $temporaryPassword = bin2hex(random_bytes(9));
        $passwordHash = Auth::hash($temporaryPassword);

        $id = (int) Database::instance()->transaction(
            static function () use ($fields, $passwordHash, $startingSalary): int {
                $id = (new EmployeeProfile())->createWithLogin($fields, $passwordHash);

                Audit::record('employee.created', 'users', $id, null, [
                    'name' => $fields['name'],
                    'email' => $fields['email'],
                    'designation' => $fields['designation'],
                ]);

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
