<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\DashboardPeriod;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The period decides which rows every dashboard total adds up, so a wrong
 * bound or a value that slips past the allow-list is a wrong number on screen.
 */
final class DashboardPeriodTest extends TestCase
{
    private function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-23 15:42:00');
    }

    public function testAllTimeHasNoLowerBoundAndEndsWithTheCurrentMonth(): void
    {
        $period = DashboardPeriod::fromQuery(['period' => 'all'], $this->today());

        self::assertTrue($period->allTime);
        self::assertNull($period->from);
        self::assertSame('2026-10-31', $period->to, 'same upper bound as the default month filter');
        self::assertSame('All time', $period->label());
        self::assertSame('period=all', $period->queryString());
    }

    public function testAllTimeIgnoresAnyMonthAlsoSubmitted(): void
    {
        $period = DashboardPeriod::fromQuery(['period' => 'all', 'month' => '2025-02'], $this->today());

        self::assertTrue($period->allTime);
        self::assertNull($period->from);
        self::assertSame('2026-10-31', $period->to);
    }

    public function testNoParamsMeansTheCurrentMonth(): void
    {
        $period = DashboardPeriod::fromQuery([], $this->today());

        self::assertFalse($period->allTime);
        self::assertSame(['2026-10-01', '2026-10-31'], [$period->from, $period->to]);
        self::assertSame('October 2026', $period->label());
        self::assertSame('month=2026-10', $period->queryString());
    }

    public function testASelectedMonthIsUsedAsItsCalendarBounds(): void
    {
        $period = DashboardPeriod::fromQuery(['period' => 'month', 'month' => '2024-02'], $this->today());

        self::assertFalse($period->allTime);
        self::assertSame(['2024-02-01', '2024-02-29'], [$period->from, $period->to]);
        self::assertSame('2024-02', $period->monthValue());
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function invalidPeriodProvider(): array
    {
        return [
            'unknown value' => [['period' => 'forever']],
            'case variant' => [['period' => 'ALL']],
            'array input' => [['period' => ['all']]],
            'injection attempt' => [['period' => "all' OR 1=1"]],
        ];
    }

    /** @param array<string,mixed> $query */
    #[DataProvider('invalidPeriodProvider')]
    public function testAnInvalidPeriodFallsBackToTheCurrentMonth(array $query): void
    {
        $period = DashboardPeriod::fromQuery($query, $this->today());

        self::assertFalse($period->allTime);
        self::assertSame(['2026-10-01', '2026-10-31'], [$period->from, $period->to]);
    }

    public function testAnInvalidMonthFallsBackToTheCurrentMonth(): void
    {
        foreach (['2026-13', 'garbage', '1999-05'] as $month) {
            $period = DashboardPeriod::fromQuery(['month' => $month], $this->today());

            self::assertFalse($period->allTime, $month);
            self::assertSame(['2026-10-01', '2026-10-31'], [$period->from, $period->to], $month);
        }
    }
}
