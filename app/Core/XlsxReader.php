<?php

declare(strict_types=1);

namespace App\Core;

use XMLReader;
use ZipArchive;

/**
 * Reads the cell values of an .xlsx workbook's first worksheet, with nothing
 * but PHP's zip and XML extensions (no runtime Composer dependency, see
 * CLAUDE.md).
 *
 * Deliberately small: shared strings, inline strings, and the cached <v>
 * value of every other cell (so a formula reads as its last calculated
 * result). Styles, number formats, dates and merged cells are ignored — a
 * payroll sheet needs the values only.
 *
 * The file is untrusted, so every limit is enforced while reading, not
 * after: entries are read with a byte cap (a zip bomb's declared size
 * cannot be trusted, so the cap is on what is actually inflated), any
 * DOCTYPE is refused outright (no entity expansion, no external entities —
 * a real workbook never has one), and rows, columns and shared strings are
 * counted.
 */
final class XlsxReader
{
    public const MAX_ENTRY_BYTES = 20_971_520;
    public const MAX_ENTRIES = 2000;
    public const MAX_COLUMNS = 100;
    public const MAX_SHARED_STRINGS = 200_000;

    private const DEFAULT_SHEET = 'xl/worksheets/sheet1.xml';

    public static function isAvailable(): bool
    {
        return class_exists(ZipArchive::class) && class_exists(XMLReader::class);
    }

    /**
     * @return array<int,list<string>> spreadsheet row number (1-based) => cell values, trailing blanks trimmed
     * @throws UnreadableSpreadsheet
     */
    public static function rows(string $path, int $maxRows): array
    {
        if (!self::isAvailable()) {
            throw new UnreadableSpreadsheet(
                'This server cannot read .xlsx files (the PHP zip extension is missing). '
                . 'Save the sheet as .csv and upload that.'
            );
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new UnreadableSpreadsheet('That file is not a readable .xlsx workbook.');
        }

        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new UnreadableSpreadsheet('That workbook has too many parts to be a payroll sheet.');
            }

            $sharedStrings = $zip->locateName('xl/sharedStrings.xml') === false
                ? []
                : self::sharedStrings(self::entry($zip, 'xl/sharedStrings.xml'));

