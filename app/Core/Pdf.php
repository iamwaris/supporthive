<?php

declare(strict_types=1);

namespace App\Core;

use Dompdf\Canvas;
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
        // isPhpEnabled stays off (the default) — it would let a
        // <script type="text/php"> block in the HTML run through eval(),
        // banned by CLAUDE.md rule 14 even for HTML this app generates
        // itself. Page numbers are drawn below via the canvas API instead,
        // which needs no PHP-in-HTML at all.

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        self::drawPageNumbers($dompdf);

        http_response_code(200);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . self::safeFilename($filename) . '"');
        header('X-Content-Type-Options: nosniff');
        echo $dompdf->output();
        exit;
    }

    /**
     * "Page X of Y" in the bottom-right of every page. Must run after
     * render() — page count is not known until the whole document is laid
     * out — via Canvas::page_text(), which replaces {PAGE_NUM}/{PAGE_COUNT}
     * itself; this is dompdf's own token substitution, not string
     * interpolation into anything executable.
     */
    private static function drawPageNumbers(Dompdf $dompdf): void
    {
        $canvas = $dompdf->getCanvas();
        $fontMetrics = $dompdf->getFontMetrics();
        $font = $fontMetrics->getFont('helvetica', 'normal');
        $size = 8;

        // page_script() (not the deprecated string-callback form, which
        // eval()s) gives the real page number/count per page, so the text
        // can be measured and right-aligned exactly instead of estimating
        // width from the unsubstituted "{PAGE_NUM}" placeholder text.
        $canvas->page_script(
            function (int $pageNumber, int $pageCount, Canvas $canvas) use ($fontMetrics, $font, $size): void {
                $text = "Page {$pageNumber} of {$pageCount}";
                $textWidth = $fontMetrics->getTextWidth($text, $font, $size);
                $canvas->text(
                    $canvas->get_width() - $textWidth - 30,
                    $canvas->get_height() - 35,
                    $text,
                    $font,
                    $size,
                    [0.29, 0.33, 0.41]
                );
            }
        );
    }

    private static function safeFilename(string $filename): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) ?? 'export.pdf';
    }
}
