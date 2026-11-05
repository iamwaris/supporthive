<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\PayrollController;
use App\Controllers\SalaryNoticeController;
use App\Core\Database;
use App\Core\RateLimiter;
use App\Models\PayrollRun;
use App\Models\PayrollRunLine;
use App\Services\Payroll\PayrollImport;
use App\Services\Mail\MailTransport;
use App\Services\Mail\NullMailer;
use App\Services\Payroll\PayrollNoticeSender;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\BranchFixture;
use Tests\Support\ControllerActionRunner;
use Tests\Support\RecordingMailTransport;

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
