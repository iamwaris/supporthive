<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\RecurringSchedule;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * RecurringSchedule is the highest-risk logic in the recurring-transactions
 * feature: a subtle bug here silently produces wrong financial entries, not
 * a visible error. It is a pure function, so every case below is exercised
 * with no database.
 *
 * The case that matters most is guarding against conflating two different
 * "reference dates": the schedule's PHASE (which day/weekday/month) must
 * always come from $startDate, never from $effectiveFrom — see
 * testYearlyAnchorsOnStartDateMonthNotEffectiveFromMonth().
 */
final class RecurringScheduleTest extends TestCase
{
    public function testDailyProducesOnePerDay(): void
    {
        $dates = RecurringSchedule::occurrencesDue(
            'daily',
            null,
            null,
            '2026-03-01',
            '2026-03-01',
            null,
            null,
            '2026-03-05'
        );

        self::assertSame(
            ['2026-03-01', '2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05'],
            $dates
        );
    }

    public function testWeeklyAnchorsOnChosenWeekdayAndStepsBySeven(): void
    {
        // 2026-03-02 is a Monday; dayOfWeek 3 = Wednesday.
        $dates = RecurringSchedule::occurrencesDue(
            'weekly',
            null,
            3,
            '2026-03-02',
            '2026-03-02',
            null,
            null,
            '2026-03-25'
        );

        self::assertSame(
            ['2026-03-04', '2026-03-11', '2026-03-18', '2026-03-25'],
            $dates
        );
        foreach ($dates as $date) {
            self::assertSame('3', date('w', strtotime($date)), $date . ' must be a Wednesday');
        }
    }

    public function testMonthlyDay31ClampsToFeb28InNonLeapYear(): void
    {
        $dates = RecurringSchedule::occurrencesDue(
            'monthly',
            31,
            null,
            '2026-01-31',
            '2026-01-31',
            null,
            null,
            '2026-02-28'
        );

        self::assertSame(['2026-01-31', '2026-02-28'], $dates);
    }

    public function testMonthlyDay31ClampsToFeb29InLeapYear(): void
    {
        $dates = RecurringSchedule::occurrencesDue(
            'monthly',
            31,
            null,
            '2028-01-31',
            '2028-01-31',
            null,
            null,
            '2028-02-29'
        );

        self::assertSame(['2028-01-31', '2028-02-29'], $dates);
    }

    public function testMonthlyDay31ReturnsToThirtyOneInMayAfterBeingClampedInApril(): void
    {
        // Proves the clamp is recomputed fresh per month, never "shifted once
        // and remembered": Apr 30 must not permanently downgrade the anchor.
        $dates = RecurringSchedule::occurrencesDue(
            'monthly',
            31,
            null,
            '2026-01-31',
            '2026-01-31',
            null,
            null,
            '2026-05-31'
        );

        self::assertSame(
            ['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31'],
            $dates
        );
    }

    public function testQuarterlyStepsThreeMonthsFromTheRulesAnchorMonth(): void
    {
        $dates = RecurringSchedule::occurrencesDue(
            'quarterly',
            15,
            null,
            '2026-02-15',
            '2026-02-15',
            null,
            null,
            '2026-11-30'
        );

        self::assertSame(
            ['2026-02-15', '2026-05-15', '2026-08-15', '2026-11-15'],
            $dates
        );
    }

    public function testYearlyAnchorsOnStartDateMonthNotEffectiveFromMonth(): void
    {
        // A rule with startDate January but created (and thus effectiveFrom)
        // in September must still fire every January — not every September.
        // Using effectiveFrom as the phase reference would silently produce
        // '2026-09-15' or '2027-09-15' here instead.
        $dates = RecurringSchedule::occurrencesDue(
            'yearly',
            15,
            null,
            '2026-01-15',
            '2026-09-10',
            null,
            null,
            '2027-06-01'
        );

        self::assertSame(['2027-01-15'], $dates);
    }

    public function testThreeMonthGapReturnsThreeOccurrencesEachWithItsOwnDate(): void
    {
        $dates = RecurringSchedule::occurrencesDue(
            'monthly',
            1,
            null,
            '2026-01-01',
            '2026-01-01',
            null,
            '2026-09-01',
            '2026-12-25'
        );

        self::assertSame(['2026-10-01', '2026-11-01', '2026-12-01'], $dates);
    }

    public function testCallingTwiceWithSameCursorAndAsOfIsIdempotent(): void
    {
        $firstRun = RecurringSchedule::occurrencesDue(
            'monthly',
            1,
            null,
            '2026-01-01',
            '2026-01-01',
            null,
            null,
            '2026-03-15'
        );
        self::assertSame(['2026-01-01', '2026-02-01', '2026-03-01'], $firstRun);

        $lastGenerated = $firstRun[count($firstRun) - 1];

        $secondRun = RecurringSchedule::occurrencesDue(
            'monthly',
            1,
            null,
            '2026-01-01',
            '2026-01-01',
            null,
            $lastGenerated,
            '2026-03-15'
        );

        self::assertSame([], $secondRun);
    }

    public function testPastStartDateGeneratesOnlyFromEffectiveFromForwardNoBackfill(): void
    {
        $dates = RecurringSchedule::occurrencesDue(
            'monthly',
            1,
            null,
            '2020-01-01',
            '2026-09-01',
            null,
            null,
            '2026-09-25'
        );

        self::assertSame(['2026-09-01'], $dates);
    }

    public function testEndDateStopsGenerationOncePassed(): void
    {
        $dates = RecurringSchedule::occurrencesDue(
            'monthly',
            1,
            null,
            '2026-01-01',
            '2026-01-01',
            '2026-03-01',
            null,
            '2026-12-31'
        );

        self::assertSame(['2026-01-01', '2026-02-01', '2026-03-01'], $dates);
    }

    public function testNothingIsDueBeforeEffectiveFrom(): void
    {
        $dates = RecurringSchedule::occurrencesDue(
            'daily',
            null,
            null,
            '2026-01-01',
            '2026-06-01',
            null,
            null,
            '2026-05-31'
        );

        self::assertSame([], $dates);
    }

    public function testWeeklyWithNullDayOfWeekThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RecurringSchedule::occurrencesDue(
            'weekly',
            null,
            null,
            '2026-01-01',
            '2026-01-01',
            null,
            null,
            '2026-01-31'
        );
    }

    public function testUnknownFrequencyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RecurringSchedule::occurrencesDue(
            'fortnightly',
            1,
            null,
            '2026-01-01',
            '2026-01-01',
            null,
            null,
            '2026-01-31'
        );
    }

    public function testMonthlyWithNullDayOfMonthThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RecurringSchedule::occurrencesDue(
            'monthly',
            null,
            null,
            '2026-01-01',
            '2026-01-01',
            null,
            null,
            '2026-01-31'
        );
    }

    public function testInvalidCalendarDateThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RecurringSchedule::occurrencesDue(
            'daily',
            null,
            null,
            '2026-02-30',
            '2026-02-30',
            null,
            null,
            '2026-03-01'
        );
    }
}
