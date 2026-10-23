<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Validator;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The dashboard's period filter: one calendar month, or "All time".
 *
 * Resolved against a caller-supplied "today" so the bounds are unit-testable
 * without a clock. Anything that fails validation falls back to the current
 * month rather than erroring, the same as every other GET filter here.
 *
 * "All time" has no lower bound but keeps the upper bound the month filter
 * already uses by default (the end of the current month), so the two views
 * agree on what counts as "recorded so far".
 */
final readonly class DashboardPeriod
{
    public const MONTH = 'month';
    public const ALL_TIME = 'all';

    private function __construct(
        public bool $allTime,
        public int $year,
        public int $month,
        public ?string $from,
        public string $to,
    ) {
    }

    /** @param array<string,mixed> $query usually $_GET */
    public static function fromQuery(array $query, DateTimeImmutable $today): self
    {
        $input = Validator::make($query, [
            'period' => 'nullable|string|in:' . self::MONTH . ',' . self::ALL_TIME,
            'month' => 'nullable|string|regex:/^\d{4}-\d{2}$/',
        ])->validated();

        $currentYear = (int) $today->format('Y');
        $currentMonth = (int) $today->format('n');

        if (($input['period'] ?? null) === self::ALL_TIME) {
            return new self(true, $currentYear, $currentMonth, null, $today->format('Y-m-t'));
        }

        [$year, $month] = [$currentYear, $currentMonth];
        $monthValue = $input['month'] ?? null;
        if (is_string($monthValue)) {
            try {
                [$year, $month] = DashboardService::parseMonth($monthValue);
            } catch (InvalidArgumentException) {
                // Out-of-range month: keep the current one, as for any bad filter value.
            }
        }

        [$from, $to] = BudgetService::periodBounds($year, $month);

        return new self(false, $year, $month, $from, $to);
    }

    /** "All time" or e.g. "October 2026" — used for headings and tile captions. */
    public function label(): string
    {
        return $this->allTime ? 'All time' : date('F Y', (int) strtotime($this->monthValue() . '-01'));
    }

    /** YYYY-MM for the month input; the current month when showing all time. */
    public function monthValue(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }

    /** Query string the chart endpoints need to reproduce this period. */
    public function queryString(): string
    {
        return $this->allTime
            ? http_build_query(['period' => self::ALL_TIME])
            : http_build_query(['month' => $this->monthValue()]);
    }
}
