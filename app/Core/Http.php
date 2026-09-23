<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Request/response helpers plus the security header policy.
 */
final class Http
{
    /**
     * Sent on every response before any output.
     *
     * All front-end libraries are vendored under public/assets, so every source
     * stays 'self' - no CDN is trusted. Two documented concessions:
     *
     *  - script-src 'unsafe-eval': Alpine.js compiles x-* attribute expressions
     *    with new Function(). Remove it by switching to the @alpinejs/csp build,
     *    which requires all logic to live in x-data component methods.
     *  - style-src 'unsafe-inline': ApexCharts and DataTables inject <style>
     *    elements at runtime. Tailwind itself is a static compiled file and
     *    does not need this.
     *
     * Never add 'unsafe-inline' to script-src. That is the one that turns a
     * stored-XSS bug into full account takeover.
     */
    public static function sendSecurityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        header_remove('X-Powered-By');

        $csp = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self'",
            "connect-src 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "object-src 'none'",
        ];

        header('Content-Security-Policy: ' . implode('; ', $csp));
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('X-Permitted-Cross-Domain-Policies: none');

        if (Config::isProduction()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }
    }

    /**
     * Client IP. Proxy headers are only trusted when the app is explicitly
     * configured to sit behind a proxy, because they are attacker-controlled.
     */
    public static function clientIp(): string
    {
        if (Config::get('security.trust_proxy', false) === true) {
            $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            if (is_string($forwarded) && $forwarded !== '') {
                $first = trim(explode(',', $forwarded)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                    return $first;
                }
            }
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
    }

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function isPost(): bool
    {
        return self::method() === 'POST';
    }

    public static function path(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        return '/' . trim(is_string($path) ? $path : '/', '/');
    }

    /** Only same-origin relative paths are allowed, to prevent open redirects. */
    public static function redirect(string $path, int $status = 302): never
    {
        if (preg_match('#^(https?:)?//#i', $path) === 1) {
            $path = '/';
        }
        header('Location: ' . url($path), true, $status);
        exit;
    }

    /** @param array<string,mixed> $data */
    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function abort(int $status, string $message = ''): never
    {
        http_response_code($status);
        $view = APP_PATH . '/Views/errors/' . $status . '.php';
        if (!is_file($view)) {
            $view = APP_PATH . '/Views/errors/500.php';
        }
        $safeMessage = $message;
        require $view;
        exit;
    }
}
