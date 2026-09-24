<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Domain\TransactionType;
use InvalidArgumentException;
use RuntimeException;

/**
 * Turns a period's profit into per-partner payouts.
 *
 * Three steps, each a deliberate stopping point rather than one action,
 * because this is the module that decides who gets paid (spec §11):
 *
 *   1. calculate() — snapshot the profit and the split as of the period end
 *      into a batch of 'calculated' rows. Nothing is paid yet.
 *   2. approve()   — a second, distinct action moves the batch to 'approved'.
 *   3. distribute()— posts one PROFIT_DISTRIBUTION ledger row per partner,
 *      through TransactionService::post() like every other movement, and
 *      only then marks the batch 'distributed'.
 *
 * calculated_amount and distributed_amount are stored separately per spec
 * §11, even though this flow always distributes exactly what was calculated.
 *
 * Rounding: profit divided by basis-point shares rarely divides evenly.
 * allocate() below uses the largest-remainder method entirely in integer
 * cents (via bcmath, never float) so the allocations always sum to EXACTLY
 * the distributable profit — the remainder is never dropped, and which
 * partner absorbs it is deterministic rather than "whoever floats highest".
 */
final class ProfitDistributionService
{
    /**
     * Net profit and loss for a period, cash basis: only posted rows, and
     * only the types that belong in P&L (transfers and capital excluded by
     * construction — see TransactionType::affectsProfitAndLoss()).
     */
    public static function distributableProfit(string $periodStart, string $periodEnd): string
    {
        [$start, $end] = self::normalisePeriod($periodStart, $periodEnd);

        return LedgerQuery::posted()->between($start, $end)->profitAndLossOnly()->netAmount();
    }

    /**
     * Snapshot a period's profit and the split in force on its last day into
     * a new batch of 'calculated' rows. Refuses rather than guesses when the
     * split does not total 100%, when there is nothing to distribute, or when
     * this exact period has already been calculated.
     *
     * @return string the new batch id
     */
    public static function calculate(string $periodStart, string $periodEnd): string
    {
        [$start, $end] = self::normalisePeriod($periodStart, $periodEnd);

        if (self::batchExistsFor($start, $end)) {
            throw new RuntimeException(
                'This period has already been calculated. Void that batch before recalculating.'
            );
        }

        if (!ShareService::isValidOn($end)) {
            throw new RuntimeException(
                'Ownership shares must total exactly 100% as of ' . $end . ' before profit can be distributed.'
            );
        }

        $profit = self::distributableProfit($start, $end);
        if ((float) $profit <= 0.0) {
            throw new InvalidArgumentException('There is no distributable profit for this period.');
        }

        $split = ShareService::splitOn($end);
        $allocations = self::allocate($profit, $split);

        $userId = Auth::id();
        if ($userId === null) {
            throw new RuntimeException('A profit calculation must be attributed to a signed-in user.');
        }

        $batchId = bin2hex(random_bytes(16));

        Database::instance()->transaction(static function (Database $db) use (
            $batchId,
            $start,
            $end,
            $split,
            $allocations,
            $userId
        ): void {
            foreach ($allocations as $partnerId => $amount) {
                $db->insert('profit_distributions', [
                    'batch_id' => $batchId,
                    'period_start' => $start,
                    'period_end' => $end,
                    'partner_id' => $partnerId,
                    'share_bp' => $split[$partnerId],
                    'calculated_amount' => $amount,
                    'status' => 'calculated',
                    'created_by' => $userId,
                ]);
            }

            Audit::record('profit_distribution.calculated', 'profit_distributions', null, null, [
                'batch_id' => $batchId,
                'period_start' => $start,
                'period_end' => $end,
                'partners' => count($allocations),
            ]);
        });

        Logger::info('Profit distribution calculated', [
            'batch_id' => $batchId,
            'period_start' => $start,
            'period_end' => $end,
            'partners' => count($allocations),
        ]);

        return $batchId;
    }

    /** Move a calculated batch to approved. A distinct action from calculating, deliberately. */
    public static function approve(string $batchId): void
    {
        $rows = self::batchRows($batchId);
        self::assertBatchStatus($rows, 'calculated', 'approved');

        $userId = Auth::id();
        if ($userId === null) {
            throw new RuntimeException('An approval must be attributed to a signed-in user.');
        }

        Database::instance()->transaction(static function (Database $db) use ($batchId, $userId): void {
            $db->update('profit_distributions', [
                'status' => 'approved',
                'approved_by' => $userId,
                'approved_at' => date('Y-m-d H:i:s'),
            ], 'batch_id = :b', ['b' => $batchId]);

            Audit::record('profit_distribution.approved', 'profit_distributions', null, null, ['batch_id' => $batchId]);
        });

        Logger::security('Profit distribution approved', ['batch_id' => $batchId, 'by' => $userId]);
    }

