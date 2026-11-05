<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Core\Session;

/**
 * An "add or update" sheet (PAY-4) held between its summary page and the
 * admin pressing Apply: the file's valid rows and the state of the month
 * they were checked against, kept in the admin's own session under a random
 * key. One at a time per session (a new upload replaces it), bound to the
 * run and the admin, usable once, and only for a few minutes.
 *
 * Holding it does not mean it is still right: applying validates the rows
 * again against the month as it is then (PayrollRun::updateFromSheet()).
 */
final class PendingSheetUpdate
{
    public const TTL_SECONDS = 900;

    private const SESSION_KEY = 'payroll_pending_sheet';

    /** @param array<int,list<string>> $rows */
    public static function stash(int $runId, int $userId, array $rows, string $state): string
    {
        $key = bin2hex(random_bytes(16));
        Session::set(self::SESSION_KEY, [
            'key' => $key,
            'run_id' => $runId,
            'user_id' => $userId,
            'expires_at' => time() + self::TTL_SECONDS,
            'rows' => $rows,
            'state' => $state,
        ]);

        return $key;
    }

    /**
     * The held sheet for this key, run and admin, removed so it cannot be
     * applied twice; null if there is none, or it is for another run or
     * user, or it has expired.
     *
     * @return array{rows:array<int,list<string>>,state:string}|null
     */
    public static function take(string $key, int $runId, int $userId): ?array
    {
        $pending = Session::get(self::SESSION_KEY);
        if (!is_array($pending) || !is_string($pending['key'] ?? null) || !hash_equals($pending['key'], $key)) {
            return null;
        }
        Session::forget(self::SESSION_KEY);

        if (
            ($pending['run_id'] ?? null) !== $runId
            || ($pending['user_id'] ?? null) !== $userId
            || !is_int($pending['expires_at'] ?? null)
            || $pending['expires_at'] < time()
            || !is_array($pending['rows'] ?? null)
            || !is_string($pending['state'] ?? null)
        ) {
            return null;
        }

        /** @var array<int,list<string>> $rows */
        $rows = $pending['rows'];

        return ['rows' => $rows, 'state' => $pending['state']];
    }
}
