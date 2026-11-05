<?php

declare(strict_types=1);

namespace App\Core;

use ErrorException;
use Throwable;

/**
 * Converts warnings/notices into exceptions and renders a safe error page.
 *
 * In production the browser gets a generic page and nothing else: stack traces,
 * file paths, SQL and exception messages leak application internals and are
 * written only to the log.
 */
final class ErrorHandler
{
    private static bool $debug = false;

    public static function register(bool $debug): void
    {
        self::$debug = $debug;

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler([self::class, 'handle']);

        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                self::handle(new ErrorException(
                    $error['message'],
                    0,
                    $error['type'],
                    $error['file'],
                    $error['line']
                ));
            }
        });
    }

    public static function handle(Throwable $e): void
    {
        Logger::error($e->getMessage(), [
            'exception' => $e::class,
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
            'path'      => $_SERVER['REQUEST_URI'] ?? 'cli',
            'trace'     => self::traceWithoutArguments($e),
        ]);

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $e->getMessage() . PHP_EOL . implode(PHP_EOL, self::traceWithoutArguments($e)) . PHP_EOL);
            exit(1);
        }

        if (headers_sent()) {
            exit(1);
        }

        http_response_code(500);

        if (self::$debug) {
            header('Content-Type: text/plain; charset=utf-8');
            echo $e::class . ': ' . $e->getMessage() . "\n";
            echo $e->getFile() . ':' . $e->getLine() . "\n\n";
            echo implode("\n", self::traceWithoutArguments($e)) . "\n";
            exit(1);
        }

        $view = APP_PATH . '/Views/errors/500.php';
        if (is_file($view)) {
            $safeMessage = '';
            require $view;
        } else {
            echo 'An unexpected error occurred.';
        }
        exit(1);
    }

    /**
     * The stack as file:line and function only — never argument values.
     *
     * getTraceAsString() prints arguments unless zend.exception_ignore_args
     * is on, and an argument can be a password, a remember-me cookie or a
     * bank detail on its way into SecretBox::encrypt(). bootstrap.php turns
     * that setting on, but the log must not depend on it: this reads only
     * the frame keys that cannot hold a value.
     *
     * @return list<string>
     */
    public static function traceWithoutArguments(Throwable $e): array
    {
        $lines = [];
        foreach ($e->getTrace() as $index => $frame) {
            $lines[] = sprintf(
                '#%d %s(%s): %s%s%s()',
                $index,
                $frame['file'] ?? '[internal function]',
                isset($frame['line']) ? (string) $frame['line'] : '?',
                $frame['class'] ?? '',
                $frame['type'] ?? '',
                $frame['function']
            );
        }
        $lines[] = '#' . count($lines) . ' {main}';

        return $lines;
    }
}
