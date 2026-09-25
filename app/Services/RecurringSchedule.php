<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Turns a recurrence rule (frequency + anchor day) into the concrete calendar
 * dates a scheduler still owes. Pure function: no database, no `Auth`, no
 * clock of its own — every notion of "now" and "already generated" is a
 * parameter, so the highest-risk logic in the recurring-transactions feature
 * can be exhaustively unit-tested without fixtures.
 *
 * Two things must never be conflated, because conflating them silently turns
 * "every January 15" into "every September 15" for a rule created in
 * September:
 *
 * - Phase: which day-of-month/weekday, and for the monthly-family
 *   frequencies which reference month, comes from $startDate alone, always.
 * - Search floor: where generation is allowed to start looking comes from
 *   $effectiveFrom — the caller's GREATEST(start_date, DATE(created_at)),
 *   i.e. the "no backfill on creation" rule.
 *
 * Month-end clamping (day 31 on a 30-day or 28/29-day month) is recomputed
 * independently at every step from the untouched anchor day; it is never
 * "shifted once and remembered", which is why day 31 correctly reappears in
 * May after being clamped to 30 in April.
 */
final class RecurringSchedule
{
    private const FREQUENCIES = ['daily', 'weekly', 'monthly', 'quarterly', 'yearly'];

    /**
     * Belt-and-braces termination for the stepping loops below. Every real
     * call is already bounded tightly by $asOf/$endDate; this only guards
     * against a misconfigured or corrupt input turning into an effectively
     * unbounded scan.
     */
    private const MAX_ITERATIONS = 100_000;

    /**
     * @param string  $frequency      'daily'|'weekly'|'monthly'|'quarterly'|'yearly'
     * @param int|null $dayOfMonth    1-31, required for monthly/quarterly/yearly
     * @param int|null $dayOfWeek     0=Sunday..6=Saturday, required for weekly
     * @param string  $startDate      Y-m-d — defines the schedule's phase, always
     * @param string  $effectiveFrom  Y-m-d — the no-backfill floor
     * @param string|null $endDate    Y-m-d or null
     * @param string|null $lastGenerated Y-m-d or null — cursor; only dates strictly after this are returned
     * @param string  $asOf           Y-m-d — the job run date; never generate beyond this
     * @return list<string> occurrence dates (Y-m-d), ascending
     */
    public static function occurrencesDue(
        string $frequency,
        ?int $dayOfMonth,
        ?int $dayOfWeek,
        string $startDate,
        string $effectiveFrom,
        ?string $endDate,
        ?string $lastGenerated,
        string $asOf
    ): array {
        self::validate($frequency, $dayOfMonth, $dayOfWeek);

        $start = self::parseDate($startDate);
        $floor = self::maxDate($start, self::parseDate($effectiveFrom));

        if ($lastGenerated !== null) {
            $floor = self::maxDate($floor, self::parseDate($lastGenerated)->modify('+1 day'));
        }

        $ceiling = self::parseDate($asOf);
        if ($endDate !== null) {
            $ceiling = self::minDate($ceiling, self::parseDate($endDate));
        }

        if ($floor > $ceiling) {
            return [];
        }

        return match ($frequency) {
            'daily' => self::daily($floor, $ceiling),
            'weekly' => self::weekly((int) $dayOfWeek, $floor, $ceiling),
            'monthly' => self::monthlyFamily($start, (int) $dayOfMonth, 1, $floor, $ceiling),
            'quarterly' => self::monthlyFamily($start, (int) $dayOfMonth, 3, $floor, $ceiling),
            'yearly' => self::monthlyFamily($start, (int) $dayOfMonth, 12, $floor, $ceiling),
            // Unreachable: validate() already rejected anything else. Kept so
            // this stays safe even if that call above is ever refactored away.
            default => throw new InvalidArgumentException(sprintf('Unknown recurrence frequency "%s".', $frequency)),
        };
    }

