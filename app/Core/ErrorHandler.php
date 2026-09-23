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
            'trace'     => explode("\n", $e->getTraceAsString()),
        ]);

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL);
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
            echo $e->getTraceAsString() . "\n";
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
}
