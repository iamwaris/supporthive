<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Validator;
use App\Domain\PakistanIban;
use App\Services\BankDetails;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Pakistani IBAN check: format (PK + 2 check digits + 4-letter bank code
 * + 16 digits) and the ISO 13616 mod-97 checksum, after spaces, hyphens and
 * case are normalised. Plus the bank-detail rules that wrap it.
 */
final class PakistanIbanTest extends TestCase
{
    /** @return array<string,array{string}> */
    public static function validIbans(): array
    {
        return [
            'published example' => ['PK36SCBL0000001123456702'],
            'grouped in fours' => ['PK36 SCBL 0000 0011 2345 6702'],
            'lower case' => ['pk36scbl0000001123456702'],
            'hyphenated' => ['PK36-SCBL-0000-0011-2345-6702'],
        ];
    }

    #[DataProvider('validIbans')]
    public function testValidIbansPassAfterNormalising(string $input): void
    {
        self::assertTrue(PakistanIban::isValid(PakistanIban::normalise($input)));
    }

    public function testNormaliseRemovesSpacesAndHyphensAndUpperCases(): void
    {
        self::assertSame('PK36SCBL0000001123456702', PakistanIban::normalise(" pk36 scbl-0000 0011\t2345 6702 "));
    }

    /** @return array<string,array{string}> */
    public static function invalidIbans(): array
    {
        return [
            'wrong check digits' => ['PK37SCBL0000001123456702'],
            'one account digit changed' => ['PK36SCBL0000001123456703'],
            'two digits swapped' => ['PK36SCBL0000001123456720'],
            'too short' => ['PK36SCBL000000112345670'],
            'too long' => ['PK36SCBL00000011234567020'],
            'another country' => ['GB82WEST12345698765432'],
            'digits in the bank code' => ['PK36SCB10000001123456702'],
            'letters in the account' => ['PK36SCBL00000011234567AB'],
            'empty' => [''],
        ];
    }

    #[DataProvider('invalidIbans')]
    public function testInvalidIbansFail(string $input): void
    {
        self::assertFalse(PakistanIban::isValid(PakistanIban::normalise($input)));
    }

    public function testTheValidatorRuleUsesTheChecksum(): void
    {
        self::assertTrue(Validator::make(['iban' => 'PK36SCBL0000001123456702'], ['iban' => 'iban_pk'])->passes());

        $failed = Validator::make(['iban' => 'PK37SCBL0000001123456702'], ['iban' => 'iban_pk']);
        self::assertTrue($failed->fails());
        self::assertStringContainsString('Pakistani IBAN', (string) $failed->firstError('iban'));
        self::assertTrue(Validator::make(['iban' => ['PK36']], ['iban' => 'iban_pk'])->fails());
    }

    public function testBankRulesNormaliseThenValidateEachField(): void
    {
        $input = BankDetails::normaliseInput([
            'bank_name' => "  Meezan   Bank ",
            'bank_account_number' => '0123-4567 8901',
            'bank_iban' => 'pk36 scbl 0000 0011 2345 6702',
        ]);
        $validator = Validator::make($input, BankDetails::RULES);

        self::assertTrue($validator->passes());
        self::assertSame([
            'bank_name' => 'Meezan Bank',
            'bank_account_number' => '012345678901',
            'bank_iban' => 'PK36SCBL0000001123456702',
        ], $validator->validated());
    }

    public function testAccountNumberMustBeSixToTwentyFourDigitsAndBankNameAtMost100(): void
    {
        $rules = BankDetails::RULES;
        foreach (['12345', str_repeat('1', 25), '12345A', 'ACCOUNT'] as $bad) {
            $input = BankDetails::normaliseInput(['bank_account_number' => $bad]);
            self::assertTrue(Validator::make($input, $rules)->fails(), $bad . ' must fail');
        }
        foreach (['123456', str_repeat('9', 24)] as $good) {
            $input = BankDetails::normaliseInput(['bank_account_number' => $good]);
            self::assertTrue(Validator::make($input, $rules)->passes(), $good . ' must pass');
        }

        self::assertTrue(Validator::make(['bank_name' => str_repeat('a', 101)], $rules)->fails());
        self::assertTrue(Validator::make(['bank_name' => str_repeat('a', 100)], $rules)->passes());
    }

    public function testArrayShapedBankInputFailsValidationInsteadOfErroring(): void
    {
        $input = BankDetails::normaliseInput([
            'bank_name' => ['x'],
            'bank_account_number' => ['123456'],
            'bank_iban' => ['PK36SCBL0000001123456702'],
        ]);

        self::assertSame(
            ['bank_name', 'bank_account_number', 'bank_iban'],
            array_keys(Validator::make($input, BankDetails::RULES)->errors())
        );
    }

    public function testMaskShowsOnlyTheLastFourCharacters(): void
    {
        self::assertSame('****6702', BankDetails::mask('PK36SCBL0000001123456702'));
        self::assertSame('****8901', BankDetails::mask('012345678901'));
        self::assertNull(BankDetails::mask(null));
        self::assertNull(BankDetails::mask(''));
    }
}
