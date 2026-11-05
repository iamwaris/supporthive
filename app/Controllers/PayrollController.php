<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Http;
use App\Core\Logger;
use App\Core\Session;
use App\Core\SpreadsheetReader;
use App\Core\UnreadableSpreadsheet;
use App\Core\Validator;
use App\Domain\PayrollLineStatus;
use App\Models\PayrollRun;
use App\Models\PayrollRunLine;
use App\Services\Payroll\PayrollImport;
use App\Services\Payroll\PayrollNoticeSender;
use App\Services\Payroll\PayrollSheetUpdate;
use App\Services\Payroll\PendingSheetUpdate;
use App\Services\Settings;
use InvalidArgumentException;
use PDOException;
use RuntimeException;

/**
 * Salary notices (PAY-1 to PAY-4), admin only (`can:administer`), scoped to
 * the admin's branch: import a month's sheet, add missing employees and
 * update figures with a further file, attach each employee's attendance screenshot, preview
 * the email and the notice page, email each employee their private link, and
 * see who confirmed or queried, and withdraw the whole month to correct
 * and resend it.
 *
 * Every run id resolves through PayrollRun::find() (branch-scoped) and every
 * line id through PayrollRunLine::findInRun() (branch- and run-scoped), so
 * another branch's run, or a line of another run, is a 404.
 */
final class PayrollController extends Controller
{
    private const PER_PAGE = 24;

    /**
     * YYYY-MM, months 01..12. Kept free of `|`: the rule string is split on
     * it, and an alternation here once cut the pattern in half (a 500).
     */
    public const MONTH_RULE = 'required|regex:/^20\d{2}-(?!00)(?!1[3-9])[01]\d$/';
    private const NOTE_RULE = 'nullable|max:5000';
    private const WITHDRAW_REASON_RULE = 'required|max:500';

    private const FIGURE_RULES = [
        'salary' => 'required|money|max:16',
        'absent_days' => 'required|regex:/^\d{1,2}(\.\d)?$/|between:0,31',
        'working_days' => 'required|regex:/^\d{1,2}(\.\d)?$/|between:0,31',
    ];

    public function index(): void
    {
        $filters = Validator::make(
            isset($_GET['page']) && is_string($_GET['page']) ? ['page' => $_GET['page']] : [],
            ['page' => 'nullable|int|between:1,100000']
        )->validated();
        $page = isset($filters['page']) ? (int) $filters['page'] : 1;

        $runs = new PayrollRun();
        $total = $runs->count();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);

