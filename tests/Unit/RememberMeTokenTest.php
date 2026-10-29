<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Core\RememberMe;
use App\Core\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The pure parts of "remember me": cookie parsing, the Secure decision, the
 * lifetime, and the login form's checkbox rule. The database-backed flow is
 * in tests/Feature/RememberMeTest.php.
 */
final class RememberMeTokenTest extends TestCase
{
    private mixed $originalSecure = null;
    private mixed $originalHttps = null;

    protected function setUp(): void
    {
        $this->originalSecure = Config::get('session.secure');
        $this->originalHttps = $_SERVER['HTTPS'] ?? null;
    }

    protected function tearDown(): void
    {
        $this->setSessionConfig('secure', $this->originalSecure);
        if ($this->originalHttps === null) {
            unset($_SERVER['HTTPS']);
        } else {
            $_SERVER['HTTPS'] = $this->originalHttps;
        }
    }

    public function testParseSplitsAWellFormedCookie(): void
    {
        $selector = str_repeat('a1', 12);
        $validator = str_repeat('f0', 32);

        self::assertSame(
            ['selector' => $selector, 'validator' => $validator],
            RememberMe::parse($selector . ':' . $validator)
        );
    }

    /** @return array<string,array{0:string}> */
    public static function malformedCookies(): array
    {
        $selector = str_repeat('a', 24);
        $validator = str_repeat('b', 64);

        return [
            'empty' => [''],
            'no separator' => [$selector . $validator],
            'short selector' => [substr($selector, 1) . ':' . $validator],
            'long validator' => [$selector . ':' . $validator . 'c'],
            'uppercase hex' => [strtoupper($selector) . ':' . $validator],
            'non-hex' => [str_repeat('z', 24) . ':' . $validator],
            'extra segment' => [$selector . ':' . $validator . ':' . $validator],
            'trailing newline' => [$selector . ':' . $validator . "\n"],
            'sql-ish' => ["' OR 1=1 --:" . $validator],
        ];
    }

    #[DataProvider('malformedCookies')]
    public function testParseRejectsAnythingIssueCouldNotHaveProduced(string $cookie): void
    {
        self::assertNull(RememberMe::parse($cookie));
    }

    public function testIssuedCookieShapeRoundTripsThroughParse(): void
    {
        // Same construction issue() uses, without the database insert.
        $cookie = bin2hex(random_bytes(12)) . ':' . bin2hex(random_bytes(32));

        self::assertNotNull(RememberMe::parse($cookie));
    }

    public function testCookieIsSecureWhenConfiguredForHttps(): void
    {
        unset($_SERVER['HTTPS']);
        $this->setSessionConfig('secure', true);

        self::assertTrue(RememberMe::cookieIsSecure());
    }

    public function testCookieIsSecureWhenTheRequestArrivedOverTls(): void
    {
        $this->setSessionConfig('secure', false);
        $_SERVER['HTTPS'] = 'on';

        self::assertTrue(RememberMe::cookieIsSecure());
    }

    public function testCookieIsNotSecureOnPlainHttpWithoutTheFlag(): void
    {
        $this->setSessionConfig('secure', false);
        $_SERVER['HTTPS'] = 'off';
        self::assertFalse(RememberMe::cookieIsSecure());

        unset($_SERVER['HTTPS']);
        self::assertFalse(RememberMe::cookieIsSecure());
    }

    public function testBootstrapKeepsArgumentsOutOfExceptionTraces(): void
    {
        // A trace that carried RememberMe::consume()'s argument would write
        // the cookie into storage/logs.
        self::assertSame('1', ini_get('zend.exception_ignore_args'));

        $trace = (static function (string $secret): \RuntimeException {
            return new \RuntimeException('x');
        })('do-not-log-me')->getTrace();
        self::assertStringNotContainsString('do-not-log-me', json_encode($trace) ?: '');
    }

    public function testLifetimeDefaultsToThirtyDays(): void
    {
        $reflection = new ReflectionClass(Config::class);
        $original = $reflection->getStaticPropertyValue('items');
        $withoutSetting = $original;
        unset($withoutSetting['session']['remember_days']);
        $reflection->setStaticPropertyValue('items', $withoutSetting);

        try {
            self::assertSame(30 * 86400, RememberMe::lifetimeSeconds());
        } finally {
            $reflection->setStaticPropertyValue('items', $original);
        }
    }

    /** @return array<string,array{0:array<string,mixed>,1:bool,2:?string}> */
    public static function rememberInputs(): array
    {
        return [
            'ticked' => [['remember' => '1'], true, '1'],
            'absent (unticked)' => [[], true, null],
            'empty string' => [['remember' => ''], true, null],
            'browser default "on"' => [['remember' => 'on'], false, null],
            'zero' => [['remember' => '0'], false, null],
            'array-shaped' => [['remember' => ['1']], false, null],
            'nested array' => [['remember' => ['a' => ['1']]], false, null],
        ];
    }

    /**
     * The exact rule AuthController::login() uses for the checkbox.
     *
     * @param array<string,mixed> $input
     */
    #[DataProvider('rememberInputs')]
    public function testRememberCheckboxRule(array $input, bool $passes, ?string $validated): void
    {
        $validator = Validator::make($input, ['remember' => 'nullable|in:1']);

        self::assertSame($passes, $validator->passes());
        if ($passes) {
            self::assertSame($validated, $validator->validated()['remember']);
        } else {
            self::assertArrayNotHasKey('remember', $validator->validated());
        }
    }

    private function setSessionConfig(string $key, mixed $value): void
    {
        $reflection = new ReflectionClass(Config::class);
        $items = $reflection->getStaticPropertyValue('items');
        $items['session'][$key] = $value;
        $reflection->setStaticPropertyValue('items', $items);
    }
}
