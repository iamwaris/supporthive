<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\ErrorHandler;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * An uncaught exception is logged with its stack, and a stack frame's
 * arguments can be secrets — a bank detail on its way into
 * SecretBox::encrypt(), a password, a cookie. The logged trace must never
 * contain them, whatever zend.exception_ignore_args happens to be set to.
 */
final class ErrorHandlerTraceTest extends TestCase
{
    private string|false $previousIgnoreArgs = false;

    protected function setUp(): void
    {
        $this->previousIgnoreArgs = ini_get('zend.exception_ignore_args');
    }

    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', (string) $this->previousIgnoreArgs);
    }

    private static function throwWith(string $sensitive): never
    {
        throw new RuntimeException('Encryption failed for a test value of length ' . strlen($sensitive));
    }

    public function testTheTraceHasLocationsAndFunctionsButNoArgumentsEvenWhenTheIniAllowsThem(): void
    {
        ini_set('zend.exception_ignore_args', '0');
        $secret = 'PK36' . bin2hex(random_bytes(5));

        try {
            self::throwWith($secret);
        } catch (RuntimeException $e) {
            $trace = implode("\n", ErrorHandler::traceWithoutArguments($e));
            self::assertStringNotContainsString($secret, $trace);
            self::assertStringContainsString(__FILE__, $trace);
            self::assertStringContainsString(self::class . '::throwWith()', $trace);
            self::assertStringEndsWith('{main}', $trace);

            // Proof the assertions above test something: the frame really
            // carries the secret. Read from getTrace(), not
            // getTraceAsString(), whose printing of it depends on
            // zend.exception_string_param_max_len (0 in php.ini-production,
            // which CI uses, so it prints '...').
            if (filter_var(ini_get('zend.exception_ignore_args'), FILTER_VALIDATE_BOOLEAN)) {
                self::markTestSkipped('zend.exception_ignore_args could not be turned off here, so the frame '
                    . 'carries no arguments to leak; the argument-free assertions above still ran.');
            }
            self::assertSame([$secret], $e->getTrace()[0]['args'] ?? null);
        }
    }

    /**
     * The real path: an uncaught exception in its own PHP process reaches
     * ErrorHandler::handle(), which writes the log and STDERR, with the
     * ini setting forced off first.
     */
    public function testAnUncaughtExceptionNeverWritesItsArgumentsToTheLogOrStderr(): void
    {
        $secret = 'SECRET' . bin2hex(random_bytes(4));
        $marker = 'trace-test-' . bin2hex(random_bytes(6));
        $script = tempnam(sys_get_temp_dir(), 'shtrace');
        self::assertIsString($script);
        file_put_contents($script, '<?php
declare(strict_types=1);
require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';
ini_set("zend.exception_ignore_args", "0");
function leaksIfLogged(string $value): void
{
    throw new RuntimeException(' . var_export($marker, true) . ');
}
leaksIfLogged(' . var_export($secret, true) . ');
');

        $process = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        unlink($script);

        $log = (string) @file_get_contents(STORAGE_PATH . '/logs/app-' . date('Y-m-d') . '.log');
        $entry = '';
        foreach (explode("\n", $log) as $line) {
            if (str_contains($line, $marker)) {
                $entry = $line;
            }
        }

        self::assertSame(1, $exitCode);
        self::assertNotSame('', $entry, 'The exception must be logged.');
        self::assertStringContainsString('leaksIfLogged()', $entry);
        self::assertStringNotContainsString($secret, $entry);
        self::assertStringContainsString($marker, $stderr);
        self::assertStringNotContainsString($secret, $stderr . $stdout);
    }
}
