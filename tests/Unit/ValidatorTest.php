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
}
