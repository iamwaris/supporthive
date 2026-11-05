<?php

declare(strict_types=1);

namespace App\Services\Payroll;

/** The outcome of PayrollImport::validate(): lines to save, or the reasons not to. */
final class PayrollImportResult
{
    /**
     * @param list<array{
     *     user_id:int,name:string,employee_code:string,salary:string,absent_days:string,working_days:string
     * }> $lines empty whenever there are errors
     * @param list<array{row:int,employee_code:string,message:string}> $errors
     * @param list<array{name:string,employee_code:?string}> $missing active employees not in the file
     */
    public function __construct(
        public readonly array $lines,
        public readonly array $errors,
        public readonly array $missing,
    ) {
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }
}
