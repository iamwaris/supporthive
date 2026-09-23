<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use InvalidArgumentException;
use RuntimeException;

/**
 * Partner ownership shares.
 *
 * Two things make this the most consequential class in the application: it
 * decides who gets paid, and its "must total exactly 100%" rule is a V1
 * acceptance criterion.
 *
 * Percentages are handled as integer BASIS POINTS OF A PERCENT throughout —
 * 40.0000% is 400000, and 100% is exactly 1000000. This is not fussiness:
 * 33.33 + 33.33 + 33.34 in floating point does not reliably equal 100, so a
 * float-based check either rejects a valid split or accepts an invalid one.
 * Integers make the comparison exact.
 *
 * Shares are effective-dated. A split is the set of rows whose period covers a
 * date, so "what was the split in March?" has a real answer and a change today
 * cannot rewrite the basis of a payout made last quarter.
 */
final class ShareService
{
    /** 100.0000% expressed in basis points of a percent. */
    public const TOTAL_BP = 1000000;

    /** One percent. */
    public const ONE_PERCENT_BP = 10000;

    /**
     * Parse a human percentage into basis points, exactly.
     *
     * Accepts "40", "40.5", "33.3333". Rejects anything else rather than
     * guessing — a mistyped share is not something to be lenient about.
     */
    public static function toBasisPoints(string $percent): int
    {
        $percent = trim($percent);

        if (preg_match('/^(\d{1,3})(?:\.(\d{1,4}))?$/', $percent, $matches) !== 1) {
            throw new InvalidArgumentException("Not a valid percentage: {$percent}");
        }

        // String padding rather than multiplication: "40.5" becomes 40 and
        // "5000", never 40.5 * 10000 with its rounding error.
        $whole = (int) $matches[1];
        $fraction = (int) str_pad($matches[2] ?? '0', 4, '0');

        $basisPoints = $whole * self::ONE_PERCENT_BP + $fraction;

        if ($basisPoints > self::TOTAL_BP) {
            throw new InvalidArgumentException("A share cannot exceed 100%: {$percent}");
        }

        return $basisPoints;
    }

    /** Render basis points back as a percentage, trailing zeros trimmed. */
    public static function toPercent(int $basisPoints): string
    {
        $whole = intdiv($basisPoints, self::ONE_PERCENT_BP);
        $fraction = rtrim(str_pad((string) ($basisPoints % self::ONE_PERCENT_BP), 4, '0', STR_PAD_LEFT), '0');

        return $fraction === '' ? (string) $whole : $whole . '.' . $fraction;
    }

    /** Always four decimal places, for tables where columns should line up. */
    public static function formatPercent(int $basisPoints): string
    {
        $whole = intdiv($basisPoints, self::ONE_PERCENT_BP);
        $fraction = str_pad((string) ($basisPoints % self::ONE_PERCENT_BP), 4, '0', STR_PAD_LEFT);

        return $whole . '.' . $fraction;
    }

    /**
     * Check a proposed split. Returns human-readable problems; an empty array
     * means it may be activated.
     *
     * @param array<int,int> $shares partner id => basis points
     * @return list<string>
     */
    public static function validateSplit(array $shares): array
    {
        $errors = [];

        if ($shares === []) {
            return ['A split needs at least one partner.'];
        }

        $total = 0;
        foreach ($shares as $partnerId => $basisPoints) {
            if ($basisPoints <= 0) {
                $errors[] = 'Every partner in the split must have a share greater than zero.';
                continue;
            }
            if ($basisPoints > self::TOTAL_BP) {
                $errors[] = 'No single share may exceed 100%.';
                continue;
            }
            $total += $basisPoints;
        }

        if ($errors !== []) {
            return array_values(array_unique($errors));
        }

        if ($total !== self::TOTAL_BP) {
            $difference = $total - self::TOTAL_BP;
            $direction = $difference > 0 ? 'over' : 'under';
            $errors[] = sprintf(
                'Shares total %s%% — they must total exactly 100%%. That is %s%% %s.',
                self::toPercent($total),
                self::toPercent(abs($difference)),
                $direction
            );
        }

        return $errors;
    }

