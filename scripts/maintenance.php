<?php

/**
 * Scheduled housekeeping: prunes rows/files that would otherwise grow
 * forever. Run from .github/workflows/maintenance.yml on a cron schedule —
 * see docs/SECURITY.md §7, which is what surfaced that nothing was pruning
 * storage/logs or rate_limits.
 *
 *   php scripts/maintenance.php
 *
 * CLI only.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Logger;
use App\Core\RateLimiter;
use App\Core\RememberMe;

// Rate-limit rows only ever matter for the decay window they were written
// for (the longest currently configured is the login lockout); a day past
// that they exist purely as dead weight in the table.
RateLimiter::prune(86400);
echo "rate_limits: pruned rows older than 1 day\n";

// An expired token can never sign anyone in (RememberMe::consume() checks
// expires_at); pruning only keeps the table down to live devices.
$expiredTokens = RememberMe::prune();
echo "remember_tokens: deleted {$expiredTokens} expired token(s)\n";

// Each day already gets its own log file; this only removes whole files
// past the retention window, never touches the one being written to today.
$logFilesDeleted = Logger::prune(30);
echo "storage/logs: deleted {$logFilesDeleted} file(s) older than 30 days\n";

echo "Maintenance complete.\n";
