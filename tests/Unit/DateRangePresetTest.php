<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\DateRangePreset;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Presets decide which rows the ledger shows and what its totals add up, so
 * an off-by-one month or quarter boundary is a wrong number on screen.
 */
final class DateRangePresetTest extends TestCase
{
    private function today(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date . ' 15:42:00');
    }

    public function testThisMonthCoversTheWholeCalendarMonth(): void
    {
        self::assertSame(
            ['preset' => 'this_month', 'from' => '2026-02-01', 'to' => '2026-02-28'],
            DateRangePreset::resolve('this_month', null, null, $this->today('2026-02-14'))
        );
    }

    public function testLastMonthCrossesTheYearBoundaryAndSkipsMonthEndOverflow(): void
    {
        self::assertSame(
            ['preset' => 'last_month', 'from' => '2025-12-01', 'to' => '2025-12-31'],
            DateRangePreset::resolve('last_month', null, null, $this->today('2026-01-10'))
        );
        // 31 March minus one month must be February, not "3 March".
        self::assertSame(
            ['preset' => 'last_month', 'from' => '2024-02-01', 'to' => '2024-02-29'],
            DateRangePreset::resolve('last_month', null, null, $this->today('2024-03-31'))
        );
    }

    public function testThisQuarterUsesCalendarQuarters(): void
    {
        self::assertSame(
            ['preset' => 'this_quarter', 'from' => '2026-07-01', 'to' => '2026-09-30'],
            DateRangePreset::resolve('this_quarter', null, null, $this->today('2026-09-27'))
        );
        self::assertSame(
            ['preset' => 'this_quarter', 'from' => '2026-10-01', 'to' => '2026-12-31'],
            DateRangePreset::resolve('this_quarter', null, null, $this->today('2026-10-01'))
        );
    }

    public function testYearToDateEndsToday(): void
    {
        self::assertSame(
            ['preset' => 'year_to_date', 'from' => '2026-01-01', 'to' => '2026-09-27'],
            DateRangePreset::resolve('year_to_date', null, null, $this->today('2026-09-27'))
        );
    }

    public function testRelativePresetWinsOverStaleCustomDates(): void
    {
        self::assertSame(
            ['preset' => 'this_month', 'from' => '2026-09-01', 'to' => '2026-09-30'],
            DateRangePreset::resolve('this_month', '2025-01-01', '2025-01-31', $this->today('2026-09-27'))
        );
    }

    public function testExplicitDatesWithoutPresetAreCustom(): void
    {
        self::assertSame(
            ['preset' => 'custom', 'from' => '2026-09-01', 'to' => null],
            DateRangePreset::resolve(null, '2026-09-01', null, $this->today('2026-09-27'))
        );
        self::assertSame(
            ['preset' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-10'],
            DateRangePreset::resolve('custom', '2026-09-01', '2026-09-10', $this->today('2026-09-27'))
        );
    }

    public function testAllTimeIgnoresDatesAndCustomWithoutDatesIsAllTime(): void
    {
        $allTime = ['preset' => 'all', 'from' => null, 'to' => null];

        self::assertSame($allTime, DateRangePreset::resolve('all', '2026-09-01', null, $this->today('2026-09-27')));
        self::assertSame($allTime, DateRangePreset::resolve('custom', null, null, $this->today('2026-09-27')));
        self::assertSame($allTime, DateRangePreset::resolve(null, null, null, $this->today('2026-09-27')));
    }

    public function testLabels(): void
    {
        self::assertSame('All time', DateRangePreset::label(null, null));
        self::assertSame('Sep 2026', DateRangePreset::label('2026-09-01', '2026-09-30'));
        self::assertSame('1 Sep – 27 Sep 2026', DateRangePreset::label('2026-09-01', '2026-09-27'));
        self::assertSame('1 Dec 2025 – 31 Jan 2026', DateRangePreset::label('2025-12-01', '2026-01-31'));
        self::assertSame('5 Sep 2026', DateRangePreset::label('2026-09-05', '2026-09-05'));
        self::assertSame('From 1 Sep 2026', DateRangePreset::label('2026-09-01', null));
        self::assertSame('Until 30 Sep 2026', DateRangePreset::label(null, '2026-09-30'));
        self::assertSame('last week – …', DateRangePreset::label('last week', null));
    }
}
