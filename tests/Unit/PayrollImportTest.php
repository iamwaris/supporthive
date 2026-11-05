<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\PayrollLineStatus;
use App\Services\Payroll\PayrollImport;
use PHPUnit\Framework\TestCase;

/**
 * Every branch of the payroll sheet validation, with synthetic employees.
 */
final class PayrollImportTest extends TestCase
{
    private const HEADER = ['EmpID', 'Salary', 'Absent', 'WorkingDays'];

    /** @return list<array{user_id:int,name:string,employee_code:?string,active:bool}> */
    private function employees(): array
    {
        return [
            ['user_id' => 1, 'name' => 'Ann', 'employee_code' => 'E-1', 'active' => true],
            ['user_id' => 2, 'name' => 'Bob', 'employee_code' => '7701', 'active' => true],
            ['user_id' => 3, 'name' => 'Cal', 'employee_code' => '9001', 'active' => false],
            ['user_id' => 4, 'name' => 'Dee', 'employee_code' => null, 'active' => true],
        ];
    }

    /**
     * @param list<list<string>> $data
     * @return array<int,list<string>>
     */
    private function sheet(array $data, array $header = self::HEADER): array
    {
        $rows = [1 => $header];
        foreach ($data as $index => $cells) {
            $rows[$index + 2] = $cells;
        }

        return $rows;
    }

    public function testValidSheetProducesFixedPointLinesAndListsMissingActiveEmployees(): void
    {
        $result = PayrollImport::validate($this->sheet([
            ['e-1', '45,000', '1.5', '26'],
            ['7701', '30000.5', '', '26.0'],
        ]), $this->employees());

        self::assertTrue($result->passes());
        self::assertSame([
            [
                'user_id' => 1, 'name' => 'Ann', 'employee_code' => 'E-1',
                'salary' => '45000.00', 'absent_days' => '1.5', 'working_days' => '26.0',
            ],
            [
                'user_id' => 2, 'name' => 'Bob', 'employee_code' => '7701',
                'salary' => '30000.50', 'absent_days' => '0.0', 'working_days' => '26.0',
            ],
        ], $result->lines);
        // Disabled Cal is not "missing"; Dee (no Employee ID) is.
        self::assertSame([['name' => 'Dee', 'employee_code' => null]], $result->missing);
    }

    public function testHeadersAreMatchedIgnoringCaseSpacesAndOrder(): void
    {
        $result = PayrollImport::validate(
            $this->sheet([['26', '0', '100', '7701']], ['Working Days', 'absent', 'SALARY', 'Employee ID']),
            $this->employees()
        );

        self::assertTrue($result->passes());
        self::assertSame('100.00', $result->lines[0]['salary']);
    }

    public function testMissingHeaderIsReportedOnTheHeaderRow(): void
    {
        $result = PayrollImport::validate(
            $this->sheet([['7701', '1', '0']], ['EmpID', 'Salary', 'Absent']),
            $this->employees()
        );

        self::assertFalse($result->passes());
        self::assertSame(1, $result->errors[0]['row']);
        self::assertStringContainsString('Missing column: WorkingDays', $result->errors[0]['message']);
        self::assertSame([], $result->lines);
    }

    public function testDuplicateHeaderColumn(): void
    {
        $result = PayrollImport::validate(
            $this->sheet([], ['EmpID', 'Salary', 'Absent', 'WorkingDays', 'Emp ID']),
            $this->employees()
        );

        self::assertStringContainsString('EmpID column appears twice', $result->errors[0]['message']);
    }

    public function testEmptyFileAndHeaderOnly(): void
    {
        self::assertSame('The file is empty.', PayrollImport::validate([], $this->employees())->errors[0]['message']);
        self::assertStringContainsString(
            'no employee rows',
            PayrollImport::validate($this->sheet([]), $this->employees())->errors[0]['message']
        );
    }

