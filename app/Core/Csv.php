<?php

declare(strict_types=1);

namespace App\Core;

/**
 * CSV export, spec §16. Native PHP (`fputcsv`), no dependency — see the
 * runtime-dependency rule in CLAUDE.md.
 *
 * Streams straight to output rather than building a string in memory: a
 * report export should not grow memory with row count.
 */
final class Csv
{
    /**
     * @param list<string> $header
     * @param iterable<list<int|float|string|null>> $rows
     */
    public static function download(string $filename, array $header, iterable $rows): never
    {
        http_response_code(200);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . self::safeFilename($filename) . '"');
        header('X-Content-Type-Options: nosniff');

        $out = fopen('php://output', 'w');
        if ($out === false) {
            Http::abort(500);
        }

        // UTF-8 BOM so Excel opens the file without mangling non-ASCII text.
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, $header);
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }

        fclose($out);
        exit;
    }

    private static function safeFilename(string $filename): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) ?? 'export.csv';
    }
}
