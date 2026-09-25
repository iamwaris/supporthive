<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Logger::prune() — added alongside the M7-6 security checklist finding
 * that storage/logs grows forever (each day gets its own file, but nothing
 * ever deleted an old one). Uses distinctly-dated filenames so this can
 * never touch a real log from an actual day the app ran.
 */
final class LoggerTest extends TestCase
{
    private string $dir;
    private string $oldFile;
    private string $freshFile;

    protected function setUp(): void
    {
        $this->dir = STORAGE_PATH . '/logs';
        // A date nothing in this app's history or tests could ever produce
        // for real, so pruning it can never be confused with a real log.
        $this->oldFile = $this->dir . '/app-2000-01-01.log';
        $this->freshFile = $this->dir . '/security-2000-01-02.log';

        file_put_contents($this->oldFile, "test\n");
        touch($this->oldFile, time() - (40 * 86400));

        file_put_contents($this->freshFile, "test\n");
        touch($this->freshFile, time() - (5 * 86400));
    }

    protected function tearDown(): void
    {
        @unlink($this->oldFile);
        @unlink($this->freshFile);
    }

    public function testPruneDeletesOnlyFilesOlderThanTheRetentionWindow(): void
    {
        $deleted = Logger::prune(30);

        self::assertFalse(is_file($this->oldFile), 'a 40-day-old log must be pruned at a 30-day retention');
        self::assertTrue(is_file($this->freshFile), 'a 5-day-old log must survive a 30-day retention');
        self::assertGreaterThanOrEqual(1, $deleted);
    }

    public function testPruneReturnsZeroWhenNothingIsOldEnough(): void
    {
        // Both fixtures are newer than a 100-day retention.
        $deleted = Logger::prune(100);

        self::assertSame(0, $deleted);
        self::assertTrue(is_file($this->oldFile));
        self::assertTrue(is_file($this->freshFile));
    }
}
