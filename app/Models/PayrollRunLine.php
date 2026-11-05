<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Model;
use App\Core\Upload;
use App\Domain\PayrollLineStatus;
use App\Services\Audit;
use RuntimeException;

/**
 * One employee's salary notice within a payroll run (PAY-1).
 *
 * Admin reads and writes are branch-scoped (App\Core\Model) and always
 * also filtered by run_id, so a line id from another run or branch is not
 * found. The public notice has no session branch: it finds its line by the
 * sha256 of the token in its URL alone (findByToken()), the same way
 * EmployeeJoinLink does, and only ever acts on that one line.
 */
final class PayrollRunLine extends Model
{
    public const SCREENSHOT_DIRECTORY = 'payroll-screenshots';

    /** 32 random bytes, hex: 64 characters, which the router's [A-Za-z0-9_-]+ placeholder accepts. */
    private const TOKEN_BYTES = 32;

    /** Every column the public notice reads, joined with its run and employee. */
    private const PUBLIC_COLUMNS = 'l.id, l.branch_id, l.run_id, l.employee_code, l.salary, l.absent_days,
        l.working_days, l.screenshot_filename, l.token_expires_at, l.status, l.signed_name, l.responded_at,
        l.query_text, r.period_month, r.note, u.name';

    protected string $table = 'payroll_run_lines';

    /** @var list<string> */
    protected array $fillable = ['salary', 'absent_days', 'working_days', 'screenshot_filename'];

    /** @return list<array<string,mixed>> the run's lines with each employee's name and email, by Employee ID */
    public function forRun(int $runId): array
    {
        return $this->db()->all(
            'SELECT l.*, u.name, u.email, u.status AS user_status
             FROM payroll_run_lines l
             JOIN users u ON u.id = l.user_id
             WHERE l.run_id = :run AND l.branch_id = :branch
             ORDER BY l.employee_code',
            ['run' => $runId, 'branch' => $this->requireBranchId()]
        );
    }

    /** @return array<string,mixed>|null */
    public function findInRun(int $runId, int $lineId): ?array
    {
        return $this->db()->first(
            'SELECT l.*, u.name, u.email, u.status AS user_status
             FROM payroll_run_lines l
             JOIN users u ON u.id = l.user_id
             WHERE l.id = :id AND l.run_id = :run AND l.branch_id = :branch
             LIMIT 1',
            ['id' => $lineId, 'run' => $runId, 'branch' => $this->requireBranchId()]
        );
    }

    /**
     * New figures, while the line is anything but confirmed. Correcting a
     * line that was sent and is awaiting a response cancels its link (see
     * cancelLinkIfSent()); the admin then sends it again.
     *
     * @param array{salary:string,absent_days:string,working_days:string} $figures
     * @return bool false when the line is not found or already confirmed
     */
    public function updateFigures(int $runId, int $lineId, array $figures): bool
    {
        $branchId = $this->requireBranchId();

        return (bool) Database::instance()->transaction(static function (Database $db) use (
            $runId,
            $lineId,
            $branchId,
            $figures
        ): bool {
            $line = self::lockEditable($db, $runId, $lineId, $branchId);
            if ($line === null) {
                return false;
            }

            $db->update('payroll_run_lines', $figures, 'id = :id AND branch_id = :branch', [
                'id' => $lineId,
                'branch' => $branchId,
            ]);
            $diff = Audit::diff(array_intersect_key($line, $figures), $figures);
            Audit::record(
                'payroll_line.figures_updated',
                'payroll_run_lines',
                $lineId,
                $diff['before'],
                $diff['after']
            );
            self::cancelLinkIfSent($db, $line, $lineId, $branchId);

            return true;
        });
    }

    /**
     * Store a new attendance screenshot for the line (png, jpeg or webp,
     * sniffed, outside the web root) and drop the one it replaces once the
     * change is durable. Throws RuntimeException with a user-facing message
     * for a bad upload; returns false when the line is no longer editable.
     *
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
     */
    public function attachScreenshot(int $runId, int $lineId, array $file): bool
    {
        if (!$this->editable($runId, $lineId)) {
            return false;
        }

        $stored = Upload::storePrivateImage($file, self::SCREENSHOT_DIRECTORY);

        return $this->useStoredScreenshot($runId, $lineId, $stored['filename']);
    }

    /**
     * Point the line at a screenshot already stored by Upload, and remove
     * the previous one. Separate from attachScreenshot() so a one-off
     * loader working from files on disk takes the same path after storage.
     */
    public function useStoredScreenshot(int $runId, int $lineId, string $filename): bool
    {
        $branchId = $this->requireBranchId();
        self::screenshotPath($filename);

        $result = Database::instance()->transaction(static function (Database $db) use (
            $runId,
            $lineId,
            $branchId,
            $filename
        ): ?string {
            $line = self::lockEditable($db, $runId, $lineId, $branchId);
            if ($line === null) {
                return null;
            }

            $db->update(
                'payroll_run_lines',
                ['screenshot_filename' => $filename],
                'id = :id AND branch_id = :branch',
                ['id' => $lineId, 'branch' => $branchId]
            );
            Audit::record('payroll_line.screenshot_attached', 'payroll_run_lines', $lineId, null, null);
            self::cancelLinkIfSent($db, $line, $lineId, $branchId);

            $previous = $line['screenshot_filename'] === null ? '' : (string) $line['screenshot_filename'];
            $db->afterCommit(static function () use ($previous, $runId): void {
                if ($previous !== '') {
                    self::removeScreenshotFile($previous, $runId);
                }
            });

            return $previous;
        });

        if ($result === null) {
            self::removeScreenshotFile($filename, $runId);
            return false;
        }

        return true;
    }

