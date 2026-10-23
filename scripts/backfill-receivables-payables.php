<?php

/**
 * AR/AP-3 one-time backfill: posts register entries recorded before the
 * receivables / payables register moved account balances (migration
 * 2026_10_23_000018). See App\Services\ReceivablePayableBackfill for the
 * rules: open entries get their issue posting, settled entries their issue
 * and settlement postings, cancelled entries nothing, always through the
 * branch's Cash account; a branch without one is skipped and listed.
 *
 *   php scripts/backfill-receivables-payables.php --dry-run   show what would post
 *   php scripts/backfill-receivables-payables.php             post it
 *
 * Run it after database/migrate.php has applied 000018. Idempotent: a second
 * run finds nothing left to post. CLI only.
 *
 * Exit code: 0 when every selected entry was posted, 1 when any entry failed,
 * 2 when entries were skipped for want of a cash account (fix the branch,
 * then run it again).
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
$report = ReceivablePayableBackfill::run($dryRun);

echo $dryRun ? "DRY RUN - nothing is written.\n" : '';

foreach ($report['posted'] as $entry) {
    printf(
        "%s %s #%d (%s, branch #%d)%s\n",
        $dryRun ? 'would post' : 'posted',
        $entry['kind'],
        $entry['id'],
        $entry['status'],
        $entry['branch_id'],
        $dryRun ? '' : sprintf(
            ': issue tx %s, settlement tx %s',
            $entry['issue_transaction_id'] ?? '-',
            $entry['settlement_transaction_id'] ?? '-'
        )
    );
}

foreach ($report['skipped'] as $entry) {
    printf(
        "SKIPPED %s #%d (%s) in branch #%d \"%s\": %s\n",
        $entry['kind'],
        $entry['id'],
        $entry['status'],
        $entry['branch_id'],
        $entry['branch_name'],
        $entry['reason']
    );
}

foreach ($report['failed'] as $entry) {
    printf("FAILED %s #%d (branch #%d): %s\n", $entry['kind'], $entry['id'], $entry['branch_id'], $entry['error']);
}

$counts = [
    'posted' => count($report['posted']),
    'skipped' => count($report['skipped']),
    'failed' => count($report['failed']),
];

printf(
    "Receivables/payables backfill%s: %d entr%s %s, %d skipped, %d failed.\n",
    $dryRun ? ' (dry run)' : '',
    $counts['posted'],
    $counts['posted'] === 1 ? 'y' : 'ies',
    $dryRun ? 'to post' : 'posted',
    $counts['skipped'],
    $counts['failed']
);

if (!$dryRun) {
    Logger::info('Receivables/payables backfill run', $counts);
}

exit($counts['failed'] > 0 ? 1 : ($counts['skipped'] > 0 ? 2 : 0));
