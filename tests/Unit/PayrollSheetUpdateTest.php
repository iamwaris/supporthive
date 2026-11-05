<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payroll\PayrollSheetUpdate;
use PHPUnit\Framework\TestCase;

/**
 * Classification of an "add or update" sheet (PAY-4), with synthetic
 * employees and lines: no database.
 */
final class PayrollSheetUpdateTest extends TestCase
{
    /** @return list<array{user_id:int,name:string,employee_code:?string,active:bool}> */
    private function employees(): array
    {
        return [
            ['user_id' => 1, 'name' => 'Ann', 'employee_code' => 'E-1', 'active' => true],
            ['user_id' => 2, 'name' => 'Bob', 'employee_code' => 'E-2', 'active' => true],
            ['user_id' => 3, 'name' => 'Cal', 'employee_code' => 'E-3', 'active' => true],
            ['user_id' => 4, 'name' => 'Dee', 'employee_code' => 'E-4', 'active' => true],
        ];
    }

    /** @return array<string,mixed> */
    private function line(int $id, int $userId, string $status, string $salary): array
    {
        return [
            'id' => $id,
            'user_id' => $userId,
            'status' => $status,
            'salary' => $salary,
            'absent_days' => '0.0',
            'working_days' => '26.0',
            'token_expires_at' => $status === 'draft' ? null : '2026-12-01 00:00:00',
        ];
    }

    /**
     * @param list<list<string>> $data
     * @return array<int,list<string>>
     */
    private function sheet(array $data): array
    {
        return [1 => ['EmpID', 'Salary', 'Absent', 'WorkingDays'], ...$data];
    }

    public function testRowsAreNewUpdatedOrNumericallyUnchanged(): void
    {
        $plan = PayrollSheetUpdate::plan($this->sheet([
            ['E-1', '35000', '0', '26'],
            ['E-2', '36000', '0', '26'],
            ['E-3', '20000', '1', '26'],
            ['E-4', '1000', '0', '26'],
        ]), $this->employees(), [
            $this->line(11, 1, 'confirmed', '35000.00'),
            $this->line(12, 2, 'sent', '35000.00'),
            $this->line(13, 3, 'query', '20000.00'),
        ]);

        self::assertTrue($plan->passes());
        self::assertSame(1, $plan->unchanged, 'confirmed 35000 == 35000.00 is unchanged, not an error');
        self::assertSame([4], array_column($plan->added, 'user_id'));
        self::assertSame([12, 13], array_column($plan->updated, 'line_id'));
        self::assertSame(
            ['salary' => '35000.00', 'absent_days' => '0.0', 'working_days' => '26.0'],
            $plan->updated[0]['before']
        );
        self::assertSame('36000.00', $plan->updated[0]['after']['salary']);
        self::assertSame(1, $plan->linksCancelled(), 'only the sent line loses its link');
        self::assertSame(0, $plan->leftOut);
    }

    public function testAChangedConfirmedLineRejectsEverything(): void
    {
        $plan = PayrollSheetUpdate::plan($this->sheet([
            ['E-4', '1000', '0', '26'],
            ['E-1', '35000.01', '0', '26'],
        ]), $this->employees(), [$this->line(11, 1, 'confirmed', '35000.00')]);

        self::assertFalse($plan->passes());
        self::assertSame([[
            'row' => 3,
            'employee_code' => 'E-1',
            'message' => 'Employee E-1 has already confirmed — withdraw the month to change their figures.',
        ]], $plan->errors);
        self::assertSame([], $plan->added);
        self::assertSame([], $plan->updated);
    }

    public function testLinesNotInTheFileAreCountedAndAnInvalidRowStillRejects(): void
    {
        $lines = [$this->line(11, 1, 'sent', '1.00'), $this->line(12, 2, 'draft', '1.00')];

        $plan = PayrollSheetUpdate::plan($this->sheet([['E-4', '1000', '0', '26']]), $this->employees(), $lines);
        self::assertSame(2, $plan->leftOut);
        self::assertSame(
            'Added 1, updated 0, unchanged 0. '
            . '2 employees in this month were not in the file and were left as they are.',
            $plan->summary()
        );

        $bad = PayrollSheetUpdate::plan($this->sheet([['E-4', 'abc', '0', '26']]), $this->employees(), $lines);
        self::assertFalse($bad->passes());
        self::assertSame('Salary must be a number.', $bad->errors[0]['message']);
    }

    public function testTheStateChangesWithAnyLineChangeButNotWithOrder(): void
    {
        $a = $this->line(11, 1, 'sent', '1.00');
        $b = $this->line(12, 2, 'draft', '1.00');
        $state = PayrollSheetUpdate::stateOf([$a, $b]);

        self::assertSame($state, PayrollSheetUpdate::stateOf([$b, $a]));
        self::assertNotSame($state, PayrollSheetUpdate::stateOf([['status' => 'confirmed'] + $a, $b]));
        $resent = ['token_expires_at' => '2027-01-01 00:00:00'] + $a;
        self::assertNotSame($state, PayrollSheetUpdate::stateOf([$resent, $b]));
        self::assertNotSame($state, PayrollSheetUpdate::stateOf([$a]));
    }

    public function testTheHeldRowsValidateToTheSamePlan(): void
    {
        $lines = [$this->line(11, 1, 'sent', '35000.00')];
        $plan = PayrollSheetUpdate::plan($this->sheet([
            ['e-1', '36,000', '', '26'],
            ['E-4', '1000.5', '0.5', '26'],
        ]), $this->employees(), $lines);

        $again = PayrollSheetUpdate::plan($plan->sheetRows(), $this->employees(), $lines);

        self::assertEquals($plan, $again);
    }
}