    /**
     * Record that the notice was emailed with a new token: the previous
     * token stops working at once, and any earlier response is cleared (the
     * caller has already written it to the audit log). Applies only if the
     * line is still in $expectedStatus, so a response that lands while the
     * email was going out is never overwritten.
     */
    public function markSent(int $lineId, string $expectedStatus, string $token, string $expiresAt): bool
    {
        return $this->db()->update(
            'payroll_run_lines',
            [
                'token_hash' => self::hash($token),
                'token_expires_at' => $expiresAt,
                'emailed_at' => date('Y-m-d H:i:s'),
                'status' => 'sent',
                'signed_name' => null,
                'responded_at' => null,
                'response_ip' => null,
                'response_user_agent' => null,
                'query_text' => null,
            ],
            'id = :id AND branch_id = :branch AND status = :expected',
            ['id' => $lineId, 'branch' => $this->requireBranchId(), 'expected' => $expectedStatus]
        ) === 1;
    }

    private function editable(int $runId, int $lineId): bool
    {
        $line = $this->findInRun($runId, $lineId);

        return $line !== null && PayrollLineStatus::from((string) $line['status'])->canEdit();
    }

    /**
     * The line, locked for this transaction, if it may still change — any
     * status but confirmed.
     *
     * @return array<string,mixed>|null
     */
    private static function lockEditable(Database $db, int $runId, int $lineId, int $branchId): ?array
    {
        return $db->first(
            "SELECT * FROM payroll_run_lines
             WHERE id = :id AND run_id = :run AND branch_id = :branch AND status IN ('draft','sent','query')
             FOR UPDATE",
            ['id' => $lineId, 'run' => $runId, 'branch' => $branchId]
        );
    }

    /**
     * A notice awaiting a response was just corrected: the link already
     * emailed must not show the old figures or accept an answer to them.
     * The token is dropped (that link now gets the neutral page) and the
     * line goes back to draft for the admin to send again. emailed_at is
     * kept — an email did go out, which is what locks the month's note.
     *
     * @param array<string,mixed> $line the row as locked, before this change
     */
    private static function cancelLinkIfSent(Database $db, array $line, int $lineId, int $branchId): void
    {
        if ((string) $line['status'] !== PayrollLineStatus::Sent->value) {
            return;
        }

        $db->update(
            'payroll_run_lines',
            ['status' => PayrollLineStatus::Draft->value, 'token_hash' => null, 'token_expires_at' => null],
            'id = :id AND branch_id = :branch',
            ['id' => $lineId, 'branch' => $branchId]
        );
        Audit::record(
            'payroll_line.link_cancelled',
            'payroll_run_lines',
            $lineId,
            ['status' => PayrollLineStatus::Sent->value, 'link_expires_at' => $line['token_expires_at']],
            ['status' => PayrollLineStatus::Draft->value]
        );
    }

    public static function newToken(): string
    {
        return bin2hex(random_bytes(self::TOKEN_BYTES));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * The notice behind a public link: live (not expired, not replaced by a
     * later send), in an active branch, and sent at least once. Unknown,
     * malformed, replaced and expired tokens all return null, and the caller
     * shows one neutral page for every one of them.
     *
     * @return array<string,mixed>|null
     */
    public static function findByToken(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        return Database::instance()->first(
            'SELECT ' . self::PUBLIC_COLUMNS . "
             FROM payroll_run_lines l
             JOIN payroll_runs r ON r.id = l.run_id AND r.branch_id = l.branch_id
             JOIN users u ON u.id = l.user_id
             JOIN branches b ON b.id = l.branch_id AND b.is_active = 1
             WHERE l.token_hash = :hash
               AND l.token_expires_at > :now
               AND l.status IN ('sent','confirmed','query')
             LIMIT 1",
            ['hash' => self::hash($token), 'now' => date('Y-m-d H:i:s')]
        );
    }

    /**
     * The employee's one response for this send: confirmed (with the name
     * they typed) or query (with their comment). Final — it applies only
     * while the line is awaiting a response under this very token.
     *
     * @return bool false when the line has already responded, was re-sent or expired
     */
    public static function respond(
        string $token,
        string $status,
        ?string $signedName,
        ?string $queryText,
        string $ip,
        string $userAgent
    ): bool {
        if (!in_array($status, ['confirmed', 'query'], true)) {
            throw new RuntimeException('Not a response status.');
        }

        $now = date('Y-m-d H:i:s');

        return Database::instance()->update(
            'payroll_run_lines',
            [
                'status' => $status,
                'signed_name' => $signedName,
                'query_text' => $queryText,
                'responded_at' => $now,
                'response_ip' => $ip,
                'response_user_agent' => mb_substr($userAgent, 0, 255),
            ],
            "token_hash = :hash AND status = 'sent' AND token_expires_at > :now",
            ['hash' => self::hash($token), 'now' => $now]
        ) === 1;
    }

    /**
     * The one place a stored screenshot name becomes a filesystem path. The
     * pattern is exactly what App\Core\Upload generates for an image, so a
     * tampered row can never point outside the screenshot directory.
     */
    public static function screenshotPath(string $filename): string
    {
        if (preg_match('/^[a-f0-9]{32}\.(png|jpg|webp)$/', $filename) !== 1) {
            throw new RuntimeException('Invalid stored filename for a payroll screenshot.');
        }

        return STORAGE_PATH . '/documents/' . self::SCREENSHOT_DIRECTORY . '/' . $filename;
    }

    public static function screenshotMime(string $filename): string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }

    public static function removeScreenshotFile(string $filename, ?int $runId): void
    {
        try {
            $path = self::screenshotPath($filename);
        } catch (RuntimeException) {
            return;
        }

        if (is_file($path) && !unlink($path)) {
            Logger::error('Payroll screenshot could not be removed', ['run_id' => $runId]);
        }
    }
}
