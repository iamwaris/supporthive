<?php

/**
 * AR/AP-3 one-time backfill: posts register entries recorded before the
 * receivables / payables register moved account balances (migration
 * 2026_10_23_000018). See App\Services\ReceivablePayableBackfill for the
 * rules: open entries get their issue posting, settled entries their issue
 * and settlement postings, cancelled entries nothing, always through the
 * branch's active cash-type account named "Cash". A branch without one is
 * skipped and listed with its cash-type accounts; nothing is guessed.
 *
 *   php scripts/backfill-receivables-payables.php --dry-run   show what would post, and where
 *   php scripts/backfill-receivables-payables.php             post it
 *   ... --branch=ID                                             limit either to one branch
 *
 * Run it after database/migrate.php has applied 000018. Idempotent: a second
 * run finds nothing left to post. CLI only.
 *
 * Exit code: 0 when every selected entry was posted (or would be), 1 when any
 * entry failed, 2 when a branch was skipped for want of its "Cash" account
 * (create or rename it, then run again).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Logger;
use App\Services\ReceivablePayableBackfill;

$dryRun = in_array('--dry-run', $argv, true);
$branchFilter = null;
foreach ($argv as $argument) {
    if (preg_match('/^--branch=(\d+)$/', $argument, $match) === 1) {
        $branchFilter = (int) $match[1];
    }
}

$report = ReceivablePayableBackfill::run($dryRun, $branchFilter);

echo $dryRun ? "DRY RUN - nothing is written.\n" : '';

$account = static fn (array $line): string => sprintf('account #%d "%s"', $line['account_id'], $line['account_name']);

foreach ($report['posted'] as $entry) {
    printf(
        "%s %s #%d (%s, branch #%d) via %s%s\n",
        $dryRun ? 'would post' : 'posted',
        $entry['kind'],
        $entry['id'],
        $entry['status'],
        $entry['branch_id'],
        $account($entry),
        $dryRun ? '' : sprintf(
            ': issue tx %s, settlement tx %s',
            $entry['issue_transaction_id'] ?? '-',
            $entry['settlement_transaction_id'] ?? '-'
        )
    );
}

foreach ($report['failed'] as $entry) {
    printf(
        "FAILED %s #%d (%s, branch #%d) via %s: %s\n",
        $entry['kind'],
        $entry['id'],
        $entry['status'],
        $entry['branch_id'],
        $account($entry),
        $entry['error']
    );
}

echo "\nBy branch:\n";
$skippedBranches = 0;
foreach ($report['branches'] as $branch) {
    $entryCount = count($branch['entries']);
    if ($branch['account_id'] !== null) {
        printf(
            "  branch #%d \"%s\": %d entr%s via account #%d \"%s\"\n",
            $branch['branch_id'],
            $branch['branch_name'],
            $entryCount,
            $entryCount === 1 ? 'y' : 'ies',
            $branch['account_id'],
            (string) $branch['account_name']
        );
        continue;
    }

    $skippedBranches++;
    $candidates = $branch['candidates'] === []
        ? 'no cash-type accounts at all'
        : 'cash-type accounts: ' . implode(', ', array_map(
            static fn (array $c): string => sprintf(
                '#%d "%s"%s',
                $c['id'],
                $c['name'],
                $c['is_active'] ? '' : ' (closed)'
            ),
            $branch['candidates']
        ));
    printf(
        "  SKIPPED branch #%d \"%s\": no active cash-type account named \"Cash\" (%s). %d entr%s not posted: %s\n",
        $branch['branch_id'],
        $branch['branch_name'],
        $candidates,
        $entryCount,
        $entryCount === 1 ? 'y' : 'ies',
        implode(', ', array_map(
            static fn (array $e): string => sprintf('%s #%d (%s)', $e['kind'], $e['id'], $e['status']),
            $branch['entries']
        ))
    );
}
if ($report['branches'] === []) {
    echo "  nothing left to post\n";
}

$counts = [
    'posted' => count($report['posted']),
    'failed' => count($report['failed']),
    'skipped_branches' => $skippedBranches,
];

printf(
    "Receivables/payables backfill%s: %d entr%s %s, %d failed, %d branch(es) skipped.\n",
    $dryRun ? ' (dry run)' : '',
    $counts['posted'],
    $counts['posted'] === 1 ? 'y' : 'ies',
    $dryRun ? 'to post' : 'posted',
    $counts['failed'],
    $counts['skipped_branches']
);

if (!$dryRun) {
    Logger::info('Receivables/payables backfill run', $counts);
}

exit($counts['failed'] > 0 ? 1 : ($counts['skipped_branches'] > 0 ? 2 : 0));
