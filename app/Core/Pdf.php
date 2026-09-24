<?php

declare(strict_types=1);

namespace App\Core;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * PDF export, spec §16. dompdf (decision 2026-09-24, docs/PLAN.md) — the
 * only runtime Composer dependency this app takes on.
 *
 * Remote/local file loading is disabled: a report PDF is built entirely from
 * a string this app generates, never from user-controlled HTML, but leaving
 * that on would be an SSRF/local-file-read surface for no benefit.
 */
final class Pdf
{
    public static function download(string $filename, string $html): never
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'Helvetica');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        http_response_code(200);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . self::safeFilename($filename) . '"');
        header('X-Content-Type-Options: nosniff');
        echo $dompdf->output();
        exit;
    }

    private static function safeFilename(string $filename): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) ?? 'export.pdf';
    }
}
