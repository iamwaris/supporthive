<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\SpreadsheetReader;
use App\Core\UnreadableSpreadsheet;
use App\Core\XlsxReader;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * XlsxReader and SpreadsheetReader against small workbooks and CSVs built
 * here, in the shapes Excel writes: shared strings, inline strings, numeric
 * EmpIDs, cached formula results, gaps — and the hostile ones: a DOCTYPE,
 * an oversized part, too many rows, a non-spreadsheet.
 */
final class XlsxReaderTest extends TestCase
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const PACKAGE_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        if (!XlsxReader::isAvailable()) {
            self::markTestSkipped('ZipArchive / XMLReader not available.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testReadsSharedStringsNumbersAndInlineStringsByRowNumber(): void
    {
        $path = $this->workbook(
            '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c>'
            . '<c r="C1" t="s"><v>2</v></c><c r="D1" t="s"><v>3</v></c></row>'
            . '<row r="2"><c r="A2"><v>7701</v></c><c r="B2"><v>45000</v></c><c r="C2"><v>1.5</v></c>'
            . '<c r="D2"><v>26.000000000000004</v></c></row>'
            . '<row r="4"><c r="A4" t="inlineStr"><is><t>EMP-7</t></is></c><c r="B4"><f>SUM(1,2)</f><v>3</v></c></row>',
            ['EMPID', 'Salary', 'Absent', 'WorkingDays']
        );

        $rows = XlsxReader::rows($path, 100);

        self::assertSame([1, 2, 4], array_keys($rows));
        self::assertSame(['EMPID', 'Salary', 'Absent', 'WorkingDays'], $rows[1]);
        self::assertSame(['7701', '45000', '1.5', '26'], $rows[2]);
        self::assertSame(['EMP-7', '3'], $rows[4]);
    }

    public function testFillsSkippedColumnsAndTrimsTrailingBlanks(): void
    {
        $path = $this->workbook(
            '<row r="1"><c r="A1"><v>1</v></c><c r="C1"><v>3</v></c><c r="E1" t="s"><v>0</v></c></row>',
            ['']
        );

        self::assertSame(['1', '', '3'], XlsxReader::rows($path, 10)[1]);
    }

    public function testRichTextSharedStringJoinsRunsAndSkipsPhonetic(): void
    {
        $shared = '<sst xmlns="' . self::NS . '"><si><r><t>Emp</t></r><r><t xml:space="preserve"> ID</t></r>'
            . '<rPh sb="0" eb="1"><t>IGNORED</t></rPh></si></sst>';
        $path = $this->workbook('<row r="1"><c r="A1" t="s"><v>0</v></c></row>', null, $shared);

        self::assertSame(['Emp ID'], XlsxReader::rows($path, 10)[1]);
    }

    public function testFollowsTheWorkbookToItsFirstSheet(): void
    {
        $path = $this->workbook('<row r="1"><c r="A1"><v>1</v></c></row>', [], null, 'worksheets/data.xml');

        self::assertSame(['1'], XlsxReader::rows($path, 10)[1]);
    }

    public function testRefusesADoctype(): void
    {
        $sheet = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "aaaa">]><worksheet xmlns="' . self::NS
            . '"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>&a;</t></is></c></row></sheetData></worksheet>';
        $path = $this->zip(['xl/worksheets/sheet1.xml' => $sheet]);

        $this->expectException(UnreadableSpreadsheet::class);
        XlsxReader::rows($path, 10);
    }

    /**
     * A UTF-16 part hides "<!DOCTYPE" from a byte search (every other byte
     * is NUL), so the parser-level refusal is what must stop it.
     */
    public function testRefusesADoctypeInAUtf16Part(): void
    {
        $sheet = '<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE x [<!ENTITY a "aaaa">]><worksheet xmlns="'
            . self::NS . '"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>&a;</t></is></c></row>'
            . '</sheetData></worksheet>';
        $utf16 = "\xFF\xFE" . mb_convert_encoding($sheet, 'UTF-16LE', 'UTF-8');
        self::assertStringNotContainsString('<!DOCTYPE', $utf16, 'the fixture must defeat the byte check');
        $path = $this->zip(['xl/worksheets/sheet1.xml' => $utf16]);

        $this->expectException(UnreadableSpreadsheet::class);
        $this->expectExceptionMessage('does not accept');
        XlsxReader::rows($path, 10);
    }

    public function testReadsAUtf16PartWithoutADoctype(): void
    {
        $sheet = '<?xml version="1.0" encoding="UTF-16"?><worksheet xmlns="' . self::NS . '"><sheetData>'
            . '<row r="1"><c r="A1" t="inlineStr"><is><t>Emp ID</t></is></c></row></sheetData></worksheet>';
        $utf16 = "\xFF\xFE" . mb_convert_encoding($sheet, 'UTF-16LE', 'UTF-8');
        $path = $this->zip(['xl/worksheets/sheet1.xml' => $utf16]);

        self::assertSame(['Emp ID'], XlsxReader::rows($path, 10)[1]);
    }

    public function testRefusesAPartLargerThanTheCapEvenWhenItCompressesWell(): void
    {
        $padding = str_repeat(' ', XlsxReader::MAX_ENTRY_BYTES + 10);
        $sheet = '<worksheet xmlns="' . self::NS . '"><sheetData>' . $padding . '</sheetData></worksheet>';
        $path = $this->zip(['xl/worksheets/sheet1.xml' => $sheet]);
        self::assertLessThan(1_000_000, (int) filesize($path), 'the fixture must be a small zip that inflates large');

        $this->expectException(UnreadableSpreadsheet::class);
        $this->expectExceptionMessage('too large');
        XlsxReader::rows($path, 10);
    }

    public function testRefusesMoreRowsThanAllowed(): void
    {
        $rows = '';
        for ($i = 1; $i <= 6; $i++) {
            $rows .= '<row r="' . $i . '"><c r="A' . $i . '"><v>' . $i . '</v></c></row>';
        }
        $path = $this->workbook($rows, []);

        $this->expectException(UnreadableSpreadsheet::class);
        $this->expectExceptionMessage('more than 5 rows');
        XlsxReader::rows($path, 5);
    }

    public function testRefusesASharedStringIndexThatDoesNotExist(): void
    {
        $path = $this->workbook('<row r="1"><c r="A1" t="s"><v>9</v></c></row>', ['only']);

        $this->expectException(UnreadableSpreadsheet::class);
        XlsxReader::rows($path, 10);
    }

    public function testRefusesBrokenXml(): void
    {
        $path = $this->zip(['xl/worksheets/sheet1.xml' => '<worksheet xmlns="' . self::NS . '"><sheetData><row>']);

        $this->expectException(UnreadableSpreadsheet::class);
        XlsxReader::rows($path, 10);
    }

    public function testRefusesAZipWithNoWorksheet(): void
    {
        $path = $this->zip(['hello.txt' => 'hi']);

        $this->expectException(UnreadableSpreadsheet::class);
        XlsxReader::rows($path, 10);
    }

    public function testNumberNormalisation(): void
    {
        self::assertSame('7701', XlsxReader::number('7701'));
        self::assertSame('0.05', XlsxReader::number('5E-2'));
        self::assertSame('45000.5', XlsxReader::number('45000.500000000001'));
        self::assertSame('abc', XlsxReader::number('abc'));
    }

    public function testSpreadsheetReaderPicksXlsxByContentNotName(): void
    {
        $path = $this->workbook('<row r="1"><c r="A1"><v>7</v></c></row>', []);
        $renamed = $path . '.csv';
        rename($path, $renamed);
        $this->files[] = $renamed;

        self::assertSame(['7'], SpreadsheetReader::read($renamed, 10)[1]);
    }

    public function testCsvWithBomCommasQuotesAndBlankLines(): void
    {
        $path = $this->file(
            "\xEF\xBB\xBFEmp ID,Salary,Absent,Working Days\r\n7701,\"45,000\",1,26\r\n\r\n7702,30000,,26\r\n"
        );

        $rows = SpreadsheetReader::read($path, 10);

        self::assertSame(['Emp ID', 'Salary', 'Absent', 'Working Days'], $rows[1]);
        self::assertSame(['7701', '45,000', '1', '26'], $rows[2]);
        self::assertSame([], $rows[3]);
        self::assertSame(['7702', '30000', '', '26'], $rows[4]);
    }

    public function testCsvSemicolonAndWindows1252(): void
    {
        $path = $this->file("EmpID;Salary;Absent;WorkingDays\nJos\xE9;1;0;26\n");

        $rows = SpreadsheetReader::read($path, 10);

        self::assertSame(['EmpID', 'Salary', 'Absent', 'WorkingDays'], $rows[1]);
        self::assertSame('José', $rows[2][0]);
    }

    public function testCsvRowCap(): void
    {
        $path = $this->file("a\nb\nc\n");

        $this->expectException(UnreadableSpreadsheet::class);
        SpreadsheetReader::read($path, 2);
    }

    public function testRejectsAFileThatIsNeitherXlsxNorText(): void
    {
        $path = $this->file("\x89PNG\r\n\x1a\n" . str_repeat("\0", 64));

        $this->expectException(UnreadableSpreadsheet::class);
        $this->expectExceptionMessage('.xlsx workbook or a .csv');
        SpreadsheetReader::read($path, 10);
    }

    /**
     * A minimal workbook: workbook.xml + rels pointing at the sheet, and
     * shared strings from $strings (or $sharedXml verbatim).
     *
     * @param list<string>|null $strings
     */
    private function workbook(
        string $rowsXml,
        ?array $strings,
        ?string $sharedXml = null,
        string $target = 'worksheets/sheet1.xml'
    ): string {
        $entries = [
            'xl/workbook.xml' => '<workbook xmlns="' . self::NS . '" xmlns:r="' . self::REL_NS . '">'
                . '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="' . self::PACKAGE_NS . '">'
                . '<Relationship Id="rId1" Type="worksheet" Target="' . $target . '"/></Relationships>',
            'xl/' . $target => '<worksheet xmlns="' . self::NS . '"><sheetData>' . $rowsXml
                . '</sheetData></worksheet>',
        ];

        if ($sharedXml !== null) {
            $entries['xl/sharedStrings.xml'] = $sharedXml;
        } elseif ($strings !== null && $strings !== []) {
            $entries['xl/sharedStrings.xml'] = '<sst xmlns="' . self::NS . '">' . implode('', array_map(
                static fn (string $s): string => '<si><t>' . htmlspecialchars($s, ENT_XML1) . '</t></si>',
                $strings
            )) . '</sst>';
        }

        return $this->zip($entries);
    }

    /** @param array<string,string> $entries */
    private function zip(array $entries): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'xlsx');
        $this->files[] = $path;
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }

    private function file(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'csv');
        $this->files[] = $path;
        file_put_contents($path, $contents);

        return $path;
    }
}