    private static function validate(string $frequency, ?int $dayOfMonth, ?int $dayOfWeek): void
    {
        if (!in_array($frequency, self::FREQUENCIES, true)) {
            throw new InvalidArgumentException(sprintf('Unknown recurrence frequency "%s".', $frequency));
        }

        if ($frequency === 'daily') {
            return;
        }

        if ($frequency === 'weekly') {
            if ($dayOfWeek === null || $dayOfWeek < 0 || $dayOfWeek > 6) {
                throw new InvalidArgumentException('weekly frequency requires dayOfWeek in 0..6.');
            }

            return;
        }

        if ($dayOfMonth === null || $dayOfMonth < 1 || $dayOfMonth > 31) {
            throw new InvalidArgumentException(sprintf('%s frequency requires dayOfMonth in 1..31.', $frequency));
        }
    }

    /** @return list<string> */
    private static function daily(DateTimeImmutable $floor, DateTimeImmutable $ceiling): array
    {
        $dates = [];
        $cursor = $floor;
        $iterations = 0;

        while ($cursor <= $ceiling) {
            if (++$iterations > self::MAX_ITERATIONS) {
                break;
            }

            $dates[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }

        return $dates;
    }

    /** @return list<string> */
    private static function weekly(int $dayOfWeek, DateTimeImmutable $floor, DateTimeImmutable $ceiling): array
    {
        // First date on/after the floor whose weekday matches, then +7 from there.
        $offset = ($dayOfWeek - (int) $floor->format('w') + 7) % 7;
        $cursor = $floor->modify(sprintf('+%d days', $offset));

        $dates = [];
        $iterations = 0;

        while ($cursor <= $ceiling) {
            if (++$iterations > self::MAX_ITERATIONS) {
                break;
            }

            $dates[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+7 days');
        }

        return $dates;
    }

    /**
     * One unified path for monthly/quarterly/yearly: step $stepMonths months
     * at a time from $anchor's month, clamping the day into each candidate
     * month independently and fresh every time — never carrying a downgraded
     * day forward to the next step.
     *
     * @return list<string>
     */
    private static function monthlyFamily(
        DateTimeImmutable $anchor,
        int $dayOfMonth,
        int $stepMonths,
        DateTimeImmutable $floor,
        DateTimeImmutable $ceiling
    ): array {
        $referenceIndex = ((int) $anchor->format('Y')) * 12 + ((int) $anchor->format('n') - 1);

        $dates = [];
        $iterations = 0;
        $step = 0;

        while (true) {
            if (++$iterations > self::MAX_ITERATIONS) {
                break;
            }

            $monthIndex = $referenceIndex + $step * $stepMonths;
            $year = intdiv($monthIndex, 12);
            $month = ($monthIndex % 12) + 1;
            $day = min($dayOfMonth, self::daysInMonth($year, $month));

            $candidate = self::parseDate(sprintf('%04d-%02d-%02d', $year, $month, $day));

            if ($candidate > $ceiling) {
                break;
            }

            if ($candidate >= $floor) {
                $dates[] = $candidate->format('Y-m-d');
            }

            $step++;
        }

        return $dates;
    }

    private static function daysInMonth(int $year, int $month): int
    {
        static $daysByMonth = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

        if ($month === 2 && self::isLeapYear($year)) {
            return 29;
        }

        return $daysByMonth[$month - 1];
    }

    private static function isLeapYear(int $year): bool
    {
        return ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;
    }

    private static function parseDate(string $value): DateTimeImmutable
    {
        if (
            preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) !== 1
            || !checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])
        ) {
            throw new InvalidArgumentException(sprintf('Invalid date "%s"; expected Y-m-d.', $value));
        }

        // '!' resets every field the format doesn't touch (notably time) to
        // the Unix epoch's, so every parsed date lands on midnight and plain
        // comparison operators compare calendar days, not wall-clock time.
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false) {
            throw new InvalidArgumentException(sprintf('Invalid date "%s"; expected Y-m-d.', $value));
        }

        return $date;
    }

    private static function maxDate(DateTimeImmutable $a, DateTimeImmutable $b): DateTimeImmutable
    {
        return $a > $b ? $a : $b;
    }

    private static function minDate(DateTimeImmutable $a, DateTimeImmutable $b): DateTimeImmutable
    {
        return $a < $b ? $a : $b;
    }
}
