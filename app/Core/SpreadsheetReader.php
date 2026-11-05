<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Reads an uploaded .xlsx or .csv into rows of cell strings. The format is
 * decided by the file's own bytes (a zip signature means a workbook), never
 * by the client's filename or MIME type.
 */
final class SpreadsheetReader
{
    public const MAX_CSV_BYTES = 2_097_152;

    /**
     * @return array<int,list<string>> row number (1-based) => cell values, trailing blanks trimmed
     * @throws UnreadableSpreadsheet
     */
    public static function read(string $path, int $maxRows): array
    {
        $handle = is_file($path) && is_readable($path) ? fopen($path, 'rb') : false;
        if ($handle === false) {
            throw new UnreadableSpreadsheet('The file could not be read. Please upload it again.');
        }
        $head = (string) fread($handle, 4);
        fclose($handle);

        if ($head === "PK\x03\x04") {
            return XlsxReader::rows($path, $maxRows);
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!str_starts_with($mime, 'text/') && $mime !== 'application/csv' && $mime !== 'application/x-empty') {
            throw new UnreadableSpreadsheet('Upload the sheet as an .xlsx workbook or a .csv file.');
        }

        return self::csvRows($path, $maxRows);
    }

    /**
     * CSV as Excel saves it: comma- or semicolon-separated (whichever the
     * first line uses more of), an optional UTF-8 BOM, and Windows-1252 text
     * when the file is not valid UTF-8.
     *
     * @return array<int,list<string>>
     * @throws UnreadableSpreadsheet
     */
    public static function csvRows(string $path, int $maxRows): array
    {
        $size = filesize($path);
        if ($size === false || $size > self::MAX_CSV_BYTES) {
            throw new UnreadableSpreadsheet('That file is too large to be a payroll sheet.');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new UnreadableSpreadsheet('The file could not be read. Please upload it again.');
        }

        try {
            $firstLine = (string) fgets($handle);
            $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
            rewind($handle);

            $rows = [];
            $rowNumber = 0;
            while (($record = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                $rowNumber++;
                if ($rowNumber > $maxRows) {
                    throw new UnreadableSpreadsheet('That sheet has more than ' . number_format($maxRows) . ' rows.');
                }

                $cells = array_map(static fn (?string $cell): string => self::text((string) $cell), $record);
                if ($rowNumber === 1 && isset($cells[0])) {
                    $cells[0] = trim((string) preg_replace('/^\xEF\xBB\xBF/', '', $cells[0]));
                }
                while ($cells !== [] && end($cells) === '') {
                    array_pop($cells);
                }
                $rows[$rowNumber] = array_values($cells);
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    private static function text(string $cell): string
    {
        if (!mb_check_encoding($cell, 'UTF-8')) {
            $cell = mb_convert_encoding($cell, 'UTF-8', 'Windows-1252');
        }

        return trim($cell);
    }
}
