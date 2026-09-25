<?php

/**
 * Recurring transactions: the nightly catch-up generator. For every active
 * branch, generates every draft its recurring rules are due for as of today
 * into `recurring_occurrences` — see App\Services\RecurringRuleService for
 * why this never touches `transactions` or the audit trail directly.
 *
 *   php scripts/generate-recurring-transactions.php
 *
 * CLI only. Run from .github/workflows/maintenance.yml on the same nightly
 * schedule as scripts/maintenance.php and scripts/preflight.php.
 *
 * One bad branch must not block the rest: a per-branch failure is logged
 * and counted, but every other branch still gets its turn. The whole run
 * only exits non-zero if at least one branch failed, matching
 * preflight.php's pass/fail tally convention.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Core\Logger;
use App\Services\RecurringRuleService;

$asOf = date('Y-m-d');
$branches = Database::instance()->all('SELECT id, name FROM branches WHERE is_active = 1');

$failures = 0;

foreach ($branches as $branch) {
    $branchId = (int) $branch['id'];
    $branchName = (string) $branch['name'];

    try {
        $count = RecurringRuleService::generateForBranch($branchId, $asOf);
        echo "{$branchName} (#{$branchId}): {$count} occurrence(s) generated\n";
    } catch (Throwable $e) {
        $failures++;
        Logger::error('Recurring transaction generation failed for branch', [
            'branch_id' => $branchId,
            'error' => $e->getMessage(),
        ]);
        echo "{$branchName} (#{$branchId}): FAILED - {$e->getMessage()}\n";
    }
}

echo $failures === 0
    ? "Recurring transaction generation complete: " . count($branches) . " branch(es), 0 failure(s).\n"
    : "Recurring transaction generation complete: {$failures} branch(es) failed.\n";

exit($failures === 0 ? 0 : 1);
