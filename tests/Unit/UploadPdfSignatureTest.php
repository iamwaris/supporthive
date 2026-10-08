<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Upload;
use PHPUnit\Framework\TestCase;

/**
 * Upload::hasPdfSignature() is the check that a file the MIME sniffer calls
 * a PDF actually starts like one, at offset 0, where a reader looks.
 */
final class UploadPdfSignatureTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testARealPdfHeaderPasses(): void
    {
        self::assertTrue(Upload::hasPdfSignature($this->fileWith("%PDF-1.7\n%\xE2\xE3\xCF\xD3\n")));
    }

    public function testAHeaderThatIsNotAtOffsetZeroFails(): void
    {
        self::assertFalse(Upload::hasPdfSignature($this->fileWith("<?php echo 1; ?>\n%PDF-1.7\n")));
    }

    public function testATruncatedOrEmptyFileFails(): void
    {
        self::assertFalse(Upload::hasPdfSignature($this->fileWith('%PDF')));
        self::assertFalse(Upload::hasPdfSignature($this->fileWith('')));
    }

    public function testAMissingFileFails(): void
    {
        self::assertFalse(Upload::hasPdfSignature(sys_get_temp_dir() . '/does-not-exist-' . bin2hex(random_bytes(4))));
    }

    private function fileWith(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'pdfsig');
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }
}