    /**
     * Post the ledger rows and mark the batch distributed.
     *
     * Every partner's payout goes through TransactionService::post() — the
     * only way anything writes to the ledger — inside one outer database
     * transaction, so a batch either pays every partner or none of them.
     */
    public static function distribute(string $batchId, int $accountId): void
    {
        $rows = self::batchRows($batchId);
        self::assertBatchStatus($rows, 'approved', 'distributed');

        $userId = Auth::id();
        if ($userId === null) {
            throw new RuntimeException('A distribution must be attributed to a signed-in user.');
        }

        $periodLabel = date('M Y', (int) strtotime((string) $rows[0]['period_start']))
            . ' – ' . date('M Y', (int) strtotime((string) $rows[0]['period_end']));

        Database::instance()->transaction(static function (Database $db) use (
            $rows,
            $accountId,
            $periodLabel,
            $batchId
        ): void {
            foreach ($rows as $row) {
                $transactionId = TransactionService::post([
                    'type' => TransactionType::ProfitDistribution,
                    'amount' => (string) $row['calculated_amount'],
                    'account_id' => $accountId,
                    'partner_id' => (int) $row['partner_id'],
                    'transaction_date' => date('Y-m-d'),
                    'description' => 'Profit distribution for ' . $periodLabel,
                ]);

                $db->update('profit_distributions', [
                    'distributed_amount' => $row['calculated_amount'],
                    'status' => 'distributed',
                    'account_id' => $accountId,
                    'transaction_id' => $transactionId,
                ], 'id = :id', ['id' => (int) $row['id']]);
            }

            Audit::record('profit_distribution.distributed', 'profit_distributions', null, null, [
                'batch_id' => $batchId,
                'account_id' => $accountId,
                'partners' => count($rows),
            ]);
        });

        Logger::security('Profit distribution paid out', [
            'batch_id' => $batchId,
            'account_id' => $accountId,
            'partners' => count($rows),
            'by' => $userId,
        ]);
    }

    /** @return list<array<string,mixed>> every batch, most recent period first, one row per batch */
    public static function batches(): array
    {
        return Database::instance()->all(
            'SELECT batch_id, period_start, period_end, status,
                    COUNT(*) AS partner_count,
                    SUM(calculated_amount) AS total_amount,
                    MIN(created_at) AS created_at
             FROM profit_distributions
             GROUP BY batch_id, period_start, period_end, status
             ORDER BY period_end DESC, created_at DESC'
        );
    }

    /** @return list<array<string,mixed>> */
    public static function batchRows(string $batchId): array
    {
        return Database::instance()->all(
            'SELECT pd.*, p.name AS partner_name
             FROM profit_distributions pd
             JOIN partners p ON p.id = pd.partner_id
             WHERE pd.batch_id = :b
             ORDER BY p.name ASC',
            ['b' => $batchId]
        );
    }

    /**
     * Split a decimal amount across basis-point shares so the parts sum to
     * EXACTLY the whole, using the largest-remainder method in integer
     * cents. Every step uses bcmath strings — never float — because a
     * penny lost to rounding is a penny that does not reconcile.
     *
     * @param array<int,int> $splitBp partner id => basis points, must sum to ShareService::TOTAL_BP
     * @return array<int,string> partner id => amount, decimal string, sums to $amount exactly
     */
    private static function allocate(string $amount, array $splitBp): array
    {
        $totalCents = bcmul($amount, '100', 0);
        $totalBp = (string) ShareService::TOTAL_BP;

        $floors = [];
        $remainders = [];
        $allocatedCents = '0';

        foreach ($splitBp as $partnerId => $bp) {
            $numerator = bcmul($totalCents, (string) $bp, 0);
            $floors[$partnerId] = bcdiv($numerator, $totalBp, 0);
            $remainders[$partnerId] = bcmod($numerator, $totalBp);
            $allocatedCents = bcadd($allocatedCents, $floors[$partnerId], 0);
        }

        $leftoverCents = (int) bcsub($totalCents, $allocatedCents, 0);

        if ($leftoverCents > 0) {
            // Largest remainder first; ties broken by partner id so the
            // outcome is reproducible rather than dependent on sort stability.
            $order = array_keys($splitBp);
            usort($order, static function (int $a, int $b) use ($remainders): int {
                $byRemainder = bccomp($remainders[$b], $remainders[$a], 0);
                return $byRemainder !== 0 ? $byRemainder : $a <=> $b;
            });

            for ($i = 0; $i < $leftoverCents; $i++) {
                $partnerId = $order[$i];
                $floors[$partnerId] = bcadd($floors[$partnerId], '1', 0);
            }
        }

        $allocations = [];
        foreach ($floors as $partnerId => $cents) {
            $allocations[$partnerId] = bcdiv($cents, '100', 2);
        }

        return $allocations;
    }

    private static function batchExistsFor(string $start, string $end): bool
    {
        return (int) Database::instance()->value(
            'SELECT COUNT(*) FROM profit_distributions WHERE period_start = :s AND period_end = :e',
            ['s' => $start, 'e' => $end]
        ) > 0;
    }

    /** @param list<array<string,mixed>> $rows */
    private static function assertBatchStatus(array $rows, string $expected, string $verb): void
    {
        if ($rows === []) {
            throw new RuntimeException('That distribution batch does not exist.');
        }

        foreach ($rows as $row) {
            if ((string) $row['status'] !== $expected) {
                throw new RuntimeException("This batch cannot be {$verb} from its current status.");
            }
        }
    }

    /** @return array{0:string,1:string} */
    private static function normalisePeriod(string $periodStart, string $periodEnd): array
    {
        $start = self::normaliseDate($periodStart);
        $end = self::normaliseDate($periodEnd);

        if (strtotime($start) > strtotime($end)) {
            throw new InvalidArgumentException('The period start must not be after the period end.');
        }

        return [$start, $end];
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