            return self::sheetRows(self::entry($zip, self::firstSheetName($zip)), $sharedStrings, $maxRows);
        } finally {
            $zip->close();
        }
    }

    /** The workbook's first sheet in tab order, through its relationship; sheet1.xml when that cannot be resolved. */
    private static function firstSheetName(ZipArchive $zip): string
    {
        if ($zip->locateName('xl/workbook.xml') === false || $zip->locateName('xl/_rels/workbook.xml.rels') === false) {
            return self::DEFAULT_SHEET;
        }

        $relationId = null;
        $reader = self::xml(self::entry($zip, 'xl/workbook.xml'));
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'sheet') {
                $relationId = $reader->getAttributeNs(
                    'id',
                    'http://schemas.openxmlformats.org/officeDocument/2006/relationships'
                );
                break;
            }
        }
        $reader->close();

        if ($relationId === null || $relationId === '') {
            return self::DEFAULT_SHEET;
        }

        $reader = self::xml(self::entry($zip, 'xl/_rels/workbook.xml.rels'));
        $target = null;
        while ($reader->read()) {
            if (
                $reader->nodeType === XMLReader::ELEMENT
                && $reader->localName === 'Relationship'
                && $reader->getAttribute('Id') === $relationId
            ) {
                $target = (string) $reader->getAttribute('Target');
                break;
            }
        }
        $reader->close();

        if ($target === null) {
            return self::DEFAULT_SHEET;
        }

        // Targets are relative to xl/ ("worksheets/sheet1.xml") or absolute
        // in the package ("/xl/worksheets/sheet1.xml"). Anything that tries
        // to climb out of the package is ignored.
        $name = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
        if (str_contains($name, '..') || preg_match('#^xl/worksheets/[A-Za-z0-9_.-]+\.xml$#', $name) !== 1) {
            return self::DEFAULT_SHEET;
        }

        return $zip->locateName($name) === false ? self::DEFAULT_SHEET : $name;
    }

    private static function entry(ZipArchive $zip, string $name): string
    {
        $stat = $zip->statName($name);
        if ($stat === false) {
            throw new UnreadableSpreadsheet('That workbook has no worksheet to read.');
        }
        if ((int) $stat['size'] > self::MAX_ENTRY_BYTES) {
            throw new UnreadableSpreadsheet('That workbook is too large to be a payroll sheet.');
        }

        // Read at most one byte past the cap: a declared size can lie, the
        // inflated length cannot.
        $contents = $zip->getFromName($name, self::MAX_ENTRY_BYTES + 1);
        if ($contents === false) {
            throw new UnreadableSpreadsheet('That workbook is damaged and could not be read.');
        }
        if (strlen($contents) > self::MAX_ENTRY_BYTES) {
            throw new UnreadableSpreadsheet('That workbook is too large to be a payroll sheet.');
        }

        return $contents;
    }

    private static function xml(string $contents): XMLReader
    {
        // No workbook part ever declares a DTD; refusing one rules out
        // entity expansion and external entities without relying on
        // libxml's defaults.
        if (stripos($contents, '<!DOCTYPE') !== false || stripos($contents, '<!ENTITY') !== false) {
            throw new UnreadableSpreadsheet('That workbook contains content this reader does not accept.');
        }

        $reader = XMLReader::XML($contents, null, LIBXML_NONET | LIBXML_COMPACT);
        if (!$reader instanceof XMLReader) {
            throw new UnreadableSpreadsheet('That workbook is damaged and could not be read.');
        }

        return $reader;
    }

    /** @return list<string> */
    private static function sharedStrings(string $contents): array
    {
        $strings = [];
        $current = null;
        $phoneticDepth = 0;
        $reader = self::xml($contents);

        while (self::advance($reader)) {
            if ($reader->nodeType === XMLReader::ELEMENT) {
                if ($reader->localName === 'si') {
                    $current = '';
                    if ($reader->isEmptyElement) {
                        $strings[] = '';
                        $current = null;
                    }
                } elseif ($reader->localName === 'rPh' && !$reader->isEmptyElement) {
                    $phoneticDepth++;
                } elseif ($reader->localName === 't' && $current !== null && $phoneticDepth === 0) {
                    $current .= $reader->readString();
                }
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT) {
                if ($reader->localName === 'rPh') {
                    $phoneticDepth = max(0, $phoneticDepth - 1);
                } elseif ($reader->localName === 'si' && $current !== null) {
                    $strings[] = $current;
                    $current = null;
                    if (count($strings) > self::MAX_SHARED_STRINGS) {
                        throw new UnreadableSpreadsheet('That workbook holds too much text to be a payroll sheet.');
                    }
                }
            }
        }
        $reader->close();

        return $strings;
    }

    /**
     * @param list<string> $sharedStrings
     * @return array<int,list<string>>
     */
    private static function sheetRows(string $contents, array $sharedStrings, int $maxRows): array
    {
        $rows = [];
        $rowNumber = 0;
        $cells = null;
        $column = 0;
        $type = 'n';
        $value = null;
        $inCell = false;
        $phoneticDepth = 0;
        $reader = self::xml($contents);

        while (self::advance($reader)) {
            if ($reader->nodeType === XMLReader::ELEMENT) {
                switch ($reader->localName) {
                    case 'row':
                        $rowNumber = self::rowNumber($reader->getAttribute('r'), $rowNumber + 1);
                        if (count($rows) >= $maxRows) {
                            throw new UnreadableSpreadsheet(
                                'That sheet has more than ' . number_format($maxRows) . ' rows.'
                            );
                        }
                        $cells = [];
                        $column = 0;
                        if ($reader->isEmptyElement) {
                            $rows[$rowNumber] = [];
                            $cells = null;
                        }
                        break;
                    case 'c':
                        $column = self::columnIndex($reader->getAttribute('r'), $column + 1);
                        $type = (string) ($reader->getAttribute('t') ?? 'n');
                        $value = null;
                        $inCell = !$reader->isEmptyElement;
                        break;
                    case 'v':
                        if ($inCell) {
                            $value = $reader->readString();
                        }
                        break;
                    case 'rPh':
                        if (!$reader->isEmptyElement) {
                            $phoneticDepth++;
                        }
                        break;
                    case 't':
                        if ($inCell && $type === 'inlineStr' && $phoneticDepth === 0) {
                            $value = ($value ?? '') . $reader->readString();
                        }
                        break;
                }
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT) {
                if ($reader->localName === 'rPh') {
                    $phoneticDepth = max(0, $phoneticDepth - 1);
                } elseif ($reader->localName === 'c' && $inCell) {
                    $inCell = false;
                    if ($cells !== null && $column <= self::MAX_COLUMNS) {
                        $cells[$column] = self::cellValue($type, $value, $sharedStrings);
                    }
                } elseif ($reader->localName === 'row' && $cells !== null) {
                    $rows[$rowNumber] = self::denseRow($cells);
                    $cells = null;
                }
            }
        }
        $reader->close();

        ksort($rows);

        return $rows;
    }

    /** read(), but a parse error is the uploader's problem, reported in their words. */
    private static function advance(XMLReader $reader): bool
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $more = $reader->read();
            $errors = libxml_get_errors();
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($previous);
        }

        if ($errors !== []) {
            throw new UnreadableSpreadsheet('That workbook is damaged and could not be read.');
        }

        return $more;
    }

    /** @param list<string> $sharedStrings */
    private static function cellValue(string $type, ?string $value, array $sharedStrings): string
    {
        if ($value === null) {
            return '';
        }

        return match ($type) {
            's' => ctype_digit($value) && isset($sharedStrings[(int) $value])
                ? trim($sharedStrings[(int) $value])
                : throw new UnreadableSpreadsheet('That workbook is damaged and could not be read.'),
            'inlineStr', 'str', 'e' => trim($value),
            'b' => $value === '1' ? 'TRUE' : 'FALSE',
            default => self::number($value),
        };
    }

    /**
     * A numeric cell as a plain decimal string. Excel stores binary floats,
     * so 26 can arrive as "26.000000000000004" and 0.05 as "5E-2"; ten
     * decimal places is far beyond anything a payroll figure carries.
     */
    public static function number(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^-?\d+$/', $value) === 1 || !is_numeric($value)) {
            return $value;
        }

        $fixed = rtrim(rtrim(sprintf('%.10F', (float) $value), '0'), '.');

        return $fixed === '-0' ? '0' : $fixed;
    }

    private static function rowNumber(?string $attribute, int $fallback): int
    {
        return $attribute !== null && ctype_digit($attribute) && (int) $attribute > 0 ? (int) $attribute : $fallback;
    }

    /** "C7" => 3, "AB1" => 28; a missing or odd reference means the next column. */
    private static function columnIndex(?string $reference, int $fallback): int
    {
        if ($reference === null || preg_match('/^([A-Z]{1,3})\d*$/', strtoupper($reference), $m) !== 1) {
            return $fallback;
        }

        $index = 0;
        foreach (str_split($m[1]) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index;
    }

    /**
     * @param array<int,string> $cells 1-based column => value
     * @return list<string>
     */
    private static function denseRow(array $cells): array
    {
        if ($cells === []) {
            return [];
        }

        $dense = array_fill(0, max(array_keys($cells)), '');
        foreach ($cells as $column => $value) {
            $dense[$column - 1] = $value;
        }

        while ($dense !== [] && end($dense) === '') {
            array_pop($dense);
        }

        return array_values($dense);
    }
}
