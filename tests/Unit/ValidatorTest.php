<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function testRequiredFieldFails(): void
    {
        $v = Validator::make(['email' => ''], ['email' => 'required|email']);
        self::assertTrue($v->fails());
        self::assertSame('The email field is required.', $v->firstError('email'));
    }

    public function testValidDataPasses(): void
    {
        $v = Validator::make(['email' => ' user@example.com '], ['email' => 'required|email|max:190']);
        self::assertTrue($v->passes());
        self::assertSame('user@example.com', $v->validated()['email']);
    }

    public function testUnruledFieldsAreDropped(): void
    {
        $v = Validator::make(['name' => 'Ada', 'role' => 'admin'], ['name' => 'required|max:50']);
        self::assertTrue($v->passes());
        self::assertArrayNotHasKey('role', $v->validated(), 'mass assignment must not pass through');
    }

    public function testYmdAcceptsARealCalendarDate(): void
    {
        foreach (['2026-10-08', '2024-02-29', '1999-12-31'] as $date) {
            $v = Validator::make(['joining_date' => $date], ['joining_date' => 'nullable|ymd']);
            self::assertTrue($v->passes(), "{$date} should pass");
            self::assertSame($date, $v->validated()['joining_date']);
        }
    }

    /** DateTime would roll 2026-02-30 into March; the round-trip check refuses it. */
    public function testYmdRejectsImpossibleOrLooselyFormattedDates(): void
    {
        $invalid = ['2026-02-30', '2025-02-29', '2026-13-01', '2026-1-5', '08/10/2026', 'next tuesday', '2026-10-08x'];
        foreach ($invalid as $date) {
            $v = Validator::make(['joining_date' => $date], ['joining_date' => 'nullable|ymd']);
            self::assertTrue($v->fails(), "{$date} should fail");
            self::assertSame('The joining date must be a valid date (YYYY-MM-DD).', $v->firstError('joining_date'));
        }
    }

    public function testYmdIsOptionalWhenNullable(): void
    {
        $v = Validator::make(['joining_date' => ''], ['joining_date' => 'nullable|ymd']);
        self::assertTrue($v->passes());
        self::assertNull($v->validated()['joining_date']);
    }

    /** The employee phone rule: a regex with no `|`, so the rule string still splits correctly. */
    public function testEmployeePhoneRule(): void
    {
        $rule = ['phone' => 'nullable|max:30|regex:/^[0-9+()\s-]{7,30}$/'];

        foreach (['+92 300 1234567', '(042) 111-222-333', '0300-1234567'] as $phone) {
            self::assertTrue(Validator::make(['phone' => $phone], $rule)->passes(), "{$phone} should pass");
        }
        foreach (['12345', 'call me', '0300<script>', str_repeat('1', 31)] as $phone) {
            self::assertTrue(Validator::make(['phone' => $phone], $rule)->fails(), "{$phone} should fail");
        }
    }

    /** The salary amount rule: money (digits, at most 2 decimals) plus the 0..9,999,999,999.99 range. */
    public function testMoneyRuleWithTheSalaryRange(): void
    {
        $rule = ['amount' => 'required|money|between:0,9999999999.99'];

        foreach (['0', '45000', '45000.5', '45000.50', '9999999999.99', ' 1200 '] as $amount) {
            $v = Validator::make(['amount' => $amount], $rule);
            self::assertTrue($v->passes(), "{$amount} should pass");
        }

        $invalid = ['-1', '1.234', '1,000', '1e5', '.5', '5.', 'abc', '10000000000', '9999999999.995', '١'];
        foreach ($invalid as $amount) {
            self::assertTrue(Validator::make(['amount' => $amount], $rule)->fails(), "{$amount} should fail");
        }

        self::assertSame(
            'The amount must be an amount in digits with at most 2 decimal places, e.g. 45000.50.',
            Validator::make(['amount' => '12.345'], $rule)->firstError('amount')
        );
        $optional = Validator::make(['amount' => ''], ['amount' => 'nullable|money']);
        self::assertSame(['amount' => null], $optional->validated());
        self::assertTrue(Validator::make(['amount' => ['1']], $rule)->fails(), 'an array is never money');
    }
}