    /**
     * Activate a split from a date.
     *
     * Closes the previous split the day before, inserts the new rows, and does
     * both in one database transaction — a partially applied ownership change
     * would leave the company owning something other than 100% of itself.
     *
     * @param array<int,int> $shares partner id => basis points
     */
    public static function activateSplit(array $shares, string $effectiveFrom): void
    {
        $errors = self::validateSplit($shares);
        if ($errors !== []) {
            throw new RuntimeException($errors[0]);
        }

        $from = self::normaliseDate($effectiveFrom);
        $db = Database::instance();

        self::assertPartnersAreActive(array_keys($shares));

        // A split already dated on or after this one would have to be
        // recalculated, which is a decision for a person, not a default.
        $later = $db->value(
            'SELECT MIN(effective_from) FROM partner_shares WHERE effective_from >= :from',
            ['from' => $from]
        );
        if ($later !== null) {
            throw new RuntimeException(
                'A split already starts on ' . (string) $later . '. Remove it before adding an earlier one.'
            );
        }

        $db->transaction(static function (Database $db) use ($shares, $from): void {
            $closeOn = date('Y-m-d', (int) strtotime($from . ' -1 day'));

            $db->run(
                'UPDATE partner_shares SET effective_to = :closeOn
                 WHERE effective_to IS NULL AND effective_from < :from',
                ['closeOn' => $closeOn, 'from' => $from]
            );

            foreach ($shares as $partnerId => $basisPoints) {
                $db->insert('partner_shares', [
                    'partner_id' => (int) $partnerId,
                    'share_bp' => $basisPoints,
                    'effective_from' => $from,
                    'effective_to' => null,
                    'created_by' => Auth::id(),
                ]);
            }
        });

        Logger::security('Ownership split activated', [
            'effective_from' => $from,
            'partners' => array_keys($shares),
            'by' => Auth::id(),
        ]);
    }

    /**
     * The split in force on a date.
     *
     * @return array<int,int> partner id => basis points
     */
    public static function splitOn(string $date): array
    {
        $on = self::normaliseDate($date);

        $rows = Database::instance()->all(
            'SELECT partner_id, share_bp
             FROM partner_shares
             WHERE effective_from <= :on AND (effective_to IS NULL OR effective_to >= :on2)',
            ['on' => $on, 'on2' => $on]
        );

        $split = [];
        foreach ($rows as $row) {
            $split[(int) $row['partner_id']] = (int) $row['share_bp'];
        }

        return $split;
    }

    /** @return array<int,int> partner id => basis points */
    public static function currentSplit(): array
    {
        return self::splitOn(date('Y-m-d'));
    }

    /** Sum of shares in force on a date. 0 means no split exists yet. */
    public static function totalBpOn(string $date): int
    {
        return array_sum(self::splitOn($date));
    }

    /**
     * Is the split on this date usable for a payout? Anything other than
     * exactly 100% must block distribution rather than pay out a guess.
     */
    public static function isValidOn(string $date): bool
    {
        return self::totalBpOn($date) === self::TOTAL_BP;
    }

    /**
     * Every distinct split, newest first, for the history table.
     *
     * @return list<array{effective_from:string,effective_to:string|null,rows:list<array<string,mixed>>,total_bp:int}>
     */
    public static function history(): array
    {
        $rows = Database::instance()->all(
            'SELECT ps.effective_from, ps.effective_to, ps.share_bp,
                    p.id AS partner_id, p.name AS partner_name
             FROM partner_shares ps
             JOIN partners p ON p.id = ps.partner_id
             ORDER BY ps.effective_from DESC, p.name ASC'
        );

        $splits = [];
        foreach ($rows as $row) {
            $key = (string) $row['effective_from'];
            if (!isset($splits[$key])) {
                $splits[$key] = [
                    'effective_from' => $key,
                    'effective_to' => $row['effective_to'] === null ? null : (string) $row['effective_to'],
                    'rows' => [],
                    'total_bp' => 0,
                ];
            }
            $splits[$key]['rows'][] = $row;
            $splits[$key]['total_bp'] += (int) $row['share_bp'];
        }

        return array_values($splits);
    }

    /** @param list<int|string> $partnerIds */
    private static function assertPartnersAreActive(array $partnerIds): void
    {
        if ($partnerIds === []) {
            return;
        }

        $placeholders = [];
        $params = [];
        foreach (array_values($partnerIds) as $index => $id) {
            $placeholders[] = ':p' . $index;
            $params['p' . $index] = (int) $id;
        }

        $found = Database::instance()->all(
            'SELECT id, name, status FROM partners WHERE id IN (' . implode(', ', $placeholders) . ')',
            $params
        );

        if (count($found) !== count($partnerIds)) {
            throw new RuntimeException('A partner in the split no longer exists.');
        }

        foreach ($found as $partner) {
            if ($partner['status'] !== 'active') {
                throw new RuntimeException(
                    'Cannot include ' . (string) $partner['name'] . ' — the partner is inactive.'
                );
            }
        }
    }

    private static function normaliseDate(string $date): string
    {
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            throw new InvalidArgumentException("Not a valid date: {$date}");
        }

        return date('Y-m-d', $timestamp);
    }
}