    /** @return array<string,array{0:list<string>,1:string}> */
    public static function badRows(): array
    {
        return [
            'missing EmpID' => [['', '1', '0', '26'], 'Employee ID is missing.'],
            'unknown EmpID' => [['NOPE', '1', '0', '26'], 'No employee with this Employee ID in this branch.'],
            'disabled employee' => [['9001', '1', '0', '26'], 'Employee 9001 is disabled.'],
            'salary missing' => [['7701', '', '0', '26'], 'Salary is missing.'],
            'salary text' => [['7701', 'abc', '0', '26'], 'Salary must be a number.'],
            'salary negative' => [['7701', '-5', '0', '26'], 'Salary cannot be negative.'],
            'salary 3 decimals' => [['7701', '1.234', '0', '26'], 'Salary may have at most 2 decimal places.'],
            'salary too large' => [['7701', '99999999999999', '0', '26'], 'Salary is too large.'],
            'absent negative' => [['7701', '1', '-1', '26'], 'Absent cannot be negative.'],
            'absent 2 decimals' => [['7701', '1', '0.25', '26'], 'Absent may have at most 1 decimal place.'],
            'working missing' => [['7701', '1', '0', ''], 'WorkingDays is missing.'],
            'working over 31' => [['7701', '1', '0', '40'], 'WorkingDays cannot be more than 31.'],
            'absent over working' => [['7701', '1', '10', '5'], 'Absent cannot be more than WorkingDays.'],
        ];
    }

    /**
     * @param list<string> $cells
     * @dataProvider badRows
     */
    public function testEachRowProblemIsReportedWithRowAndEmpIdAndNothingIsKept(array $cells, string $message): void
    {
        $result = PayrollImport::validate($this->sheet([['E-1', '1', '0', '26'], $cells]), $this->employees());

        self::assertFalse($result->passes());
        self::assertSame([], $result->lines, 'all-or-nothing: a valid row is not kept when another fails');
        self::assertContains(['row' => 3, 'employee_code' => $cells[0], 'message' => $message], $result->errors);
    }

    public function testDuplicateEmpIdPointsAtTheFirstRow(): void
    {
        $result = PayrollImport::validate(
            $this->sheet([['7701', '1', '0', '26'], ['7701', '2', '0', '26']]),
            $this->employees()
        );

        self::assertSame(
            [[
                'row' => 3,
                'employee_code' => '7701',
                'message' => 'Employee ID appears more than once (first on row 2).',
            ]],
            $result->errors
        );
    }

    public function testBlankRowsAboveAndBetweenAreIgnored(): void
    {
        $rows = [
            1 => [],
            2 => self::HEADER,
            3 => ['7701', '1', '0', '26'],
            4 => ['', ''],
            5 => ['E-1', '2', '0', '26'],
        ];

        $result = PayrollImport::validate($rows, $this->employees());

        self::assertTrue($result->passes());
        self::assertCount(2, $result->lines);
    }

    public function testHeaderNormalisation(): void
    {
        self::assertSame('workingdays', PayrollImport::normaliseHeader(' Working_Days '));
        self::assertSame('empid', PayrollImport::normaliseHeader('Emp. ID'));
    }

    public function testLineStatusRules(): void
    {
        self::assertTrue(PayrollLineStatus::Draft->canEdit());
        self::assertTrue(PayrollLineStatus::Query->canEdit());
        self::assertFalse(PayrollLineStatus::Sent->canEdit());
        self::assertFalse(PayrollLineStatus::Confirmed->canEdit());

        self::assertTrue(PayrollLineStatus::Draft->canSend());
        self::assertTrue(PayrollLineStatus::Sent->canSend());
        self::assertTrue(PayrollLineStatus::Query->canSend());
        self::assertFalse(PayrollLineStatus::Confirmed->canSend());

        self::assertTrue(PayrollLineStatus::Sent->acceptsResponse());
        self::assertFalse(PayrollLineStatus::Confirmed->acceptsResponse());
        self::assertFalse(PayrollLineStatus::Query->acceptsResponse());
        self::assertFalse(PayrollLineStatus::Draft->acceptsResponse());
    }
}
