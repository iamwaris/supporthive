<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\PayrollController;
use App\Controllers\SalaryNoticeController;
use App\Core\Config;
use App\Core\Database;
use App\Core\RateLimiter;
use App\Models\PayrollRun;
use App\Models\PayrollRunLine;
use App\Services\Payroll\PayrollImport;
use App\Services\Mail\MailTransport;
use App\Services\Mail\NullMailer;
use App\Services\Payroll\PayrollNoticeSender;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\BranchFixture;
use Tests\Support\ControllerActionRunner;
use Tests\Support\RecordingMailTransport;
use Tests\Support\RouteDispatch;

/**
 * Salary notices end to end against the test database (PAY-1).
 *
 * Admin side: a run and its lines belong to one branch, and another
 * branch's run or line id is a 404; a run cannot be re-imported once any
 * notice was emailed. Sending: the email carries a link and no figures;
 * each send issues a fresh token and kills the previous one; a line with
 * no screenshot, or a disabled employee, is skipped; a failed send keeps
 * the old link. Public side: a dead, unknown or expired token shows the
 * neutral page; the employee sees only their own line; an answer is final.
 */
final class PayrollNoticeTest extends TestCase
{
    private const IP = '0.0.0.0';
    private const MONTH = '2026-10-01';
    private const SALARY = '45678.90';

    private int $branchAId;
    private int $branchBId;
    private int $adminAId;
    private int $adminBId;
    private int $annId;
    private int $bobId;
    private int $calId;
    private int $runId;

    /** @var list<string> */
    private array $screenshotFiles = [];

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->cleanUp();

        $this->branchAId = BranchFixture::create('PAY-A');
        $this->branchBId = BranchFixture::create('PAY-B');
        $this->adminAId = $this->insertUser('pay-admin-a@test.local', 'admin', $this->branchAId);
        $this->adminBId = $this->insertUser('pay-admin-b@test.local', 'admin', $this->branchBId);
        $this->annId = $this->insertEmployee('pay-ann@test.local', 'Pay Ann', 'PAY-1');
        $this->bobId = $this->insertEmployee('pay-bob@test.local', 'Pay Bob', 'PAY-2');
        $this->calId = $this->insertEmployee('pay-cal@test.local', 'Pay Cal', 'PAY-3');

        $_SESSION = $this->sessionA();
        $runs = new PayrollRun();
        $result = PayrollImport::validate([
            1 => ['EmpID', 'Salary', 'Absent', 'WorkingDays'],
            2 => ['PAY-1', self::SALARY, '1', '26'],
            3 => ['PAY-2', '30000', '0', '26'],
            4 => ['PAY-3', '20000', '0', '26'],
        ], $runs->branchEmployees());
        self::assertTrue($result->passes());
        $this->runId = $runs->import(self::MONTH, 'Paid on the 5th.', $result->lines);

