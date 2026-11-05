<?php

declare(strict_types=1);

namespace App\Controllers;

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
use App\Services\Settings;
use PDOException;
use RuntimeException;

/**
 * Salary notices (PAY-1), admin only (`can:administer`), scoped to the
 * admin's branch: import a month's sheet, attach each employee's
 * attendance screenshot, email each employee their private link, and see
 * who confirmed or queried.
 *
 * Every run id resolves through PayrollRun::find() (branch-scoped) and every
 * line id through PayrollRunLine::findInRun() (branch- and run-scoped), so
 * another branch's run, or a line of another run, is a 404.
 */
final class PayrollController extends Controller
{
    private const PER_PAGE = 24;

    private const MONTH_RULE = 'required|regex:/^20\d{2}-(0[1-9]|1[0-2])$/';
    private const NOTE_RULE = 'nullable|max:5000';

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

        $rows = $this->readUploadedSheet();
        $runs = new PayrollRun();
        $result = PayrollImport::validate($rows, $runs->branchEmployees());

        if (!$result->passes()) {
            Logger::info('Payroll import rejected', ['errors' => count($result->errors)]);
            $this->renderImportForm($result->errors, 422);
            return;
        }

        $existing = $runs->findForMonth($periodMonth);
        if ($existing !== null) {
            if ($runs->hasEmailedLines((int) $existing['id'])) {
                $this->failImport(
                    'Notices for ' . $monthLabel . ' have already been emailed, so its lines cannot be replaced. '
                    . 'Correct individual lines on the run page instead.'
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

        if ($result->missing !== []) {
            Session::flash('payroll_missing', $result->missing);
        }
        Session::flash(
            'success',
            'Imported ' . count($result->lines) . ' ' . (count($result->lines) === 1 ? 'employee' : 'employees')
            . ' for ' . $monthLabel . '. Attach each screenshot, then send.'
        );
        Http::redirect('/salary-notices/' . $runId);
    }

    /** @param array<string,string> $params */
    public function show(array $params): void
    {
        $run = $this->findRunOr404($params);
        $runId = (int) $run['id'];
        $lines = (new PayrollRunLine())->forRun($runId);
        $monthLabel = PayrollNoticeSender::monthLabel((string) $run['period_month']);

        $this->view('pages/salary-notices/show', [
            'title' => 'Salary notices — ' . $monthLabel,
            'nav' => 'salary-notices',
            'pageTitle' => 'Salary notices — ' . $monthLabel,
            'pageMeta' => count($lines) . (count($lines) === 1 ? ' employee' : ' employees'),
            'run' => $run,
            'lines' => $lines,
            'noteEditable' => !(new PayrollRun())->hasEmailedLines($runId),
            'missing' => Session::flash('payroll_missing') ?? [],
            'editLineId' => (int) (Session::flash('payroll_edit_line') ?? 0),
            'symbol' => Settings::string('currency_symbol', 'Rs'),
            'maxUploadMb' => round((int) Config::get('uploads.max_bytes', 5_242_880) / 1048576, 1),
        ]);
    }

    /** @param array<string,string> $params */
    public function updateNote(array $params): void
    {
        $run = $this->findRunOr404($params);
        $back = '/salary-notices/' . (int) $run['id'];
        $clean = $this->validate(['note' => self::NOTE_RULE], $back . '#note');

        if (!(new PayrollRun())->updateNote((int) $run['id'], self::noteOrNull($clean['note']))) {
            Session::flash('error', 'The note can no longer change: notices for this month have been emailed.');
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
     * The uploaded sheet's rows, or back to the form with the reason.
     *
     * @return array<int,list<string>>
     */
    private function readUploadedSheet(): array
    {
        $file = $_FILES['sheet'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $this->failImport('Choose the month\'s .xlsx or .csv file.', 'sheet');
        }
        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            $this->failImport('The file did not upload completely. Please try again.', 'sheet');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if (!is_uploaded_file($tmp)) {
            Logger::security('Payroll sheet rejected: not an uploaded file', ['ip' => Http::clientIp()]);
            $this->failImport('The file did not upload completely. Please try again.', 'sheet');
        }

        $maxBytes = (int) Config::get('uploads.max_bytes', 5_242_880);
        if ((int) $file['size'] > $maxBytes) {
            $this->failImport('That file is larger than ' . round($maxBytes / 1048576, 1) . ' MB.', 'sheet');
        }

        try {
            return SpreadsheetReader::read($tmp, PayrollImport::MAX_ROWS + 1);
        } catch (UnreadableSpreadsheet $e) {
            $this->failImport($e->getMessage(), 'sheet');
        }
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

    private function failImport(string $message, string $field = 'period_month'): never
    {
        $this->failWithFieldErrors([$field => $message], '/salary-notices/new');
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