        $this->view('pages/salary-notices/index', [
            'title' => 'Salary notices',
            'nav' => 'salary-notices',
            'pageTitle' => 'Salary notices',
            'pageMeta' => 'Each month: import the sheet, attach screenshots, email every employee their own notice.',
            'runs' => $runs->listWithCounts($page, self::PER_PAGE),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'perPage' => self::PER_PAGE,
        ]);
    }

    public function create(): void
    {
        $this->renderImportForm([], 200);
    }

    /**
     * Parse and validate the uploaded sheet, then save it — all of it or
     * nothing. Errors are listed row by row on the form itself (status 422)
     * rather than flashed, so a long list never sits in the session.
     */
    public function import(): void
    {
        $clean = $this->validate([
            'period_month' => self::MONTH_RULE,
            'note' => self::NOTE_RULE,
            'replace' => 'nullable|in:1',
        ], '/salary-notices/new');

        $periodMonth = (string) $clean['period_month'] . '-01';
        $note = self::noteOrNull($clean['note']);
        $monthLabel = PayrollNoticeSender::monthLabel($periodMonth);

        $rows = $this->readUploadedSheet('/salary-notices/new', 'Choose the month\'s .xlsx or .csv file.');
        $runs = new PayrollRun();
        try {
            $result = PayrollImport::validate($rows, $runs->branchEmployees());
            $existing = $runs->findForMonth($periodMonth);
            $outstanding = $existing !== null && $runs->hasOutstandingNotices((int) $existing['id']);
        } catch (PDOException $e) {
            Logger::error('Payroll import lookup failed', ['error' => $e->getMessage()]);
            $this->failImport('Nothing was saved: the sheet could not be checked just now. Please try again.');
        }

        if (!$result->passes()) {
            Logger::info('Payroll import rejected', ['errors' => count($result->errors)]);
            $this->renderImportForm($result->errors, 422);
            return;
        }

        if ($existing !== null) {
            if ($outstanding) {
                $this->failImport(
                    'Notices for ' . $monthLabel . ' are out with employees, so its lines cannot be replaced. '
                    . 'Correct individual lines on the run page, or withdraw this month\'s notices there first.'
                );
            }
            if ($clean['replace'] !== '1') {
                Session::flash('payroll_replace_month', $monthLabel);
                $this->failImport(
                    'There is already a run for ' . $monthLabel . '. Tick "Replace its lines" and upload the file '
                    . 'again to replace them. Screenshots already attached to that run are removed.'
                );
            }
        }

        try {
            $runId = $runs->import($periodMonth, $note, $result->lines);
        } catch (RuntimeException | PDOException $e) {
            // Another admin emailed or imported this month between the checks above and the write.
            Logger::error('Payroll import failed', ['error' => $e->getMessage()]);
            $this->failImport('Nothing was saved: this month changed while you were importing. Please try again.');
        }

        Session::flash(
            'success',
            'Imported ' . self::employees(count($result->lines)) . ' for ' . $monthLabel . '. '
            . ($result->missing === []
                ? ''
                : self::employees(count($result->missing)) . ' active in this branch '
                    . (count($result->missing) === 1 ? 'is' : 'are') . ' not in the file — see the list below. ')
            . 'Attach each screenshot, then send.'
        );
        Http::redirect('/salary-notices/' . $runId);
    }

    /** @param array<string,string> $params */
    public function show(array $params): void
    {
        $this->renderRun($this->findRunOr404($params), [], 200);
    }

    /**
     * "Upload sheet: add or update employees" (PAY-4): a further sheet in
     * the import format that adds employees missing from the month and
     * corrects the figures of those already in it, checked all-or-nothing
     * (PayrollSheetUpdate). If applying it would cancel a link already
     * emailed, nothing changes yet: the admin gets a summary of every change
     * and an Apply button (applySheet()). Otherwise it is applied at once.
     *
     * @param array<string,string> $params
     */
    public function uploadSheet(array $params): void
    {
        $run = $this->findRunOr404($params);
        $runId = (int) $run['id'];
        $back = '/salary-notices/' . $runId . '#add-employees';

        $rows = $this->readUploadedSheet($back, 'Choose the .xlsx or .csv file with the employees to add or update.');
        try {
            $plan = PayrollSheetUpdate::plan(
                $rows,
                (new PayrollRun())->branchEmployees(),
                (new PayrollRunLine())->forRun($runId)
            );
        } catch (PDOException $e) {
            Logger::error('Payroll sheet update lookup failed', ['run_id' => $runId, 'error' => $e->getMessage()]);
            $this->failWithFieldErrors(
                ['sheet' => 'Nothing was changed: the file could not be checked just now. Please try again.'],
                $back
            );
        }

        if (!$plan->passes()) {
            Logger::info('Payroll sheet update rejected', ['run_id' => $runId, 'errors' => count($plan->errors)]);
            $this->renderRun($run, $plan->errors, 422);
            return;
        }

        if ($plan->linksCancelled() === 0) {
            $this->applyAndReport($runId, $plan->sheetRows(), $plan->state);
        }

        $monthLabel = PayrollNoticeSender::monthLabel((string) $run['period_month']);
        $this->view('pages/salary-notices/sheet-update', [
            'title' => 'Check the changes — ' . $monthLabel,
            'nav' => 'salary-notices',
            'pageTitle' => 'Check the changes before applying',
            'pageMeta' => 'Salary notices — ' . $monthLabel,
            'run' => $run,
            'plan' => $plan,
            'pendingKey' => PendingSheetUpdate::stash($runId, (int) Auth::id(), $plan->sheetRows(), $plan->state),
            'symbol' => Settings::string('currency_symbol', 'Rs'),
            'expiresInMinutes' => intdiv(PendingSheetUpdate::TTL_SECONDS, 60),
        ]);
    }

    /**
     * Apply the sheet held since its summary page. The key must be this
     * admin's, for this run, unused and unexpired; the rows are validated
     * again against the month as it is now, and if anything changed since
     * the summary, nothing is applied.
     *
     * @param array<string,string> $params
     */
    public function applySheet(array $params): void
    {
        $run = $this->findRunOr404($params);
        $runId = (int) $run['id'];
        $back = '/salary-notices/' . $runId;

        $clean = Validator::make($_POST, ['pending_key' => ['required', 'regex:/^[a-f0-9]{32}$/']]);
        $pending = $clean->fails()
            ? null
            : PendingSheetUpdate::take((string) $clean->validated()['pending_key'], $runId, (int) Auth::id());
        if ($pending === null) {
            Session::flash(
                'error',
                'Nothing was changed: that upload has expired or was already applied. Upload the sheet again.'
            );
            Http::redirect($back . '#add-employees');
        }

        $this->applyAndReport($runId, $pending['rows'], $pending['state']);
    }

    /**
     * The notice email for one line, exactly as it would be sent, with a
     * placeholder in place of the private link. Issues no token, sends
     * nothing, changes nothing.
     *
     * @param array<string,string> $params
     */
    public function previewEmail(array $params): void
    {
        [$run, $line] = $this->findLineOr404($params);
        Http::sendPrivatePageHeaders();

        $sender = PayrollNoticeSender::fromConfig();
        try {
            $message = $sender->preview($line, (string) $run['period_month']);
        } catch (InvalidArgumentException) {
            // No valid recipient or MAIL_FROM: skipReasonFor() below says which.
            $message = null;
        }

        $monthLabel = PayrollNoticeSender::monthLabel((string) $run['period_month']);
        $this->view('pages/salary-notices/preview-email', [
            'title' => 'Preview email — ' . (string) $line['name'],
            'nav' => 'salary-notices',
            'pageTitle' => 'Preview email',
            'pageMeta' => (string) $line['name'] . ' · ' . $monthLabel,
            'run' => $run,
            'line' => $line,
            'message' => $message,
            'skipReason' => $sender->mailIsConfigured()
                ? $sender->skipReasonFor($line)
                : 'email is not configured (MAIL_HOST)',
        ]);
    }

    /**
     * The page an employee opens from their email, for this line, rendered
     * from the same view with its answer forms replaced by a preview banner.
     *
     * @param array<string,string> $params
     */
    public function previewNotice(array $params): void
    {
        [$run, $line] = $this->findLineOr404($params);
        Http::sendPrivatePageHeaders();
        $lineBase = '/salary-notices/' . (int) $run['id'] . '/lines/' . (int) $line['id'];

        $this->view('pages/salary-notice/show', [
            'title' => 'Preview: salary notice for ' . (string) $line['name'],
            'line' => ['note' => $run['note'], 'period_month' => $run['period_month']] + $line,
            'status' => PayrollLineStatus::from((string) $line['status']),
            'monthLabel' => PayrollNoticeSender::monthLabel((string) $run['period_month']),
            'base' => '',
            'screenshotUrl' => $line['screenshot_filename'] === null ? null : $lineBase . '/screenshot',
            'preview' => [
                'name' => (string) $line['name'],
                'back' => '/salary-notices/' . (int) $run['id'] . '#line-' . (int) $line['id'],
                'email' => $lineBase . '/preview-email',
            ],
        ], 'layouts/public');
    }

    /** @param array<string,string> $params */
    public function updateNote(array $params): void
    {
        $run = $this->findRunOr404($params);
        $back = '/salary-notices/' . (int) $run['id'];
        $clean = $this->validate(['note' => self::NOTE_RULE], $back . '#note');

        if (!(new PayrollRun())->updateNote((int) $run['id'], self::noteOrNull($clean['note']))) {
            Session::flash('error', 'The note can no longer change: notices for this month are out with employees.');
            Http::redirect($back);
        }

        Session::flash('success', 'Note saved.');
        Http::redirect($back);
    }

    /** @param array<string,string> $params */
    public function updateLine(array $params): void
    {
        [$run, $line] = $this->findLineOr404($params);
        $back = '/salary-notices/' . (int) $run['id'];
        $anchor = $back . '#line-' . (int) $line['id'];

        // Read by show() so the field errors open under this line only.
        Session::flash('payroll_edit_line', (int) $line['id']);
        $clean = $this->validate(self::FIGURE_RULES, $anchor . '-edit');
        if ((float) $clean['absent_days'] > (float) $clean['working_days']) {
            $this->failWithFieldErrors(
                ['absent_days' => 'Absent cannot be more than working days.'],
                $anchor . '-edit'
            );
        }

        $saved = (new PayrollRunLine())->updateFigures((int) $run['id'], (int) $line['id'], [
            'salary' => (string) $clean['salary'],
            'absent_days' => (string) $clean['absent_days'],
            'working_days' => (string) $clean['working_days'],
        ]);
        if (!$saved) {
            Session::flash(
                'error',
                'This line can no longer be changed: the employee has confirmed it.'
            );
            Http::redirect($anchor);
        }

        Session::flash(
            'success',
            'Figures saved for ' . (string) $line['name'] . '. ' . self::resendReminder($line)
        );
        Http::redirect($anchor);
    }

    /** @param array<string,string> $params */
    public function uploadScreenshot(array $params): void
    {
        [$run, $line] = $this->findLineOr404($params);
        $anchor = '/salary-notices/' . (int) $run['id'] . '#line-' . (int) $line['id'];

        $file = $_FILES['screenshot'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $this->failLine('Choose a PNG, JPEG or WebP image to upload.', $anchor);
        }

        try {
            /** @var array{name:string,type:string,tmp_name:string,error:int,size:int} $file */
            $attached = (new PayrollRunLine())->attachScreenshot((int) $run['id'], (int) $line['id'], $file);
        } catch (RuntimeException $e) {
            // Upload's messages are written for the user; nothing internal is in them.
            $this->failLine($e->getMessage() . ' Use a PNG, JPEG or WebP image.', $anchor);
        }

        if (!$attached) {
            $this->failLine('The screenshot can no longer change: the employee has confirmed this notice.', $anchor);
        }

        Session::flash(
            'success',
            'Screenshot attached for ' . (string) $line['name'] . '.'
            . (PayrollLineStatus::from((string) $line['status']) === PayrollLineStatus::Draft
                ? ''
                : ' ' . self::resendReminder($line))
        );
        Http::redirect($anchor);
    }

    /** @param array<string,string> $params */
    public function screenshot(array $params): never
    {
        [, $line] = $this->findLineOr404($params);
        self::streamScreenshot($line);
    }

    /**
     * Withdraw every notice of the month (PAY-3): every link already emailed
     * stops working, confirmations and queries included, and every line is a
     * draft again for the admin to correct and resend. The prior state of
     * each line is kept in the audit log. Needs a reason; the confirm step is
     * a plain form on the run page.
     *
     * @param array<string,string> $params
     */
    public function withdraw(array $params): void
    {
        $run = $this->findRunOr404($params);
        $back = '/salary-notices/' . (int) $run['id'];
        $clean = $this->validate(['withdraw_reason' => self::WITHDRAW_REASON_RULE], $back . '#withdraw');
        $reason = str_replace("\r\n", "\n", trim((string) $clean['withdraw_reason']));
        $monthLabel = PayrollNoticeSender::monthLabel((string) $run['period_month']);

        try {
            $counts = (new PayrollRun())->withdraw((int) $run['id'], $reason);
        } catch (PDOException $e) {
            Logger::error('Payroll withdraw failed', ['run_id' => (int) $run['id'], 'error' => $e->getMessage()]);
            Session::flash('error', 'Nothing was withdrawn: please try again.');
            Http::redirect($back);
        }

        if ($counts === null) {
            Session::flash(
                'error',
                'There is nothing to withdraw: no notice for ' . $monthLabel . ' is out with employees.'
            );
            Http::redirect($back);
        }

        Logger::info('Salary notices withdrawn', ['run_id' => (int) $run['id']] + $counts);
        Session::flash(
            'success',
            'Withdrew the notices for ' . $monthLabel . ': every emailed link has stopped working'
            . match ($counts['confirmed']) {
                0 => '',
                1 => ', including 1 confirmed response',
                default => ', including ' . $counts['confirmed'] . ' confirmed responses',
            }
            . '. Correct the figures, then resend.'
        );
        Http::redirect($back);
    }

    /** "Send to all not yet sent": draft lines only. @param array<string,string> $params */
    public function sendAll(array $params): void
    {
        $run = $this->findRunOr404($params);
        $drafts = array_values(array_filter(
            (new PayrollRunLine())->forRun((int) $run['id']),
            static fn (array $line): bool => (string) $line['status'] === PayrollLineStatus::Draft->value
        ));

        if ($drafts === []) {
            Session::flash('error', 'Every notice in this run has already been sent.');
            Http::redirect('/salary-notices/' . (int) $run['id']);
        }

        $this->sendAndReport($run, $drafts, '/salary-notices/' . (int) $run['id']);
    }

    /** Send, or send again, one line. @param array<string,string> $params */
    public function sendLine(array $params): void
    {
        [$run, $line] = $this->findLineOr404($params);
        $this->sendAndReport($run, [$line], '/salary-notices/' . (int) $run['id'] . '#line-' . (int) $line['id']);
    }

    /**
     * Stream a line's screenshot inline. Shared with the public notice,
     * which has already resolved the line from its token.
     *
     * @param array<string,mixed> $line
     */
    public static function streamScreenshot(array $line): never
    {
        $filename = (string) ($line['screenshot_filename'] ?? '');
        if ($filename === '') {
            Http::abort(404);
        }

        try {
            $path = PayrollRunLine::screenshotPath($filename);
        } catch (RuntimeException) {
            Logger::error('Payroll line has an invalid screenshot filename', ['line_id' => (int) $line['id']]);
            Http::abort(404);
        }

        if (!is_file($path)) {
            Logger::error('Payroll screenshot missing from storage', ['line_id' => (int) $line['id']]);
            Http::abort(404);
        }

        Http::sendFile(
            $path,
            PayrollRunLine::screenshotMime($filename),
            'attendance-' . (string) $line['employee_code'],
            true
        );
    }

    /**
     * @param array<string,mixed> $run
     * @param list<array<string,mixed>> $lines
     */
    private function sendAndReport(array $run, array $lines, string $back): never
    {
        $sender = PayrollNoticeSender::fromConfig();
        if (!$sender->mailIsConfigured()) {
            Session::flash(
                'error',
                'Nothing was sent: email is not configured (MAIL_HOST is blank in .env). '
                . 'No notice or link has changed.'
            );
            Http::redirect($back);
        }

        $report = $sender->send($lines, (string) $run['period_month']);
        Logger::info('Salary notices sent', [
            'run_id' => (int) $run['id'],
            'sent' => count($report->sent),
            'skipped' => count($report->skipped),
            'failed' => count($report->failed),
        ]);

        Session::flash($report->sent === [] ? 'error' : 'success', $report->summary());
        if ($report->sent !== [] && $report->hasProblems()) {
            // Some went, some did not: the problems must not read as success.
            Session::flash('error', 'Some notices were not sent — see the list above.');
        }
        Http::redirect($back);
    }

    /**
     * Apply the sheet if the month is still as it was when the sheet was
     * checked, and say what changed.
     *
     * @param array<int,list<string>> $rows
     */
    private function applyAndReport(int $runId, array $rows, string $state): never
    {
        $back = '/salary-notices/' . $runId;
        try {
            $applied = (new PayrollRun())->updateFromSheet($runId, $rows, $state);
        } catch (RuntimeException | PDOException $e) {
            Logger::error('Payroll sheet update failed', ['run_id' => $runId, 'error' => $e->getMessage()]);
            $applied = null;
        }

        if ($applied === null) {
            Session::flash(
                'error',
                'Nothing was changed: this month changed while you were checking the file. '
                . 'Upload the sheet again to see the changes as they are now.'
            );
            Http::redirect($back . '#add-employees');
        }

        Logger::info('Payroll sheet applied', [
            'run_id' => $runId,
            'added' => count($applied->added),
            'updated' => count($applied->updated),
            'links_cancelled' => $applied->linksCancelled(),
        ]);
        Session::flash('success', $applied->summary());
        Http::redirect($back);
    }

    /**
     * Render the run page. $sheetErrors are the row-by-row problems of a
     * rejected "add or update" file, listed on the page itself (status 422)
     * like the import form's, so a long list never sits in the session.
     *
     * @param array<string,mixed> $run
     * @param list<array{row:int,employee_code:string,message:string}> $sheetErrors
     */
    private function renderRun(array $run, array $sheetErrors, int $status): void
    {
        $runId = (int) $run['id'];
        $runs = new PayrollRun();
        $lines = (new PayrollRunLine())->forRun($runId);
        $monthLabel = PayrollNoticeSender::monthLabel((string) $run['period_month']);

        http_response_code($status);
        $this->view('pages/salary-notices/show', [
            'title' => 'Salary notices — ' . $monthLabel,
            'nav' => 'salary-notices',
            'pageTitle' => 'Salary notices — ' . $monthLabel,
            'pageMeta' => self::employees(count($lines)),
            'run' => $run,
            'lines' => $lines,
            'noteEditable' => !$runs->hasOutstandingNotices($runId),
            'withdrawnBy' => $run['withdrawn_at'] === null ? null : ($runs->withdrawnByName($runId) ?? 'a former user'),
            'missing' => $runs->activeEmployeesWithoutLine($runId),
            'sheetErrors' => $sheetErrors,
            'editLineId' => (int) (Session::flash('payroll_edit_line') ?? 0),
            'symbol' => Settings::string('currency_symbol', 'Rs'),
            'maxUploadMb' => round((int) Config::get('uploads.max_bytes', 5_242_880) / 1048576, 1),
        ]);
    }

    /**
     * The uploaded sheet's rows, or back to $back with the reason on the
     * `sheet` field.
     *
     * @return array<int,list<string>>
     */
    private function readUploadedSheet(string $back, string $noFileMessage): array
    {
        $fail = function (string $message) use ($back): never {
            $this->failWithFieldErrors(['sheet' => $message], $back);
        };

        $file = $_FILES['sheet'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $fail($noFileMessage);
        }
        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            $fail('The file did not upload completely. Please try again.');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if (!is_uploaded_file($tmp)) {
            Logger::security('Payroll sheet rejected: not an uploaded file', ['ip' => Http::clientIp()]);
            $fail('The file did not upload completely. Please try again.');
        }

        $maxBytes = (int) Config::get('uploads.max_bytes', 5_242_880);
        if ((int) $file['size'] > $maxBytes) {
            $fail('That file is larger than ' . round($maxBytes / 1048576, 1) . ' MB.');
        }

        try {
            return SpreadsheetReader::read($tmp, PayrollImport::MAX_ROWS + 1);
        } catch (UnreadableSpreadsheet $e) {
            $fail($e->getMessage());
        }
    }

    /** "1 employee", "3 employees" */
    private static function employees(int $count): string
    {
        return $count . ' ' . ($count === 1 ? 'employee' : 'employees');
    }

    /** @param list<array{row:int,employee_code:string,message:string}> $importErrors */
    private function renderImportForm(array $importErrors, int $status): void
    {
        http_response_code($status);
        $this->view('pages/salary-notices/import', [
            'title' => 'Import salary sheet',
            'nav' => 'salary-notices',
            'pageTitle' => 'Import salary sheet',
            'pageMeta' => 'One sheet per month. Nothing is saved unless every row is valid.',
            'defaultMonth' => date('Y-m', strtotime('first day of last month')),
            'importErrors' => $importErrors,
            'replaceMonth' => Session::flash('payroll_replace_month'),
            // On a 422 render nothing was redirected, so old() would be
            // stale: the submitted values are passed straight through.
            'submitted' => $status === 422 ? [
                'period_month' => is_string($_POST['period_month'] ?? null) ? $_POST['period_month'] : '',
                'note' => is_string($_POST['note'] ?? null) ? $_POST['note'] : '',
            ] : null,
        ]);
    }

    private function failImport(string $message): never
    {
        $this->failWithFieldErrors(['period_month' => $message], '/salary-notices/new');
    }

    private function failLine(string $message, string $anchor): never
    {
        Session::flash('error', $message);
        Http::redirect($anchor);
    }

    /** @param array<string,string> $messages field => message */
    private function failWithFieldErrors(array $messages, string $back): never
    {
        $old = $_POST;
        unset($old[Csrf::FIELD]);
        Session::set('_old', $old);
        Session::flash('errors', array_map(static fn (string $message): array => [$message], $messages));
        Session::flash('error', reset($messages) ?: 'Please correct the highlighted fields.');
        Http::redirect($back);
    }

    /**
     * What the admin must do next after correcting a line.
     *
     * @param array<string,mixed> $line the line as it was before the change
     */
    private static function resendReminder(array $line): string
    {
        return PayrollLineStatus::from((string) $line['status'])->editCancelsLink()
            ? 'The link already sent was cancelled — send the notice again.'
            : 'Send the notice again so they see it.';
    }

    private static function noteOrNull(mixed $note): ?string
    {
        $note = $note === null ? '' : trim((string) $note);

        return $note === '' ? null : str_replace("\r\n", "\n", $note);
    }

    /**
     * @param array<string,string> $params
     * @return array<string,mixed>
     */
    private function findRunOr404(array $params): array
    {
        $run = (new PayrollRun())->find((int) ($params['id'] ?? 0));
        if ($run === null) {
            Http::abort(404);
        }

        return $run;
    }

    /**
     * @param array<string,string> $params
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function findLineOr404(array $params): array
    {
        $run = $this->findRunOr404($params);
        $line = (new PayrollRunLine())->findInRun((int) $run['id'], (int) ($params['line'] ?? 0));
        if ($line === null) {
            Http::abort(404);
        }

        return [$run, $line];
    }
}