        // Ann and Cal have screenshots; Bob does not.
        foreach ([$this->annId, $this->calId] as $userId) {
            $this->attachFakeScreenshot($this->lineIdFor($userId));
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        foreach ($this->screenshotFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $_SESSION = [];
    }

    private function cleanUp(): void
    {
        $db = Database::instance();
        foreach (['payroll_run_lines', 'payroll_runs', 'audit_log', 'employee_profiles'] as $table) {
            $db->run(
                'DELETE t FROM ' . $table . ' t JOIN branches b ON b.id = t.branch_id WHERE b.name LIKE :n',
                ['n' => 'PAY-%']
            );
        }
        $db->run(
            'DELETE a FROM audit_log a JOIN users u ON u.id = a.user_id WHERE u.email LIKE :e',
            ['e' => 'pay-%@test.local']
        );
        $db->delete('users', 'email LIKE :e', ['e' => 'pay-%@test.local']);
        $db->delete('branches', 'name LIKE :n', ['n' => 'PAY-%']);
        foreach (['view', 'respond'] as $kind) {
            RateLimiter::clear('salary-notice-' . $kind . '-ip:' . self::IP);
        }
    }

    private function insertUser(string $email, string $role, int $branchId, string $name = ''): int
    {
        return Database::instance()->insert('users', [
            'name' => $name !== '' ? $name : 'Pay ' . $email,
            'email' => $email,
            'password_hash' => password_hash('unused', PASSWORD_DEFAULT),
            'role' => $role,
            'branch_id' => $branchId,
            'status' => 'active',
        ]);
    }

    private function insertEmployee(string $email, string $name, string $code): int
    {
        $id = $this->insertUser($email, 'employee', $this->branchAId, $name);
        Database::instance()->insert('employee_profiles', [
            'user_id' => $id,
            'branch_id' => $this->branchAId,
            'employee_code' => $code,
            'designation' => 'Fronter',
        ]);

        return $id;
    }

    /** @return array<string,int> */
    private function sessionA(): array
    {
        return ['_auth_user_id' => $this->adminAId, '_active_branch_id' => $this->branchAId];
    }

    /** @return array<string,int> */
    private function sessionB(): array
    {
        return ['_auth_user_id' => $this->adminBId, '_active_branch_id' => $this->branchBId];
    }

    private function lineIdFor(int $userId): int
    {
        return (int) Database::instance()->value(
            'SELECT id FROM payroll_run_lines WHERE run_id = :run AND user_id = :user',
            ['run' => $this->runId, 'user' => $userId]
        );
    }

    /** @return array<string,mixed> */
    private function line(int $userId): array
    {
        return (array) Database::instance()->first(
            'SELECT * FROM payroll_run_lines WHERE run_id = :run AND user_id = :user',
            ['run' => $this->runId, 'user' => $userId]
        );
    }

    private function attachFakeScreenshot(int $lineId): void
    {
        $filename = bin2hex(random_bytes(16)) . '.png';
        $path = PayrollRunLine::screenshotPath($filename);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        // A 1x1 transparent PNG.
        file_put_contents($path, (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
        ));
        $this->screenshotFiles[] = $path;
        self::assertTrue((new PayrollRunLine())->useStoredScreenshot($this->runId, $lineId, $filename));
    }

    private function sender(MailTransport $mail): PayrollNoticeSender
    {
        return new PayrollNoticeSender(
            new PayrollRunLine(),
            $mail,
            'payroll@test.local',
            'Payroll',
            'https://app.test/base',
            'Test Co'
        );
    }

    /**
     * Send these employees' lines (in branch A's session) and return each
     * one's token, read back from the email that went out.
     *
     * @param list<int> $userIds
     * @return array<int,string> user id => token
     */
    private function send(array $userIds, ?RecordingMailTransport $mail = null): array
    {
        $_SESSION = $this->sessionA();
        $mail ??= new RecordingMailTransport();
        $rows = array_values(array_filter(
            (new PayrollRunLine())->forRun($this->runId),
            static fn (array $row): bool => in_array((int) $row['user_id'], $userIds, true)
        ));
        $this->sender($mail)->send($rows, self::MONTH);
        $_SESSION = [];

        $tokens = [];
        foreach ($mail->sent as $message) {
            self::assertSame(1, preg_match('#/salary-notice/([a-f0-9]{64})#', $message->textBody, $m));
            $userId = (int) Database::instance()->value(
                'SELECT id FROM users WHERE email = :e',
                ['e' => $message->to[0]]
            );
            $tokens[$userId] = $m[1];
        }

        return $tokens;
    }

    /** @return array{status:int,body:string,session:array<string,mixed>} */
    private function viewNotice(string $token): array
    {
        return ControllerActionRunner::run(
            SalaryNoticeController::class,
            'show',
            [],
            [],
            [],
            'GET',
            ['token' => $token]
        );
    }

    public function testImportCreatesDraftLinesInTheAdminsBranch(): void
    {
        $line = $this->line($this->annId);

        self::assertSame('draft', $line['status']);
        self::assertSame($this->branchAId, (int) $line['branch_id']);
        self::assertSame(self::SALARY, (string) $line['salary']);
        self::assertSame('PAY-1', $line['employee_code']);
    }

    public function testEmailCarriesThePrivateLinkButNoFigures(): void
    {
        $mail = new RecordingMailTransport();
        $tokens = $this->send([$this->annId], $mail);

        self::assertCount(1, $mail->sent);
        $message = $mail->sent[0];
        self::assertSame(['pay-ann@test.local'], $message->to);
        self::assertSame('Your salary notice for October 2026', $message->subject);
        $link = 'https://app.test/base/salary-notice/' . $tokens[$this->annId];
        self::assertStringContainsString($link, $message->textBody);
        self::assertStringNotContainsString('45678', $message->textBody);
        self::assertStringNotContainsString('45,678', $message->textBody);

        $line = $this->line($this->annId);
        self::assertSame('sent', $line['status']);
        self::assertSame(hash('sha256', $tokens[$this->annId]), $line['token_hash'], 'only the hash is stored');
        self::assertNotNull($line['emailed_at']);
    }

    public function testLinesWithoutScreenshotOrForADisabledEmployeeAreSkipped(): void
    {
        Database::instance()->update('users', ['status' => 'suspended'], 'id = :id', ['id' => $this->calId]);
        $_SESSION = $this->sessionA();
        $mail = new RecordingMailTransport();

        $report = $this->sender($mail)->send((new PayrollRunLine())->forRun($this->runId), self::MONTH);

        self::assertSame(['Pay Ann'], $report->sent);
        self::assertSame([
            ['name' => 'Pay Bob', 'reason' => 'no attendance screenshot yet'],
            ['name' => 'Pay Cal', 'reason' => 'employee is disabled'],
        ], $report->skipped);
        self::assertSame('draft', $this->line($this->bobId)['status']);
    }

    public function testAFailedSendKeepsTheLineAndItsPreviousLink(): void
    {
        $first = $this->send([$this->annId])[$this->annId];

        $failing = new RecordingMailTransport(true);
        $_SESSION = $this->sessionA();
        $annLine = (new PayrollRunLine())->findInRun($this->runId, $this->lineIdFor($this->annId));
        $report = $this->sender($failing)->send([$annLine], self::MONTH);

        self::assertSame(1, $failing->attempts);
        self::assertCount(1, $report->failed);
        self::assertStringNotContainsString(
            '550',
            $report->summary(),
            'the server reply goes to the log, not the browser'
        );
        self::assertNotNull(PayrollRunLine::findByToken($first), 'the link already sent still works');
    }

    public function testResendKillsTheOldTokenAndKeepsTheQueryInTheAuditLog(): void
    {
        $first = $this->send([$this->annId])[$this->annId];
        self::assertTrue(PayrollRunLine::respond($first, 'query', null, 'Absent should be 0.', '10.0.0.1', 'UA'));

        $second = $this->send([$this->annId])[$this->annId];

        self::assertNotSame($first, $second);
        self::assertNull(PayrollRunLine::findByToken($first));
        $line = PayrollRunLine::findByToken($second);
        self::assertNotNull($line);
        self::assertSame('sent', $line['status']);
        self::assertNull($line['query_text']);

        $meta = (string) Database::instance()->value(
            "SELECT meta FROM audit_log WHERE action = 'payroll_line.resent' AND entity_id = :id",
            ['id' => $this->lineIdFor($this->annId)]
        );
        self::assertStringContainsString('Absent should be 0.', $meta);
    }

    public function testAResponseIsFinalForThatSend(): void
    {
        $token = $this->send([$this->annId])[$this->annId];

        self::assertTrue(PayrollRunLine::respond($token, 'confirmed', 'Pay Ann', null, '10.0.0.1', 'Agent/1'));
        self::assertFalse(PayrollRunLine::respond($token, 'confirmed', 'Someone Else', null, '10.0.0.2', 'x'));
        self::assertFalse(PayrollRunLine::respond($token, 'query', null, 'changed my mind', '10.0.0.2', 'x'));

        $line = $this->line($this->annId);
        self::assertSame('confirmed', $line['status']);
        self::assertSame('Pay Ann', $line['signed_name']);
        self::assertSame('10.0.0.1', $line['response_ip']);
        self::assertSame('Agent/1', $line['response_user_agent']);

        // A confirmed notice is closed: it is never sent again.
        $_SESSION = $this->sessionA();
        $report = $this->sender(new RecordingMailTransport())->send(
            [(new PayrollRunLine())->findInRun($this->runId, $this->lineIdFor($this->annId))],
            self::MONTH
        );
        self::assertSame([['name' => 'Pay Ann', 'reason' => 'already confirmed']], $report->skipped);
    }

    public function testAnExpiredTokenIsDead(): void
    {
        $token = $this->send([$this->annId])[$this->annId];
        Database::instance()->update(
            'payroll_run_lines',
            ['token_expires_at' => date('Y-m-d H:i:s', time() - 60)],
            'id = :id',
            ['id' => $this->lineIdFor($this->annId)]
        );

        self::assertNull(PayrollRunLine::findByToken($token));
        self::assertFalse(PayrollRunLine::respond($token, 'confirmed', 'Pay Ann', null, '1.1.1.1', 'x'));
    }

    private function auditCount(string $action, int $lineId): int
    {
        return (int) Database::instance()->value(
            'SELECT COUNT(*) FROM audit_log WHERE action = :action AND entity_id = :id',
            ['action' => $action, 'id' => $lineId]
        );
    }

    /**
     * Regression (review of PAY-1): with MAIL_HOST blank the transport is
     * NullMailer, which "accepts" by logging. That must not mark a line
     * sent with a link nobody received, nor cancel a link that was.
     */
    public function testWithMailNotConfiguredNothingIsSentAndNothingChanges(): void
    {
        $annLineId = $this->lineIdFor($this->annId);
        $first = $this->send([$this->annId])[$this->annId];
        self::assertTrue(PayrollRunLine::respond($first, 'query', null, 'Please check.', '10.0.0.1', 'UA'));
        $auditBefore = (int) Database::instance()->value('SELECT COUNT(*) FROM audit_log');
        $annBefore = $this->line($this->annId);

        $_SESSION = $this->sessionA();
        $sender = $this->sender(new NullMailer());
        self::assertFalse($sender->mailIsConfigured());
        $report = $sender->send((new PayrollRunLine())->forRun($this->runId), self::MONTH);

        self::assertSame([], $report->sent);
        self::assertSame([], $report->failed);
        self::assertCount(3, $report->skipped);
        foreach ($report->skipped as $skip) {
            self::assertSame('Email is not configured (MAIL_HOST)', $skip['reason']);
        }
        self::assertSame($annBefore, $this->line($this->annId), 'the queried line is untouched');
        self::assertNotNull(PayrollRunLine::findByToken($first), 'the link already sent still works');
        self::assertSame('draft', $this->line($this->calId)['status']);
        self::assertNull($this->line($this->calId)['token_hash']);
        self::assertNull($this->line($this->calId)['emailed_at']);
        self::assertSame($auditBefore, (int) Database::instance()->value('SELECT COUNT(*) FROM audit_log'));
        self::assertSame(0, $this->auditCount('payroll_line.resent', $annLineId));
    }

    public function testCorrectingFiguresOfASentLineCancelsItsLinkForAResend(): void
    {
        $token = $this->send([$this->annId])[$this->annId];
        $_SESSION = $this->sessionA();

        self::assertTrue((new PayrollRunLine())->updateFigures($this->runId, $this->lineIdFor($this->annId), [
            'salary' => '40000.00',
            'absent_days' => '0.0',
            'working_days' => '26.0',
        ]));

        $line = $this->line($this->annId);
        self::assertSame('40000.00', (string) $line['salary']);
        self::assertSame('draft', $line['status']);
        self::assertNull($line['token_hash']);
        self::assertNull(PayrollRunLine::findByToken($token));
        self::assertSame(404, $this->viewNotice($token)['status'], 'the old link shows the neutral page');
        self::assertFalse(PayrollRunLine::respond($token, 'confirmed', 'Pay Ann', null, '1.1.1.1', 'x'));
        self::assertSame(1, $this->auditCount('payroll_line.link_cancelled', $this->lineIdFor($this->annId)));

        $resent = $this->send([$this->annId])[$this->annId];
        self::assertSame('sent', PayrollRunLine::findByToken($resent)['status'] ?? null);
    }

    public function testReplacingTheScreenshotOfASentLineCancelsItsLink(): void
    {
        $token = $this->send([$this->annId])[$this->annId];
        $_SESSION = $this->sessionA();

        $this->attachFakeScreenshot($this->lineIdFor($this->annId));

        self::assertSame('draft', $this->line($this->annId)['status']);
        self::assertNull(PayrollRunLine::findByToken($token));
        self::assertSame(1, $this->auditCount('payroll_line.link_cancelled', $this->lineIdFor($this->annId)));
    }

    public function testEditingADraftOrQueriedLineKeepsItsStatus(): void
    {
        $token = $this->send([$this->annId])[$this->annId];
        self::assertTrue(PayrollRunLine::respond($token, 'query', null, 'Absent is wrong.', '1.1.1.1', 'x'));
        $_SESSION = $this->sessionA();
        $lines = new PayrollRunLine();
        $figures = ['salary' => '31000.00', 'absent_days' => '0.0', 'working_days' => '26.0'];

        self::assertTrue($lines->updateFigures($this->runId, $this->lineIdFor($this->annId), $figures));
        self::assertTrue($lines->updateFigures($this->runId, $this->lineIdFor($this->bobId), $figures));

        self::assertSame('query', $this->line($this->annId)['status']);
        self::assertSame('draft', $this->line($this->bobId)['status']);
        self::assertSame(0, $this->auditCount('payroll_line.link_cancelled', $this->lineIdFor($this->annId)));
    }

    public function testAConfirmedLineStaysLocked(): void
    {
        $token = $this->send([$this->annId])[$this->annId];
        self::assertTrue(PayrollRunLine::respond($token, 'confirmed', 'Pay Ann', null, '1.1.1.1', 'x'));
        $_SESSION = $this->sessionA();
        $lines = new PayrollRunLine();
        $lineId = $this->lineIdFor($this->annId);

        self::assertFalse($lines->updateFigures($this->runId, $lineId, [
            'salary' => '1.00',
            'absent_days' => '0.0',
            'working_days' => '1.0',
        ]));
        $filename = bin2hex(random_bytes(16)) . '.png';
        self::assertFalse($lines->useStoredScreenshot($this->runId, $lineId, $filename));

        $line = $this->line($this->annId);
        self::assertSame('confirmed', $line['status']);
        self::assertSame(self::SALARY, (string) $line['salary']);
        self::assertNotNull(PayrollRunLine::findByToken($token));
    }

    public function testAFailedAnswerIsNotKeptToPrefillAnotherNotice(): void
    {
        $token = $this->send([$this->annId])[$this->annId];

        $result = ControllerActionRunner::run(
            SalaryNoticeController::class,
            'show',
            ['_old' => ['signed_name' => 'Typed On Another Notice']],
            [],
            [],
            'GET',
            ['token' => $token]
        );

        self::assertSame(200, $result['status']);
        self::assertStringContainsString('Typed On Another Notice', $result['body'], 'shown once, on this render');
        self::assertArrayNotHasKey('_old', $result['session']);
    }

    /**
     * Regression (PAY-1 hotfix): every import was a 500 because the month
     * rule's regex was split on its `|`. A valid month with no file must now
     * reach the file check and come back with that field's own error.
     */
    public function testImportWithAValidMonthAndNoFileAsksForTheFile(): void
    {
        $result = ControllerActionRunner::run(
            PayrollController::class,
            'import',
            $this->sessionA(),
            ['period_month' => '2026-10', 'note' => '']
        );

        self::assertSame(302, $result['status']);
        $errors = $result['session']['_flash']['errors'] ?? [];
        self::assertArrayNotHasKey('period_month', $errors);
        self::assertSame(["Choose the month's .xlsx or .csv file."], $errors['sheet'] ?? null);
    }

    public function testRunCannotBeReplacedOnceANoticeWasEmailed(): void
    {
        $this->send([$this->annId]);
        $_SESSION = $this->sessionA();

        $this->expectException(RuntimeException::class);
        (new PayrollRun())->import(self::MONTH, null, []);
    }

    public function testAnotherBranchsRunAndLineAre404(): void
    {
        $lineId = (string) $this->lineIdFor($this->annId);

        $own = ControllerActionRunner::run(
            PayrollController::class,
            'show',
            $this->sessionA(),
            [],
            [],
            'GET',
            ['id' => (string) $this->runId]
        );
        self::assertSame(200, $own['status']);
        self::assertStringContainsString('Pay Ann', $own['body']);

        foreach (['show', 'screenshot', 'sendLine'] as $action) {
            $result = ControllerActionRunner::run(
                PayrollController::class,
                $action,
                $this->sessionB(),
                [],
                [],
                $action === 'sendLine' ? 'POST' : 'GET',
                ['id' => (string) $this->runId, 'line' => $lineId]
            );
            self::assertSame(404, $result['status'], $action);
        }

        // A real line id under a run id it does not belong to.
        $wrongRun = ControllerActionRunner::run(
            PayrollController::class,
            'screenshot',
            $this->sessionA(),
            [],
            [],
            'GET',
            ['id' => (string) ($this->runId + 100000), 'line' => $lineId]
        );
        self::assertSame(404, $wrongRun['status']);
        self::assertSame('draft', $this->line($this->annId)['status']);
    }

    public function testUnknownMalformedAndReplacedTokensShowTheSameNeutralPage(): void
    {
        $replaced = $this->send([$this->annId])[$this->annId];
        $this->send([$this->annId]);

        foreach ([str_repeat('a', 64), 'not-a-token', $replaced] as $token) {
            $result = $this->viewNotice($token);
            self::assertSame(404, $result['status']);
            self::assertStringContainsString('This link is no longer active', $result['body']);
            self::assertStringNotContainsString('Pay Ann', $result['body']);
        }
    }

    public function testTheNoticeShowsOnlyItsOwnLine(): void
    {
        $tokens = $this->send([$this->annId, $this->calId]);

        $result = $this->viewNotice($tokens[$this->annId]);

        self::assertSame(200, $result['status']);
        self::assertStringContainsString('Pay Ann', $result['body']);
        self::assertStringContainsString('45,678.90', $result['body']);
        self::assertStringContainsString('Paid on the 5th.', $result['body']);
        self::assertStringContainsString('October 2026', $result['body']);
        self::assertStringNotContainsString('Pay Cal', $result['body']);
        self::assertStringNotContainsString('20,000', $result['body']);
    }

    public function testConfirmingThroughTheControllerRecordsTheTypedName(): void
    {
        $token = $this->send([$this->annId])[$this->annId];

        $result = ControllerActionRunner::run(
            SalaryNoticeController::class,
            'confirm',
            [],
            ['signed_name' => '  Pay Ann Full  ', 'status' => 'query'],
            [],
            'POST',
            ['token' => $token]
        );

        self::assertSame(302, $result['status']);
        $line = $this->line($this->annId);
        self::assertSame('confirmed', $line['status']);
        self::assertSame('Pay Ann Full', $line['signed_name']);
        self::assertNull($line['query_text']);
        self::assertSame(1, (int) Database::instance()->value(
            "SELECT COUNT(*) FROM audit_log
             WHERE action = 'payroll_line.confirmed' AND entity_id = :id AND user_id IS NULL",
            ['id' => $this->lineIdFor($this->annId)]
        ));
    }

    public function testAQueryNeedsAComment(): void
    {
        $token = $this->send([$this->annId])[$this->annId];

        $result = ControllerActionRunner::run(
            SalaryNoticeController::class,
            'query',
            [],
            ['query_text' => ''],
            [],
            'POST',
            ['token' => $token]
        );

        self::assertSame(302, $result['status']);
        self::assertArrayHasKey('query_text', $result['session']['_flash']['errors'] ?? []);
        self::assertSame('sent', $this->line($this->annId)['status']);
    }

    /** @return array{status:int,body:string,session:array<string,mixed>} */
    private function runAs(string $action, array $session, int $runId, ?int $lineId = null): array
    {
        return ControllerActionRunner::run(
            PayrollController::class,
            $action,
            $session,
            [],
            [],
            $action === 'addLines' ? 'POST' : 'GET',
            ['id' => (string) $runId] + ($lineId === null ? [] : ['line' => (string) $lineId])
        );
    }

    /** Every line of the run, plus the audit log size: what a read-only action must leave as it was. */
    private function stateSnapshot(): array
    {
        return [
            Database::instance()->all(
                'SELECT * FROM payroll_run_lines WHERE run_id = :run ORDER BY id',
                ['run' => $this->runId]
            ),
            (int) Database::instance()->value('SELECT COUNT(*) FROM audit_log'),
        ];
    }

    /**
     * PAY-2: the preview is built by the same method as the real email, so
     * the two differ only in the link, and building it issues no token.
     */
    public function testEmailPreviewIsTheSentEmailExceptForTheLinkAndChangesNothing(): void
    {
        $_SESSION = $this->sessionA();
        $annLine = (new PayrollRunLine())->findInRun($this->runId, $this->lineIdFor($this->annId));
        self::assertNotNull($annLine);
        $before = $this->stateSnapshot();

        $sender = $this->sender(new RecordingMailTransport());
        $preview = $sender->preview($annLine, self::MONTH);
        self::assertSame($before, $this->stateSnapshot(), 'previewing issues no token and writes nothing');
        self::assertNull($sender->skipReasonFor($annLine));

        $mail = new RecordingMailTransport();
        $token = $this->send([$this->annId], $mail)[$this->annId];
        $sent = $mail->sent[0];

        $placeholder = 'https://app.test/base/salary-notice/' . PayrollNoticeSender::PREVIEW_LINK_TOKEN;
        self::assertStringContainsString($placeholder, $preview->textBody);
        self::assertSame(
            str_replace('https://app.test/base/salary-notice/' . $token, $placeholder, $sent->textBody),
            $preview->textBody
        );
        self::assertSame($sent->subject, $preview->subject);
        self::assertSame($sent->to, $preview->to);
        self::assertSame($sent->fromAddress, $preview->fromAddress);
        self::assertSame($sent->fromName, $preview->fromName);
        self::assertSame(0, preg_match('#/salary-notice/[a-f0-9]{64}#', $preview->textBody));
    }

    public function testBothPreviewPagesRenderForTheAdminAndChangeNothing(): void
    {
        $annLineId = $this->lineIdFor($this->annId);
        $before = $this->stateSnapshot();

        $email = $this->runAs('previewEmail', $this->sessionA(), $this->runId, $annLineId);
        self::assertSame(200, $email['status']);
        self::assertStringContainsString('Pay Ann', $email['body']);
        self::assertStringContainsString('Preview notice page', $email['body']);
        self::assertSame(0, preg_match('#/salary-notice/[a-f0-9]{64}#', $email['body']));

        $page = $this->runAs('previewNotice', $this->sessionA(), $this->runId, $annLineId);
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Preview — this is what Pay Ann will see', $page['body']);
        self::assertStringContainsString('45,678.90', $page['body']);
        self::assertStringContainsString('Paid on the 5th.', $page['body']);
        self::assertStringContainsString(
            '/salary-notices/' . $this->runId . '/lines/' . $annLineId . '/screenshot',
            $page['body'],
            'the screenshot comes through the admin route'
        );
        self::assertStringNotContainsString('<form', $page['body'], 'no live form on the preview');
        self::assertStringNotContainsString('action=', $page['body']);
        self::assertStringNotContainsString('/confirm', $page['body']);
        self::assertStringNotContainsString('Pay Cal', $page['body']);

        // Bob has no screenshot yet: the preview says so instead of a broken image.
        $bob = $this->runAs('previewNotice', $this->sessionA(), $this->runId, $this->lineIdFor($this->bobId));
        self::assertSame(200, $bob['status']);
        self::assertStringContainsString('No screenshot attached yet', $bob['body']);

        self::assertSame($before, $this->stateSnapshot());
    }

    public function testPreviewsAndAddingForAnotherBranchAre404(): void
    {
        $annLineId = $this->lineIdFor($this->annId);
        $before = $this->stateSnapshot();

        foreach (['previewEmail', 'previewNotice'] as $action) {
            $other = $this->runAs($action, $this->sessionB(), $this->runId, $annLineId);
            self::assertSame(404, $other['status'], $action);
            self::assertSame(
                404,
                $this->runAs($action, $this->sessionA(), $this->runId + 100000, $annLineId)['status'],
                $action . ' with a line under the wrong run'
            );
        }
        self::assertSame(404, $this->runAs('addLines', $this->sessionB(), $this->runId)['status']);
        self::assertSame($before, $this->stateSnapshot());
    }

    public function testNonAdminsAreRefusedThePreviewAndAddRoutes(): void
    {
        $partnerId = $this->insertUser('pay-partner@test.local', 'partner', $this->branchAId);
        $lineBase = '/salary-notices/' . $this->runId . '/lines/' . $this->lineIdFor($this->annId);
        $requests = [
            ['GET', $lineBase . '/preview-email'],
            ['GET', $lineBase . '/preview'],
            ['POST', '/salary-notices/' . $this->runId . '/lines'],
        ];

        foreach ([$this->annId, $partnerId] as $userId) {
            foreach ($requests as [$method, $uri]) {
                $result = ControllerActionRunner::run(
                    RouteDispatch::class,
                    'dispatch',
                    [
                        '_auth_user_id' => $userId,
                        '_active_branch_id' => $this->branchAId,
                        '_csrf_token' => 'pay-csrf',
                        '_csrf_issued_at' => time(),
                    ],
                    ['_csrf' => 'pay-csrf'],
                    [],
                    $method,
                    ['uri' => $uri]
                );
                self::assertSame(403, $result['status'], $method . ' ' . $uri . ' as user ' . $userId);
                self::assertStringNotContainsString('45,678', $result['body']);
            }
        }
    }

    /**
     * PAY-2: employees missing from the first file can be added after other
     * notices went out, without touching any existing line.
     */
    public function testAddingEmployeesAfterOthersWereSentLeavesExistingLinesAlone(): void
    {
        $deeId = $this->insertEmployee('pay-dee@test.local', 'Pay Dee', 'PAY-4');
        $token = $this->send([$this->annId])[$this->annId];

        $page = $this->runAs('show', $this->sessionA(), $this->runId);
        self::assertStringContainsString('Pay Dee', $page['body'], 'listed as missing from this month');
        self::assertStringContainsString('href="#add-employees"', $page['body']);

        $_SESSION = $this->sessionA();
        $before = Database::instance()->all(
            'SELECT * FROM payroll_run_lines WHERE run_id = :run ORDER BY id',
            ['run' => $this->runId]
        );
        $inRun = array_map(static fn (array $row): int => (int) $row['user_id'], $before);
        $result = PayrollImport::validate([
            1 => ['EmpID', 'Salary', 'Absent', 'WorkingDays'],
            2 => ['pay-4', '25000', '2', '26'],
        ], (new PayrollRun())->branchEmployees(), $inRun);
        self::assertTrue($result->passes());

        (new PayrollRun())->appendLines($this->runId, $result->lines);

        $dee = $this->line($deeId);
        self::assertSame('draft', $dee['status']);
        self::assertSame('25000.00', (string) $dee['salary']);
        self::assertNull($dee['token_hash']);
        self::assertSame($before, Database::instance()->all(
            'SELECT * FROM payroll_run_lines WHERE run_id = :run AND user_id <> :dee ORDER BY id',
            ['run' => $this->runId, 'dee' => $deeId]
        ), 'existing lines are untouched');
        self::assertNotNull(PayrollRunLine::findByToken($token), 'the link already sent still works');
        $meta = (string) Database::instance()->value(
            "SELECT meta FROM audit_log WHERE action = 'payroll_run.lines_added' AND entity_id = :id",
            ['id' => $this->runId]
        );
        self::assertStringContainsString('PAY-4', $meta);

        $after = $this->runAs('show', $this->sessionA(), $this->runId);
        self::assertStringNotContainsString('not in this month', $after['body']);
    }

    public function testAddingAnEmployeeAlreadyInTheRunIsRejectedAndNothingIsSaved(): void
    {
        $deeId = $this->insertEmployee('pay-dee@test.local', 'Pay Dee', 'PAY-4');
        $_SESSION = $this->sessionA();
        $runs = new PayrollRun();
        $inRun = array_map(
            static fn (array $row): int => (int) $row['user_id'],
            (new PayrollRunLine())->forRun($this->runId)
        );

        $result = PayrollImport::validate([
            1 => ['EmpID', 'Salary', 'Absent', 'WorkingDays'],
            2 => ['PAY-4', '25000', '0', '26'],
            3 => ['PAY-1', '1', '0', '26'],
        ], $runs->branchEmployees(), $inRun);

        self::assertFalse($result->passes());
        self::assertSame([], $result->lines, 'all or nothing');
        self::assertSame(
            [[
                'row' => 3,
                'employee_code' => 'PAY-1',
                'message' => 'Employee PAY-1 is already in this month — edit their row instead.',
            ]],
            $result->errors
        );

        // The same employee slipping past the check (a concurrent upload) is
        // refused under the run lock, and the rest of that append rolls back.
        $countBefore = count($runs->activeEmployeesWithoutLine($this->runId));
        try {
            $runs->appendLines($this->runId, [
                ['user_id' => $deeId, 'name' => 'Pay Dee', 'employee_code' => 'PAY-4',
                    'salary' => '1.00', 'absent_days' => '0.0', 'working_days' => '26.0'],
                ['user_id' => $this->annId, 'name' => 'Pay Ann', 'employee_code' => 'PAY-1',
                    'salary' => '1.00', 'absent_days' => '0.0', 'working_days' => '26.0'],
            ]);
            self::fail('appending an employee already in the run must throw');
        } catch (RuntimeException) {
        }
        self::assertSame([], $this->line($deeId));
        self::assertSame(self::SALARY, (string) $this->line($this->annId)['salary']);
        self::assertSame($countBefore, count($runs->activeEmployeesWithoutLine($this->runId)));
        self::assertSame(0, (int) Database::instance()->value(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'payroll_run.lines_added' AND entity_id = :id",
            ['id' => $this->runId]
        ));
    }

    public function testAddingWithNoFileReturnsTheFieldErrorNotA500(): void
    {
        $result = $this->runAs('addLines', $this->sessionA(), $this->runId);

        self::assertSame(302, $result['status']);
        self::assertSame(
            ['Choose the .xlsx or .csv file with the employees to add.'],
            $result['session']['_flash']['errors']['sheet'] ?? null
        );
        self::assertSame(3, (int) Database::instance()->value(
            'SELECT COUNT(*) FROM payroll_run_lines WHERE run_id = :run',
            ['run' => $this->runId]
        ));
    }

    /**
     * PAY-3: send Ann, Bob and Cal, then Ann confirms, Cal queries and Bob
     * stays awaiting a response. Returns each one's token.
     *
     * @return array<int,string>
     */
    private function sendAllAndRespond(): array
    {
        $_SESSION = $this->sessionA();
        $this->attachFakeScreenshot($this->lineIdFor($this->bobId));
        $tokens = $this->send([$this->annId, $this->bobId, $this->calId]);
        self::assertTrue(
            PayrollRunLine::respond($tokens[$this->annId], 'confirmed', 'Pay Ann Signed', null, '10.1.1.1', 'UA')
        );
        self::assertTrue(
            PayrollRunLine::respond($tokens[$this->calId], 'query', null, 'Absent is wrong.', '10.1.1.2', 'UA')
        );

        return $tokens;
    }

    /** @return array{confirmed:int,query:int,sent:int,draft:int}|null */
    private function withdraw(string $reason = 'Imported the wrong sheet.'): ?array
    {
        $_SESSION = $this->sessionA();
        $counts = (new PayrollRun())->withdraw($this->runId, $reason);
        $_SESSION = [];

        return $counts;
    }

    /**
     * @param array<string,mixed> $session
     * @return array{status:int,body:string,session:array<string,mixed>}
     */
    private function postWithdraw(array $session, string $reason, ?int $runId = null): array
    {
        return ControllerActionRunner::run(
            PayrollController::class,
            'withdraw',
            $session,
            ['withdraw_reason' => $reason],
            [],
            'POST',
            ['id' => (string) ($runId ?? $this->runId)]
        );
    }

    public function testWithdrawResetsConfirmedSentAndQueriedLinesAndKillsTheirLinks(): void
    {
        $tokens = $this->sendAllAndRespond();
        $users = [$this->annId, $this->bobId, $this->calId];
        $screenshots = array_map(fn (int $id): mixed => $this->line($id)['screenshot_filename'], $users);

        self::assertSame(['confirmed' => 1, 'query' => 1, 'sent' => 1, 'draft' => 0], $this->withdraw());

        $cleared = [
            'token_hash',
            'token_expires_at',
            'signed_name',
            'responded_at',
            'response_ip',
            'response_user_agent',
            'query_text',
        ];
        foreach ($users as $i => $userId) {
            $line = $this->line($userId);
            self::assertSame('draft', $line['status']);
            foreach ($cleared as $column) {
                self::assertNull($line[$column], $column);
            }
            self::assertSame($screenshots[$i], $line['screenshot_filename'], 'the screenshot is kept');
            self::assertNotNull($line['emailed_at'], 'when it was emailed stays as history');
            self::assertNull(PayrollRunLine::findByToken($tokens[$userId]));
            self::assertFalse(PayrollRunLine::respond($tokens[$userId], 'confirmed', 'Late', null, '1.1.1.1', 'x'));
        }

        // The old links of a confirmed line (Ann) and of a sent line (Bob) both get the neutral page.
        foreach ([$this->annId, $this->bobId] as $userId) {
            $page = $this->viewNotice($tokens[$userId]);
            self::assertSame(404, $page['status']);
            self::assertStringContainsString('This link is no longer active', $page['body']);
            self::assertStringNotContainsString('Pay Ann', $page['body']);
            self::assertStringNotContainsString('Pay Bob', $page['body']);
        }
    }

    public function testTheAuditLogKeepsEachLinesPriorStateIncludingTheSignature(): void
    {
        $this->sendAllAndRespond();
        $this->withdraw('Wrong month imported.');

        $annMeta = json_decode((string) Database::instance()->value(
            "SELECT meta FROM audit_log WHERE action = 'payroll_line.withdrawn' AND entity_id = :id",
            ['id' => $this->lineIdFor($this->annId)]
        ), true);
        self::assertSame('confirmed', $annMeta['before']['status']);
        self::assertSame('Pay Ann Signed', $annMeta['before']['signed_name']);
        self::assertSame('10.1.1.1', $annMeta['before']['response_ip']);
        self::assertSame(self::SALARY, (string) $annMeta['before']['salary']);
        self::assertNotNull($annMeta['before']['responded_at']);
        self::assertNotNull($annMeta['before']['emailed_at']);

        $calMeta = (string) Database::instance()->value(
            "SELECT meta FROM audit_log WHERE action = 'payroll_line.withdrawn' AND entity_id = :id",
            ['id' => $this->lineIdFor($this->calId)]
        );
        self::assertStringContainsString('Absent is wrong.', $calMeta);

        $runMeta = json_decode((string) Database::instance()->value(
            "SELECT meta FROM audit_log WHERE action = 'payroll_run.withdrawn' AND entity_id = :id",
            ['id' => $this->runId]
        ), true);
        self::assertSame('Wrong month imported.', $runMeta['after']['reason']);
        self::assertSame(3, $runMeta['after']['lines']);
        self::assertSame(1, $runMeta['after']['confirmed']);
        self::assertSame(1, $runMeta['after']['query']);
        self::assertSame(1, $runMeta['after']['sent']);
    }

    public function testNothingToWithdrawWhenNoNoticeIsOut(): void
    {
        self::assertNull($this->withdraw(), 'nothing was ever sent');

        $this->send([$this->annId]);
        self::assertNotNull($this->withdraw());
        self::assertNull($this->withdraw(), 'a second withdrawal finds every line already a draft');
        self::assertSame(1, (int) Database::instance()->value(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'payroll_run.withdrawn' AND entity_id = :id",
            ['id' => $this->runId]
        ));
    }

    public function testFullReplaceNoteAndEditsAreAllowedAgainAfterAWithdrawal(): void
    {
        $this->sendAllAndRespond();
        $_SESSION = $this->sessionA();
        $runs = new PayrollRun();
        self::assertFalse($runs->updateNote($this->runId, 'Changed.'));
        try {
            $runs->import(self::MONTH, null, []);
            self::fail('a replace must be refused while notices are out');
        } catch (RuntimeException) {
            // Refused, as before PAY-3.
        }

        $this->withdraw();
        $_SESSION = $this->sessionA();

        // The line Ann had confirmed can be corrected now.
        self::assertTrue((new PayrollRunLine())->updateFigures($this->runId, $this->lineIdFor($this->annId), [
            'salary' => '40000.00',
            'absent_days' => '0.0',
            'working_days' => '26.0',
        ]));
        self::assertTrue($runs->updateNote($this->runId, 'Corrected figures.'));

        $result = PayrollImport::validate([
            1 => ['EmpID', 'Salary', 'Absent', 'WorkingDays'],
            2 => ['PAY-1', '41000', '0', '26'],
        ], $runs->branchEmployees());
        self::assertSame($this->runId, $runs->import(self::MONTH, 'Corrected.', $result->lines));
        self::assertSame('41000.00', (string) $this->line($this->annId)['salary']);
        self::assertSame([], $this->line($this->bobId), 'the old lines were replaced');
        foreach ($this->screenshotFiles as $path) {
            self::assertFileDoesNotExist($path, 'replacing lines removes their screenshots, as before');
        }
        self::assertNotNull(
            Database::instance()->value('SELECT withdrawn_at FROM payroll_runs WHERE id = :id', ['id' => $this->runId]),
            'the withdrawal survives the re-import'
        );
    }

    public function testNoticesSentAfterAWithdrawalSayTheyAreCorrections(): void
    {
        $before = new RecordingMailTransport();
        $this->send([$this->annId], $before);
        self::assertSame('Your salary notice for October 2026', $before->sent[0]->subject);
        self::assertStringNotContainsString('This replaces', $before->sent[0]->textBody);

        $this->withdraw();
        $mail = new RecordingMailTransport();
        $token = $this->send([$this->annId, $this->calId], $mail)[$this->annId];

        self::assertCount(2, $mail->sent, 'Cal was not emailed before, but every send after a withdrawal counts');
        foreach ($mail->sent as $message) {
            self::assertSame('Updated salary notice — October 2026', $message->subject);
            self::assertStringContainsString(
                'This replaces the salary notice we sent earlier for October 2026. '
                . 'Please review and confirm again. The earlier link no longer works.',
                $message->textBody
            );
        }

        $page = $this->viewNotice($token);
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Updated notice', $page['body']);
        self::assertStringContainsString(
            'This replaces the salary notice we sent earlier for October 2026.',
            $page['body']
        );
        // Decoded, not matched as text: MySQL 8's JSON type re-serialises with spaces and reorders keys.
        $sentMeta = json_decode((string) Database::instance()->value(
            "SELECT meta FROM audit_log WHERE action = 'payroll_line.sent' AND entity_id = :id
             ORDER BY id DESC LIMIT 1",
            ['id' => $this->lineIdFor($this->annId)]
        ), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($sentMeta['after']['correction'] ?? null);
    }

    public function testCorrectionWordingSurvivesAFullReimport(): void
    {
        $this->send([$this->annId]);
        $this->withdraw();
        $_SESSION = $this->sessionA();
        $runs = new PayrollRun();
        $result = PayrollImport::validate([
            1 => ['EmpID', 'Salary', 'Absent', 'WorkingDays'],
            2 => ['PAY-1', '41000', '0', '26'],
        ], $runs->branchEmployees());
        $runs->import(self::MONTH, null, $result->lines);
        $this->attachFakeScreenshot($this->lineIdFor($this->annId));

        $mail = new RecordingMailTransport();
        $this->send([$this->annId], $mail);
        self::assertSame('Updated salary notice — October 2026', $mail->sent[0]->subject);
    }

    public function testPreviewsShowTheCorrectionAfterAWithdrawal(): void
    {
        $this->send([$this->annId]);
        $this->withdraw();
        $annLineId = $this->lineIdFor($this->annId);

        $_SESSION = $this->sessionA();
        $annLine = (new PayrollRunLine())->findInRun($this->runId, $annLineId);
        self::assertNotNull($annLine);
        $preview = $this->sender(new RecordingMailTransport())->preview($annLine, self::MONTH);
        self::assertSame('Updated salary notice — October 2026', $preview->subject);
        self::assertStringContainsString('This replaces the salary notice we sent earlier', $preview->textBody);
        $_SESSION = [];

        $email = $this->runAs('previewEmail', $this->sessionA(), $this->runId, $annLineId);
        self::assertSame(200, $email['status']);
        self::assertStringContainsString('Updated salary notice — October 2026', $email['body']);
        $page = $this->runAs('previewNotice', $this->sessionA(), $this->runId, $annLineId);
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Updated notice', $page['body']);
        self::assertStringContainsString('This replaces the salary notice we sent earlier', $page['body']);
    }

    public function testWithdrawingThroughTheControllerNeedsAReason(): void
    {
        $token = $this->send([$this->annId])[$this->annId];

        foreach (['', '   ', str_repeat('x', 501)] as $reason) {
            $result = $this->postWithdraw($this->sessionA(), $reason);
            self::assertSame(302, $result['status'], 'a field error, not a 500');
            self::assertArrayHasKey('withdraw_reason', $result['session']['_flash']['errors'] ?? []);
            self::assertSame('sent', $this->line($this->annId)['status']);
            self::assertNotNull(PayrollRunLine::findByToken($token));
        }

        $done = $this->postWithdraw($this->sessionA(), 'Imported the September sheet by mistake');
        self::assertSame(302, $done['status']);
        self::assertSame('draft', $this->line($this->annId)['status']);

        $page = $this->runAs('show', $this->sessionA(), $this->runId);
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Withdrawn on', $page['body']);
        self::assertStringContainsString('by Pay pay-admin-a@test.local', $page['body']);
        self::assertStringContainsString('Imported the September sheet by mistake', $page['body']);
        self::assertStringContainsString('Correct the figures, then resend.', $page['body']);
        self::assertStringContainsString('id="run-note"', $page['body'], 'the note is editable again');
        self::assertStringNotContainsString('id="withdraw"', $page['body'], 'nothing left to withdraw');
    }

    public function testTheRunPageOffersTheWithdrawalWithItsConsequences(): void
    {
        $this->sendAllAndRespond();

        $page = $this->runAs('show', $this->sessionA(), $this->runId);
        self::assertStringContainsString('id="withdraw"', $page['body']);
        self::assertStringContainsString(
            'Every link already emailed for October 2026 stops working, including',
            $page['body']
        );
        self::assertStringContainsString('1 confirmed response', $page['body']);
        self::assertStringContainsString('name="withdraw_reason"', $page['body']);
        self::assertStringContainsString('/salary-notices/' . $this->runId . '/withdraw', $page['body']);
    }

    public function testAnotherBranchGets404AndNonAdminsGet403OnWithdraw(): void
    {
        $token = $this->send([$this->annId])[$this->annId];
        $partnerId = $this->insertUser('pay-partner@test.local', 'partner', $this->branchAId);
        $before = $this->stateSnapshot();

        self::assertSame(404, $this->postWithdraw($this->sessionB(), 'Not mine')['status']);
        self::assertSame(404, $this->postWithdraw($this->sessionA(), 'No such run', $this->runId + 100000)['status']);

        foreach ([$this->annId, $partnerId] as $userId) {
            $result = ControllerActionRunner::run(
                RouteDispatch::class,
                'dispatch',
                [
                    '_auth_user_id' => $userId,
                    '_active_branch_id' => $this->branchAId,
                    '_csrf_token' => 'pay-csrf',
                    '_csrf_issued_at' => time(),
                ],
                ['_csrf' => 'pay-csrf', 'withdraw_reason' => 'Trying'],
                [],
                'POST',
                ['uri' => '/salary-notices/' . $this->runId . '/withdraw']
            );
            self::assertSame(403, $result['status'], 'user ' . $userId);
        }

        self::assertSame($before, $this->stateSnapshot());
        self::assertNotNull(PayrollRunLine::findByToken($token));
    }

    /**
     * The withdrawal holds the run row and every line row locked until it
     * commits, so a concurrent withdrawal, re-import, send or answer waits
     * for it and then sees the drafts it left.
     */
    public function testAWithdrawalLocksTheRunAndItsLinesUntilItCommits(): void
    {
        $token = $this->send([$this->annId])[$this->annId];
        $other = $this->secondConnection();
        $lockQueries = [
            'SELECT id FROM payroll_runs WHERE id = ? FOR UPDATE NOWAIT' => $this->runId,
            'SELECT id FROM payroll_run_lines WHERE id = ? FOR UPDATE NOWAIT' => $this->lineIdFor($this->annId),
        ];

        Database::instance()->transaction(function () use ($other, $lockQueries): void {
            self::assertNotNull($this->withdraw());
            foreach ($lockQueries as $sql => $id) {
                try {
                    $other->prepare($sql)->execute([$id]);
                    self::fail('the row should be locked: ' . $sql);
                } catch (PDOException) {
                    // Locked until the withdrawal commits, as intended.
                }
            }
        });

        foreach ($lockQueries as $sql => $id) {
            self::assertTrue($other->prepare($sql)->execute([$id]), 'free once committed');
        }
        self::assertFalse(PayrollRunLine::respond($token, 'confirmed', 'Pay Ann', null, '1.1.1.1', 'x'));
        self::assertNull($this->withdraw(), 'a withdrawal that waited finds nothing left to withdraw');
    }

    private function secondConnection(): PDO
    {
        /** @var array{host:string,port:int,name:string,user:string,pass:string} $c */
        $c = Config::get('database', []);

        return new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['name']),
            $c['user'],
            $c['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    public function testDeletingTheBranchRemovesItsRuns(): void
    {
        Database::instance()->transaction(function (Database $db): void {
            PayrollRun::deleteForBranch($db, $this->branchAId);
        });

        self::assertSame(0, (int) Database::instance()->value(
            'SELECT COUNT(*) FROM payroll_runs WHERE branch_id = :b',
            ['b' => $this->branchAId]
        ));
        foreach ($this->screenshotFiles as $path) {
            self::assertFileDoesNotExist($path);
        }
    }
}
