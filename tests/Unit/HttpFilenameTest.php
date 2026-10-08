<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Http;
use PHPUnit\Framework\TestCase;

/**
 * Http::dispositionFilename() puts an uploader-chosen name inside a
 * Content-Disposition header. A CR/LF there is header injection, a quote
 * breaks out of the filename value, and a path separator is at best
 * confusing — every one of those must be gone from the result.
 */
final class HttpFilenameTest extends TestCase
{
    public function testAPlainNameIsKept(): void
    {
        self::assertSame('Leave Policy 2026.pdf', Http::dispositionFilename('Leave Policy 2026.pdf'));
    }

    public function testHeaderInjectionAndQuotesAreRemoved(): void
    {
        $name = Http::dispositionFilename("evil\r\nSet-Cookie: x=1\".pdf");

        self::assertStringNotContainsString("\r", $name);
        self::assertStringNotContainsString("\n", $name);
        self::assertStringNotContainsString('"', $name);
        self::assertStringNotContainsString(':', $name);
        self::assertStringEndsWith('.pdf', $name);
    }

    public function testPathSeparatorsCannotSurvive(): void
    {
        $name = Http::dispositionFilename('..\\..\\windows/system32/evil.pdf');

        self::assertStringNotContainsString('/', $name);
        self::assertStringNotContainsString('\\', $name);
        self::assertStringNotContainsString('..', $name);
    }

    public function testDisallowedCharactersBecomeASingleDash(): void
    {
        self::assertSame('Pay-slip-Oct.pdf', Http::dispositionFilename('Pay*slip<>Oct.pdf'));
    }

    public function testTheExtensionIsAlwaysPresentAndNeverDoubled(): void
    {
        self::assertSame('Handbook.pdf', Http::dispositionFilename('Handbook'));
        self::assertSame('Handbook.pdf', Http::dispositionFilename('Handbook.PDF'));
        self::assertSame('receipt.jpg', Http::dispositionFilename('receipt.jpg', 'jpg'));
    }

    public function testAnEmptyOrAllInvalidNameFallsBack(): void
    {
        self::assertSame('document.pdf', Http::dispositionFilename(''));
        self::assertSame('document.pdf', Http::dispositionFilename('.pdf'));
        self::assertSame('document.pdf', Http::dispositionFilename("\"\r\n"));
        self::assertSame('document.pdf', Http::dispositionFilename('***'));
    }

    public function testTheResultIsCappedAt150Characters(): void
    {
        $name = Http::dispositionFilename(str_repeat('a', 400) . '.pdf');

        self::assertSame(150, strlen($name));
        self::assertStringEndsWith('.pdf', $name);
    }
}
