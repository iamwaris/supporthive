<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\View;
use PHPUnit\Framework\TestCase;

/**
 * The shared PDF report template (owner's request: standard thousands-
 * separated numbers, branding repeated on every page, page numbers).
 *
 * Money cells reach this template as DECIMAL(15,2) strings — always exactly
 * two decimal digits, per CLAUDE.md's "money is never a float" — so that
 * shape is what the formatter keys on, without a per-report list of which
 * columns are money. This asserts that heuristic is neither too broad (a
 * date or an id) nor too narrow (money still formats when negative, or when
 * whole).
 */
final class PdfReportViewTest extends TestCase
{
    private function render(array $rows): string
    {
        return View::capture('pdf/report', [
            'title' => 'Test Report',
            'subtitle' => 'A period',
            'header' => ['Date', 'Description', 'Amount'],
            'rows' => $rows,
            'companyName' => 'Acme Co',
        ], null);
    }

    public function testLargeMoneyValuesGetThousandsSeparators(): void
    {
        $html = $this->render([['2026-09-01', 'Big expense', '1800000.00']]);

        self::assertStringContainsString('1,800,000.00', $html);
        self::assertStringNotContainsString('>1800000.00<', $html);
    }

    public function testNegativeMoneyValuesAreFormattedWithTheSignPreserved(): void
    {
        $html = $this->render([['2026-09-01', 'Refund', '-61000.00']]);

        self::assertStringContainsString('-61,000.00', $html);
    }

    public function testSmallMoneyValuesUnderOneThousandAreUnaffected(): void
    {
        $html = $this->render([['2026-09-01', 'Small expense', '654.45']]);

        self::assertStringContainsString('654.45', $html);
    }

    public function testDatesAndPlainTextAreNeverReformatted(): void
    {
        $html = $this->render([['2026-09-24', 'Fb ads cost, #1234', '661.19']]);

        self::assertStringContainsString('2026-09-24', $html);
        self::assertStringContainsString('Fb ads cost, #1234', $html);
    }

    public function testAnIntegerWithoutTwoDecimalPlacesIsLeftAlone(): void
    {
        // Deliberately not the DECIMAL(15,2) shape — e.g. a bare count or id
        // that happens to share a column with money in some export shapes.
        $html = $this->render([['2026-09-01', 'Count-like cell', '1800000']]);

        self::assertStringContainsString('>1800000<', $html);
    }

    public function testCompanyBrandingAppearsInBothTheHeaderAndTheRepeatingFooter(): void
    {
        $html = $this->render([['2026-09-01', 'Row', '10.00']]);

        self::assertStringContainsString('<h1>Acme Co', $html);
        // The footer is what repeats on every page in dompdf (position:
        // fixed) — the header does not. Branding on page 2+ of a long
        // report depends on this element, not the <h1>.
        self::assertMatchesRegularExpression('/<footer>[^<]*Acme Co/', $html);
    }

    public function testEmptyReportShowsNoDataMessageInsteadOfAnEmptyTable(): void
    {
        $html = $this->render([]);

        self::assertStringContainsString('No data for this filter.', $html);
    }
}
