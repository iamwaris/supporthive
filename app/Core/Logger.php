<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Append-only file logger. Never log secrets: passwords, tokens, full card or ID numbers.
 */
final class Logger
{
    public const EMERGENCY = 'emergency';
    public const ERROR = 'error';
    public const WARNING = 'warning';
    public const INFO = 'info';
    public const SECURITY = 'security';

    /** @param array<string,mixed> $context */
    public static function log(string $level, string $message, array $context = []): void
    {
        $dir = STORAGE_PATH . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        $file = $dir . '/' . ($level === self::SECURITY ? 'security' : 'app') . '-' . date('Y-m-d') . '.log';
        $entry = sprintf(
            "[%s] %s: %s %s%s",
            date('c'),
            strtoupper($level),
            $message,
            $context === [] ? '' : json_encode(self::redact($context), JSON_UNESCAPED_SLASHES),
            PHP_EOL
        );

        @file_put_contents($file, $entry, FILE_APPEND | LOCK_EX);
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    private static function redact(array $context): array
    {
        $secret = ['password', 'pass', 'pwd', 'token', 'secret', 'authorization', 'cookie', 'csrf', 'api_key'];
        foreach ($context as $key => $value) {
            foreach ($secret as $needle) {
                if (stripos((string) $key, $needle) !== false) {
                    $context[$key] = '[redacted]';
                    continue 2;
                }
            }
            if (is_array($value)) {
                $context[$key] = self::redact($value);
            }
        }
        return $context;
    }

    /** @param array<string,mixed> $context */
    public static function security(string $message, array $context = []): void
    {
        self::log(self::SECURITY, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function error(string $message, array $context = []): void
    {
        self::log(self::ERROR, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function info(string $message, array $context = []): void
    {
        self::log(self::INFO, $message, $context);
    }
}
