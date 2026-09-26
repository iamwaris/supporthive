<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;

/**
 * The ledger's date-range shortcuts ("This month", "Year to date"...) and the
 * human label for whatever range is active.
 *
 * Presets are resolved on the server, against a caller-supplied "today", so a
 * shared `?range=this_month` link always means the month it is opened in and
 * the arithmetic is unit-testable without a clock. Explicit `from`/`to` query
 * params — the format every existing bookmark uses — are the Custom preset.
 */
final class DateRangePreset
{
    public const ALL_TIME = 'all';
    public const THIS_MONTH = 'this_month';
    public const LAST_MONTH = 'last_month';
    public const THIS_QUARTER = 'this_quarter';
    public const YEAR_TO_DATE = 'year_to_date';
    public const CUSTOM = 'custom';

    /** @return array<string,string> preset value => label, in display order */
    public static function options(): array
    {
        return [
            self::ALL_TIME => 'All time',
            self::THIS_MONTH => 'This month',
            self::LAST_MONTH => 'Last month',
            self::THIS_QUARTER => 'This quarter',
            self::YEAR_TO_DATE => 'Year to date',
            self::CUSTOM => 'Custom',
        ];
    }

    /** Presets that resolve to fixed dates — i.e. not "All time" and not "Custom". */
    public static function isRelative(string $preset): bool
    {
        return in_array($preset, [self::THIS_MONTH, self::LAST_MONTH, self::THIS_QUARTER, self::YEAR_TO_DATE], true);
    }

    /**
     * Decides which preset a request means and the from/to it filters on.
     *
     * A relative preset wins over any from/to also submitted: without JS the
     * custom date fields are always in the form, so stale values from an
     * earlier custom range would otherwise override the preset the user just
     * picked. No preset (an old bookmark) with dates means Custom.
     *
     * @return array{preset:string, from:?string, to:?string}
     */
    public static function resolve(?string $preset, ?string $from, ?string $to, DateTimeImmutable $today): array
    {
        $today = $today->setTime(0, 0);

        if ($preset !== null && self::isRelative($preset)) {
            [$start, $end] = self::bounds($preset, $today);

            return ['preset' => $preset, 'from' => $start->format('Y-m-d'), 'to' => $end->format('Y-m-d')];
        }

        if ($preset === self::ALL_TIME) {
            return ['preset' => self::ALL_TIME, 'from' => null, 'to' => null];
        }

        if ($from === null && $to === null) {
            return ['preset' => self::ALL_TIME, 'from' => null, 'to' => null];
        }

        return ['preset' => self::CUSTOM, 'from' => $from, 'to' => $to];
    }

    /**
     * "All time", "Sep 2026" for a whole calendar month, otherwise a range
     * such as "1 Sep – 27 Sep 2026" (the year is repeated only when it differs).
     */
    public static function label(?string $from, ?string $to): string
    {
        if ($from === null && $to === null) {
            return 'All time';
        }

        $start = self::parse($from);
        $end = self::parse($to);

        if (($from !== null && $start === null) || ($to !== null && $end === null)) {
            // Unparseable input still filters (LedgerQuery is lenient), so echo it rather than hide it.
            return ($from ?? '…') . ' – ' . ($to ?? '…');
        }
        if ($start === null) {
            return $end === null ? 'All time' : 'Until ' . $end->format('j M Y');
        }
        if ($end === null) {
            return 'From ' . $start->format('j M Y');
        }

        if (
            $start->format('Y-m') === $end->format('Y-m')
            && $start->format('j') === '1'
            && $end->format('j') === $end->format('t')
        ) {
            return $start->format('M Y');
        }
        if ($start == $end) {
            return $start->format('j M Y');
        }
        if ($start->format('Y') === $end->format('Y')) {
            return $start->format('j M') . ' – ' . $end->format('j M Y');
        }

        return $start->format('j M Y') . ' – ' . $end->format('j M Y');
    }

    /** @return array{0:DateTimeImmutable,1:DateTimeImmutable} */
    private static function bounds(string $preset, DateTimeImmutable $today): array
    {
        $monthStart = $today->modify('first day of this month');

        return match ($preset) {
            self::THIS_MONTH => [$monthStart, $today->modify('last day of this month')],
            self::LAST_MONTH => [
                $monthStart->modify('-1 month'),
                $monthStart->modify('-1 day'),
            ],
            self::THIS_QUARTER => self::quarter($today),
            default => [$today->setDate((int) $today->format('Y'), 1, 1), $today],
        };
    }

    /** @return array{0:DateTimeImmutable,1:DateTimeImmutable} */
    private static function quarter(DateTimeImmutable $today): array
    {
        $firstMonth = intdiv((int) $today->format('n') - 1, 3) * 3 + 1;
        $start = $today->setDate((int) $today->format('Y'), $firstMonth, 1);

        return [$start, $start->modify('+2 months')->modify('last day of this month')];
    }

    private static function parse(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }
}
