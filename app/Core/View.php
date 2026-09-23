<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Plain PHP templates with a layout.
 *
 * Data is extracted into scope but every value must still be printed through
 * e() in the template. There is no auto-escaping here, so the rule is absolute:
 * <?= e($var) ?>, never <?= $var ?>.
 */
final class View
{
    /** @param array<string,mixed> $data */
    public static function render(string $template, array $data = [], string $layout = 'layouts/app'): void
    {
        echo self::capture($template, $data, $layout);
    }

    /** @param array<string,mixed> $data */
    public static function capture(string $template, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $content = self::renderFile($template, $data);

        if ($layout === null) {
            return $content;
        }

        return self::renderFile($layout, $data + ['content' => $content]);
    }

    /** @param array<string,mixed> $data */
    private static function renderFile(string $template, array $data): string
    {
        $path = APP_PATH . '/Views/' . str_replace(['..', '\\'], '', $template) . '.php';

        if (!is_file($path)) {
            throw new RuntimeException('View not found: ' . $template);
        }

        $render = static function (string $__path, array $__data): string {
            // phpcs:ignore
            extract($__data, EXTR_SKIP);
            ob_start();
            require $__path;
            return (string) ob_get_clean();
        };

        return $render($path, $data);
    }
}
