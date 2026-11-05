<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\BranchController;
use App\Controllers\JoinController;
use App\Controllers\JoinerController;
use App\Core\Database;
use App\Core\RateLimiter;
use App\Models\EmployeeJoinLink;
use App\Models\EmployeeProfile;
use App\Services\BankDetails;
use PHPUnit\Framework\TestCase;
use Tests\Support\BranchFixture;
use Tests\Support\ControllerActionRunner;
use Tests\Support\RouteDispatch;

/**
 * The public new-joiner form and its admin side (JOIN-1).
 *
 * Public: a valid link shows the form; every link that does not work —
 * unknown, malformed, disabled, replaced, branch locked — shows the same
 * neutral page; a submission with bank details creates only a pending
 * joiner holding them (masked in every list, never in an audit row); designation, salary and Employee ID
 * cannot be smuggled in; honeypot, minimum time, rate limits and
 * array-shaped input are all handled without storing anything.
 *
 * Admin: the link is shown once and only its hash stored; approve needs an
 * Employee ID and creates the employee through the shared onboarding path
 * with the defaults and a first salary row; delete is a hard, POST-only
 * removal of a pending row whose audit entry carries nothing sensitive;
 * nothing crosses branches; deleting a branch removes both.
 */
final class JoinerOnboardingTest extends TestCase
{
    private const IBAN = 'PK36SCBL0000001123456702';
    private const ACCOUNT = '987654321012';
    private const BANK = 'Jnr Test Bank';
    private const SUPER_ADMIN_PASSWORD = 'jnr-super-admin-password';
    private const IP = '0.0.0.0';

    private int $branchAId;
    private int $branchBId;
    private int $adminAId;
    private int $adminBId;
    private string $token;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->cleanUp();

        $this->branchAId = BranchFixture::create('JNR-A');
        $this->branchBId = BranchFixture::create('JNR-B');
        $this->adminAId = $this->insertUser('jnr-admin-a@test.local', 'admin', $this->branchAId);
        $this->adminBId = $this->insertUser('jnr-admin-b@test.local', 'admin', $this->branchBId);

        $_SESSION = $this->sessionA();
        $this->token = (new EmployeeJoinLink())->regenerate();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        $_SESSION = [];
    }

    private function cleanUp(): void
    {
        $db = Database::instance();
        foreach (['joiner_submissions', 'employee_join_links', 'employee_salaries', 'audit_log'] as $table) {
            $db->run(
                'DELETE t FROM ' . $table . ' t JOIN branches b ON b.id = t.branch_id WHERE b.name LIKE :n',
                ['n' => 'JNR-%']
            );
        }
        $db->run(
            'DELETE a FROM audit_log a JOIN users u ON u.id = a.user_id WHERE u.email LIKE :e',
            ['e' => 'jnr-%@test.local']
        );
        $db->delete('users', 'email LIKE :e', ['e' => 'jnr-%@test.local']);
        $db->delete('branches', 'name LIKE :n', ['n' => 'JNR-%']);
        RateLimiter::clear('join-submit-ip:' . self::IP);
        RateLimiter::clear('join-view-ip:' . self::IP);
    }

    private function insertUser(string $email, string $role, ?int $branchId, string $password = 'unused'): int
    {
        return Database::instance()->insert('users', [
            'name' => 'Jnr ' . $email,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
            'branch_id' => $branchId,
            'status' => 'active',
        ]);
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

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function joinerPost(string $email = 'jnr-joiner@test.local', array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Jnr Joiner',
            'email' => $email,
            'phone' => '+92 300 1112223',
            'joining_date' => '2026-12-01',
            'bank_name' => self::BANK,
            'bank_account_number' => '9876-5432-1012',
            'bank_iban' => 'PK36 SCBL 0000 0011 2345 6702',
            JoinController::HONEYPOT => '',
        ];
    }

    /**
     * A joiner who opened the form a while ago (past the minimum time).
     *
     * @param array<string,mixed> $post
     * @param array<string,mixed>|null $session
     * @return array{status:int,body:string,session:array<string,mixed>}
     */
    private function submit(array $post, ?string $token = null, ?array $session = null): array
    {
        return ControllerActionRunner::run(
            JoinController::class,
            'submit',
            $session ?? ['_join_form_started_at' => time() - 30],
            $post,
            [],
            'POST',
            ['token' => $token ?? $this->token]
        );
    }

    /** @return array{status:int,body:string,session:array<string,mixed>} */
    private function view(string $token): array
    {
        return ControllerActionRunner::run(JoinController::class, 'show', [], [], [], 'GET', ['token' => $token]);
    }

    /**
     * @param array<string,mixed> $post
     * @param array<string,mixed>|null $session
     * @param array<string,string> $params
     * @return array{status:int,body:string,session:array<string,mixed>}
     */
    private function admin(
        string $method,
        array $post = [],
        array $params = [],
        ?array $session = null,
        string $httpMethod = 'POST'
    ): array {
        return ControllerActionRunner::run(
            JoinerController::class,
            $method,
            $session ?? $this->sessionA(),
            $post,
            [],
            $httpMethod,
            $params
        );
    }

    private function submissionCount(): int
    {
        return (int) Database::instance()->value(
            'SELECT COUNT(*) FROM joiner_submissions WHERE branch_id IN (:a, :b)',
            ['a' => $this->branchAId, 'b' => $this->branchBId]
        );
    }

    /** @return array<string,mixed> */
    private function onlySubmission(): array
    {
        $rows = Database::instance()->all(
            'SELECT * FROM joiner_submissions WHERE branch_id = :b',
            ['b' => $this->branchAId]
        );
        self::assertCount(1, $rows);

        return $rows[0];
    }

    private function submitValid(): int
    {
        self::assertSame(302, $this->submit($this->joinerPost())['status']);

        return (int) $this->onlySubmission()['id'];
    }

    /**
     * @param array<string,string> $overrides
     * @return array<string,string>
     */
    private function approvePost(array $overrides = []): array
    {
        return $overrides + [
            'employee_code' => 'JNR-100',
            'designation' => 'Fronter',
            'salary' => '35000',
            'email' => 'jnr-joiner@test.local',
            'joining_date' => '2026-12-01',
        ];
    }

    // --- The link ---------------------------------------------------------------

    public function testGeneratingShowsTheUrlOnceAndStoresOnlyItsHash(): void
    {
        $result = $this->admin('regenerateLink');

        self::assertSame(302, $result['status']);
        $url = (string) ($result['session']['_flash']['join_link_url'] ?? '');
        self::assertMatchesRegularExpression('#/join/[a-f0-9]{64}$#', $url);
        $token = substr($url, -64);

        $row = Database::instance()->first(
            'SELECT token_hash, is_active FROM employee_join_links WHERE branch_id = :b',
            ['b' => $this->branchAId]
        );
        self::assertSame(hash('sha256', $token), $row['token_hash'] ?? null);
        self::assertNotSame($token, $row['token_hash'] ?? null);
        self::assertSame(1, (int) ($row['is_active'] ?? 0));
        self::assertSame('1', (string) Database::instance()->value(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'join_link.regenerated' AND branch_id = :b",
            ['b' => $this->branchAId]
        ));

        // The page after shows it; nothing on the screen later can.
        $index = $this->admin('index', [], [], $this->sessionA() + ['_flash' => ['join_link_url' => $url]], 'GET');
        self::assertStringContainsString($url, $index['body']);
        $again = $this->admin('index', [], [], null, 'GET');
        self::assertStringNotContainsString($token, $again['body']);
        self::assertStringContainsString('Active', $again['body']);
    }

    public function testAValidLinkShowsTheFormWithoutTheAdminOnlyFields(): void
    {
        $result = $this->view($this->token);

        self::assertSame(200, $result['status']);
        self::assertStringContainsString('name="bank_iban"', $result['body']);
        self::assertStringContainsString(
            'name="hp_contact_ref" type="text" value="" tabindex="-1" autocomplete="off"',
            $result['body']
        );
        self::assertStringNotContainsString('name="website"', $result['body']);
        self::assertStringContainsString('noindex', $result['body']);
        foreach (['name="designation"', 'name="salary"', 'name="employee_code"', 'JNR-A'] as $absent) {
            self::assertStringNotContainsString($absent, $result['body']);
        }
        self::assertIsInt($result['session']['_join_form_started_at'] ?? null);
    }

    public function testUnknownMalformedDisabledReplacedAndLockedLinksAllShowTheSameNeutralPage(): void
    {
        $unknown = $this->view(bin2hex(random_bytes(32)));
        $malformed = $this->view('not-a-token');
        self::assertSame(404, $unknown['status']);
        self::assertStringContainsString('This link is no longer active', $unknown['body']);

        $this->admin('disableLink');
        $disabled = $this->view($this->token);

        $this->admin('enableLink');
        self::assertSame(200, $this->view($this->token)['status']);

        $_SESSION = $this->sessionA();
        $newToken = (new EmployeeJoinLink())->regenerate();
        $_SESSION = [];
        $replaced = $this->view($this->token);
        self::assertSame(200, $this->view($newToken)['status']);

        Database::instance()->update('branches', ['is_active' => 0], 'id = :id', ['id' => $this->branchAId]);
        $locked = $this->view($newToken);

        foreach ([$malformed, $disabled, $replaced, $locked] as $result) {
            self::assertSame(404, $result['status']);
            self::assertSame($unknown['body'], $result['body']);
        }
    }

    public function testSubmittingThroughADeadLinkStoresNothing(): void
    {
        $this->admin('disableLink');

        $result = $this->submit($this->joinerPost());

        self::assertSame(404, $result['status']);
        self::assertStringContainsString('This link is no longer active', $result['body']);
        self::assertSame(0, $this->submissionCount());
    }

    // --- Submitting -------------------------------------------------------------

    /**
     * Regression for the production 500 (2026-11-05): a submission with bank
     * details, sent through the real route table with CSRF and middleware,
     * reaches the thank-you redirect and stores them. Nothing on this path
     * may depend on the sodium extension or an encryption key any more.
     */
    public function testASubmissionWithBankDetailsSucceedsEndToEndThroughTheRouter(): void
    {
        $csrf = bin2hex(random_bytes(32));

        $result = ControllerActionRunner::run(
            RouteDispatch::class,
            'dispatch',
            ['_csrf_token' => $csrf, '_csrf_issued_at' => time(), '_join_form_started_at' => time() - 30],
            $this->joinerPost() + ['_csrf' => $csrf],
            [],
            'POST',
            ['uri' => '/join/' . $this->token]
        );

        self::assertSame(302, $result['status'], $result['body']);
        self::assertTrue($result['session']['_join_submitted'] ?? false);
        $row = $this->onlySubmission();
        self::assertSame('pending', $row['status']);
        self::assertSame(self::IBAN, $row['bank_iban']);
        self::assertSame(self::ACCOUNT, $row['bank_account_number']);
        self::assertSame(self::BANK, $row['bank_name']);
    }

    public function testAValidSubmissionWithBankDetailsCreatesOnlyAPendingJoinerHoldingThem(): void
    {
        $result = $this->submit($this->joinerPost());

        self::assertSame(302, $result['status']);
        self::assertTrue($result['session']['_join_submitted'] ?? false);

        $row = $this->onlySubmission();
        self::assertSame('pending', $row['status']);
        self::assertSame('Jnr Joiner', $row['name']);
        self::assertSame('jnr-joiner@test.local', $row['email']);
        self::assertSame('2026-12-01', $row['joining_date']);

        self::assertSame(self::IBAN, $row['bank_iban']);
        self::assertSame(self::ACCOUNT, $row['bank_account_number']);
        self::assertSame(self::BANK, $row['bank_name']);

        // The anonymous audit row says only that a submission arrived.
        $meta = (string) Database::instance()->value(
            "SELECT COALESCE(meta, '') FROM audit_log WHERE action = 'joiner.submitted' AND entity_id = :id",
            ['id' => $row['id']]
        );
        foreach ([self::IBAN, self::ACCOUNT, self::BANK] as $plain) {
            self::assertStringNotContainsString($plain, $meta);
        }

        // No login, no user, no employee.
        self::assertNull(Database::instance()->value(
            'SELECT id FROM users WHERE email = :e',
            ['e' => 'jnr-joiner@test.local']
        ));
        self::assertSame('1', (string) Database::instance()->value(
            "SELECT COUNT(*) FROM audit_log
             WHERE action = 'joiner.submitted' AND entity_id = :id AND branch_id = :b AND user_id IS NULL",
            ['id' => $row['id'], 'b' => $this->branchAId]
        ));
    }

    public function testDesignationSalaryEmployeeIdAndRoleCannotBeInjected(): void
    {
        $this->submit($this->joinerPost('jnr-joiner@test.local', [
            'designation' => 'Chief Executive',
            'salary' => '999999',
            'employee_code' => 'HACK-1',
            'role' => 'admin',
            'branch_id' => (string) $this->branchBId,
            'status' => 'approved',
        ]));

        $row = $this->onlySubmission();
        self::assertSame('pending', $row['status']);
        self::assertSame($this->branchAId, (int) $row['branch_id']);
        $raw = (string) json_encode($row);
        foreach (['Chief Executive', '999999', 'HACK-1'] as $injected) {
            self::assertStringNotContainsString($injected, $raw);
        }

        // Approval uses what the admin decides, never what the joiner posted.
        $this->admin('approve', $this->approvePost(), ['id' => (string) $row['id']]);
        $employee = Database::instance()->first(
            "SELECT u.role, p.designation, p.employee_code FROM users u JOIN employee_profiles p ON p.user_id = u.id
             WHERE u.email = 'jnr-joiner@test.local'"
        );
        self::assertSame(['role' => 'employee', 'designation' => 'Fronter', 'employee_code' => 'JNR-100'], $employee);
    }

    public function testTheHoneypotLooksLikeSuccessButStoresNothing(): void
    {
        $result = $this->submit(
            $this->joinerPost('jnr-joiner@test.local', [JoinController::HONEYPOT => 'http://spam.example'])
        );

        self::assertSame(302, $result['status']);
        self::assertTrue($result['session']['_join_submitted'] ?? false);
        self::assertSame(0, $this->submissionCount());
    }

    public function testSubmittingFasterThanAPersonCouldStoresNothing(): void
    {
        foreach ([['_join_form_started_at' => time()], []] as $session) {
            $result = $this->submit($this->joinerPost(), null, $session);

            self::assertSame(302, $result['status']);
            self::assertNotNull($result['session']['_flash']['error'] ?? null);
            self::assertArrayNotHasKey('_join_submitted', $result['session']);
        }
        self::assertSame(0, $this->submissionCount());
    }

    public function testSubmissionsAreRateLimitedPerIpAndPerToken(): void
    {
        for ($i = 0; $i < 20; $i++) {
            RateLimiter::hit('join-submit-ip:' . self::IP);
        }
        self::assertSame(429, $this->submit($this->joinerPost())['status']);
        self::assertSame(0, $this->submissionCount());

        RateLimiter::clear('join-submit-ip:' . self::IP);
        $tokenKey = 'join-submit-token:' . EmployeeJoinLink::hash($this->token);
        for ($i = 0; $i < 100; $i++) {
            RateLimiter::hit($tokenKey);
        }
        self::assertSame(429, $this->submit($this->joinerPost())['status']);
        self::assertSame(0, $this->submissionCount());
        RateLimiter::clear($tokenKey);
    }

    public function testViewingTheFormIsRateLimitedPerIp(): void
    {
        for ($i = 0; $i < 120; $i++) {
            RateLimiter::hit('join-view-ip:' . self::IP);
        }

        self::assertSame(429, $this->view($this->token)['status']);
    }

    public function testArrayShapedInputIsRejectedFieldByFieldWithoutAnError(): void
    {
        $result = $this->submit($this->joinerPost('jnr-joiner@test.local', [
            'name' => ['Jnr'],
            'email' => ['jnr-joiner@test.local'],
            'phone' => ['1'],
            'joining_date' => ['2026-12-01'],
            'bank_name' => ['x'],
            'bank_account_number' => ['123456'],
            'bank_iban' => [self::IBAN],
        ]));

        self::assertSame(302, $result['status']);
        self::assertEqualsCanonicalizing(
            ['name', 'email', 'phone', 'joining_date', 'bank_name', 'bank_account_number', 'bank_iban'],
            array_keys($result['session']['_flash']['errors'] ?? [])
        );
        self::assertSame(0, $this->submissionCount());

        // An array honeypot is a bot too: no error, nothing stored.
        $trap = $this->submit($this->joinerPost('jnr-joiner@test.local', [JoinController::HONEYPOT => ['x']]));
        self::assertSame(302, $trap['status']);
        self::assertSame(0, $this->submissionCount());
    }

    public function testAFailedSubmissionKeepsPersonalFieldsButNeverFlashesAccountNumberOrIban(): void
    {
        $result = $this->submit($this->joinerPost('not-an-email', ['bank_iban' => 'PK37SCBL0000001123456702']));

        self::assertSame(302, $result['status']);
        $errors = $result['session']['_flash']['errors'] ?? [];
        self::assertArrayHasKey('email', $errors);
        self::assertArrayHasKey('bank_iban', $errors);
        self::assertSame('Jnr Joiner', $result['session']['_old']['name'] ?? null);
        self::assertSame(self::BANK, $result['session']['_old']['bank_name'] ?? null);
        self::assertArrayNotHasKey('bank_iban', $result['session']['_old'] ?? []);
        self::assertArrayNotHasKey('bank_account_number', $result['session']['_old'] ?? []);
        self::assertSame(0, $this->submissionCount());
    }

    public function testTheThankYouPageAppearsOnlyRightAfterASubmission(): void
    {
        $thanks = ControllerActionRunner::run(
            JoinController::class,
            'submitted',
            ['_join_submitted' => true],
            [],
            [],
            'GET'
        );
        $stranger = ControllerActionRunner::run(JoinController::class, 'submitted', [], [], [], 'GET');

        self::assertSame(200, $thanks['status']);
        self::assertStringContainsString('Thank you', $thanks['body']);
        self::assertArrayNotHasKey('_join_submitted', $thanks['session']);
        self::assertSame(404, $stranger['status']);
        self::assertStringContainsString('This link is no longer active', $stranger['body']);
    }

    // --- Reviewing --------------------------------------------------------------

    public function testThePendingListMasksBankDetailsAndHasAnEmptyState(): void
    {
        $empty = $this->admin('index', [], [], null, 'GET');
        self::assertStringContainsString('No joiners waiting for review', $empty['body']);

        $this->submitValid();
        $list = $this->admin('index', [], [], null, 'GET');

        self::assertStringContainsString('Jnr Joiner', $list['body']);
        self::assertStringContainsString('****1012', $list['body']);
        self::assertStringNotContainsString(self::ACCOUNT, $list['body']);
        self::assertStringNotContainsString(self::IBAN, $list['body']);
    }

    public function testTheReviewPageShowsEverythingSubmittedWithTheDefaultsPrefilled(): void
    {
        $id = $this->submitValid();

        $page = $this->admin('show', [], ['id' => (string) $id], null, 'GET');

        self::assertSame(200, $page['status']);
        foreach ([self::IBAN, self::ACCOUNT, self::BANK, 'jnr-joiner@test.local', '+92 300 1112223'] as $shown) {
            self::assertStringContainsString($shown, $page['body']);
        }
        self::assertStringContainsString('value="Fronter"', $page['body']);
        self::assertStringContainsString('value="35000"', $page['body']);
    }

    public function testApprovalRequiresAnEmployeeId(): void
    {
        $id = $this->submitValid();

        $result = $this->admin('approve', $this->approvePost(['employee_code' => '']), ['id' => (string) $id]);

        self::assertSame(302, $result['status']);
        self::assertArrayHasKey('employee_code', $result['session']['_flash']['errors'] ?? []);
        self::assertSame('pending', $this->onlySubmission()['status']);
        self::assertNull(Database::instance()->value("SELECT id FROM users WHERE email = 'jnr-joiner@test.local'"));
    }

    public function testApprovalMovesTheBankDetailsSoTheSubmissionKeepsNoCopy(): void
    {
        $id = $this->submitValid();
        $before = $this->onlySubmission();

        $this->admin('approve', $this->approvePost(), ['id' => (string) $id]);

        $after = $this->onlySubmission();
        self::assertSame(self::IBAN, $before['bank_iban']);
        self::assertNull($after['bank_name']);
        self::assertNull($after['bank_account_number']);
        self::assertNull($after['bank_iban']);
        $employeeId = (int) $after['employee_user_id'];
        $profile = (array) Database::instance()->first(
            'SELECT bank_name, bank_account_number, bank_iban FROM employee_profiles WHERE user_id = :id',
            ['id' => $employeeId]
        );
        self::assertSame(
            ['bank_name' => self::BANK, 'bank_account_number' => self::ACCOUNT, 'bank_iban' => self::IBAN],
            $profile
        );

        $page = $this->admin('show', [], ['id' => (string) $id], null, 'GET');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Bank details moved to the employee record', $page['body']);
        self::assertStringContainsString('/employees/' . $employeeId . '#bank-details', $page['body']);
        self::assertStringNotContainsString(self::IBAN, $page['body']);
        self::assertStringNotContainsString(self::ACCOUNT, $page['body']);

        $list = $this->admin('index', [], [], null, 'GET');
        self::assertStringContainsString('No joiners waiting for review', $list['body']);
        $approved = ControllerActionRunner::run(
            RouteDispatch::class,
            'dispatch',
            $this->sessionA(),
            [],
            [],
            'GET',
            ['uri' => '/joiners?status=approved']
        );
        self::assertSame(200, $approved['status']);
        self::assertStringContainsString('Moved to employee', $approved['body']);
        self::assertStringNotContainsString('****', $approved['body']);
    }

    public function testBlockedSubmissionsAreCountedPerLinkAndShownToTheAdmin(): void
    {
        $this->submit($this->joinerPost('jnr-joiner@test.local', [JoinController::HONEYPOT => 'filled']));
        $this->submit($this->joinerPost(), null, ['_join_form_started_at' => time()]);
        $this->submit($this->joinerPost(), null, []);
        // A real submission is not counted.
        $this->submit($this->joinerPost());

        $count = static fn (int $branchId): int => (int) Database::instance()->value(
            'SELECT blocked_count FROM employee_join_links WHERE branch_id = :b',
            ['b' => $branchId]
        );
        self::assertSame(3, $count($this->branchAId));

        $panel = $this->admin('index', [], [], null, 'GET');
        self::assertStringContainsString('3 submissions blocked as automated', $panel['body']);

        // Per link: a regenerated link starts again from zero.
        $this->admin('regenerateLink');
        self::assertSame(0, $count($this->branchAId));
    }

    public function testRateLimitAndExpiredFormPagesUnderJoinAreNeutral(): void
    {
        $expired = ControllerActionRunner::run(
            RouteDispatch::class,
            'dispatch',
            [],
            $this->joinerPost(),
            [],
            'POST',
            ['uri' => '/join/' . $this->token]
        );

        for ($i = 0; $i < 120; $i++) {
            RateLimiter::hit('join-view-ip:' . self::IP);
        }
        $limited = ControllerActionRunner::run(
            RouteDispatch::class,
            'dispatch',
            [],
            [],
            [],
            'GET',
            ['uri' => '/join/' . $this->token]
        );

        self::assertSame(419, $expired['status']);
        self::assertStringContainsString('This page has expired', $expired['body']);
        self::assertSame(429, $limited['status']);
        self::assertStringContainsString('Too many attempts', $limited['body']);
        foreach ([$expired['body'], $limited['body']] as $body) {
            self::assertStringContainsString('<meta name="referrer" content="no-referrer">', $body);
            self::assertStringContainsString('noindex', $body);
            // None of the app's own error page: no brand, no way into the app.
            self::assertStringNotContainsString('Back to home', $body);
            self::assertStringNotContainsString('LedgerHive', $body);
            self::assertStringNotContainsString('JNR-A', $body);
        }
        self::assertSame(0, $this->submissionCount());
    }

    public function testApprovalCreatesTheEmployeeWithDefaultsFirstSalaryBankDetailsAndALogin(): void
    {
        $id = $this->submitValid();
        $submission = $this->onlySubmission();

        $result = $this->admin('approve', $this->approvePost(), ['id' => (string) $id]);

        self::assertSame(302, $result['status']);
        $notice = (string) ($result['session']['_flash']['success'] ?? '');
        self::assertStringContainsString('temporary password', $notice);

        $db = Database::instance();
        $user = $db->first("SELECT * FROM users WHERE email = 'jnr-joiner@test.local'");
        self::assertNotNull($user);
        self::assertSame('employee', $user['role']);
        self::assertSame($this->branchAId, (int) $user['branch_id']);
        self::assertSame(1, (int) $user['must_change_password']);

        $profile = (array) $db->first('SELECT * FROM employee_profiles WHERE user_id = :id', ['id' => $user['id']]);
        self::assertSame('Fronter', $profile['designation']);
        self::assertSame('JNR-100', $profile['employee_code']);
        self::assertSame('+92 300 1112223', $profile['phone']);
        self::assertSame(self::IBAN, $submission['bank_iban']);
        self::assertSame(self::IBAN, $profile['bank_iban']);
        self::assertSame(self::ACCOUNT, $profile['bank_account_number']);
        self::assertSame(self::BANK, $profile['bank_name']);

        $salaries = $db->all(
            'SELECT amount, effective_from FROM employee_salaries WHERE user_id = :id',
            ['id' => $user['id']]
        );
        self::assertCount(1, $salaries);
        self::assertSame('35000.00', (string) $salaries[0]['amount']);
        self::assertSame('2026-12-01', (string) $salaries[0]['effective_from']);

        $after = $this->onlySubmission();
        self::assertSame('approved', $after['status']);
        self::assertSame((int) $user['id'], (int) $after['employee_user_id']);
        self::assertSame($this->adminAId, (int) $after['reviewed_by']);
        self::assertSame('1', (string) $db->value(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'joiner.approved' AND entity_id = :id AND user_id = :u",
            ['id' => $id, 'u' => $this->adminAId]
        ));
        $createdMeta = (string) $db->value(
            "SELECT meta FROM audit_log WHERE action = 'employee.created' AND entity_id = :id",
            ['id' => $user['id']]
        );
        self::assertStringNotContainsString(self::IBAN, $createdMeta);
        self::assertStringNotContainsString(self::ACCOUNT, $createdMeta);
    }

    public function testWithoutAJoiningDateTheFirstSalaryStartsOnTheApprovalDay(): void
    {
        $id = $this->submitValid();

        $this->admin(
            'approve',
            $this->approvePost(['joining_date' => '', 'salary' => '36000.50']),
            ['id' => (string) $id]
        );

        $salary = Database::instance()->first(
            "SELECT s.amount, s.effective_from FROM employee_salaries s JOIN users u ON u.id = s.user_id
             WHERE u.email = 'jnr-joiner@test.local'"
        );
        self::assertSame('36000.50', (string) ($salary['amount'] ?? ''));
        self::assertSame(date('Y-m-d'), (string) ($salary['effective_from'] ?? ''));
    }

    public function testApprovalRefusesAnEmailOrEmployeeIdAlreadyInUse(): void
    {
        $id = $this->submitValid();
        $_SESSION = $this->sessionA();
        (new EmployeeProfile())->createWithLogin([
            'name' => 'Jnr Existing',
            'email' => 'jnr-existing@test.local',
            'phone' => null,
            'designation' => 'Fronter',
            'joining_date' => null,
            'employee_code' => 'JNR-100',
        ], password_hash('unused', PASSWORD_DEFAULT));
        $_SESSION = [];

        $codeTaken = $this->admin('approve', $this->approvePost(), ['id' => (string) $id]);
        $emailTaken = $this->admin(
            'approve',
            $this->approvePost(['employee_code' => 'JNR-101', 'email' => 'jnr-existing@test.local']),
            ['id' => (string) $id]
        );

        self::assertArrayHasKey('employee_code', $codeTaken['session']['_flash']['errors'] ?? []);
        self::assertArrayHasKey('email', $emailTaken['session']['_flash']['errors'] ?? []);
        self::assertSame('pending', $this->onlySubmission()['status']);
    }

    public function testDeletingAPendingJoinerRemovesTheRowAndAuditsOnlyNonSensitiveFacts(): void
    {
        $id = $this->submitValid();
        $submittedAt = (string) $this->onlySubmission()['created_at'];

        $result = $this->admin('destroy', [], ['id' => (string) $id]);

        self::assertSame(302, $result['status']);
        self::assertStringContainsString('deleted', (string) ($result['session']['_flash']['success'] ?? ''));
        self::assertSame(0, $this->submissionCount());
        self::assertNull(Database::instance()->value("SELECT id FROM users WHERE email = 'jnr-joiner@test.local'"));

        $meta = (string) Database::instance()->value(
            "SELECT meta FROM audit_log
             WHERE action = 'joiner.deleted' AND entity_type = 'joiner_submissions'
               AND entity_id = :id AND user_id = :u",
            ['id' => $id, 'u' => $this->adminAId]
        );
        self::assertNotSame('', $meta);
        $sensitive = [self::IBAN, self::ACCOUNT, self::BANK, '6702', '1012', 'bank_', 'jnr-joiner@', '1112223'];
        foreach ($sensitive as $secret) {
            self::assertStringNotContainsString($secret, $meta);
        }
        $decoded = json_decode($meta, true);
        self::assertIsArray($decoded);
        self::assertSame(['name' => 'Jnr Joiner', 'submitted_at' => $submittedAt], $decoded['before'] ?? null);
    }

    public function testOnlyAPendingJoinerCanBeDeleted(): void
    {
        $id = $this->submitValid();
        $this->admin('approve', $this->approvePost(), ['id' => (string) $id]);

        $late = $this->admin('destroy', [], ['id' => (string) $id]);

        self::assertSame(302, $late['status']);
        self::assertStringContainsString('already been reviewed', (string) ($late['session']['_flash']['error'] ?? ''));
        self::assertSame('approved', $this->onlySubmission()['status']);
        self::assertNotNull(Database::instance()->value("SELECT id FROM users WHERE email = 'jnr-joiner@test.local'"));
    }

    public function testAGetRequestCannotDeleteAJoiner(): void
    {
        $id = $this->submitValid();

        $result = ControllerActionRunner::run(
            RouteDispatch::class,
            'dispatch',
            $this->sessionA(),
            [],
            [],
            'GET',
            ['uri' => '/joiners/' . $id . '/delete']
        );

        self::assertSame(404, $result['status']);
        self::assertSame(1, $this->submissionCount());
        self::assertSame('0', (string) Database::instance()->value(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'joiner.deleted' AND entity_id = :id",
            ['id' => $id]
        ));
    }

    public function testThePendingPageOffersDeleteBehindAConfirmationThatWorksWithoutJavaScript(): void
    {
        $id = $this->submitValid();

        $page = $this->admin('show', [], ['id' => (string) $id], null, 'GET');

        self::assertMatchesRegularExpression(
            '#<details[^>]*>\s*<summary[^>]*>Delete this joiner</summary>.*?<form method="post" action="[^"]*/joiners/'
            . $id . '/delete"#s',
            $page['body']
        );
        self::assertStringNotContainsString('rejection_reason', $page['body']);
    }

    public function testAnotherBranchCanNeitherSeeNorActOnASubmission(): void
    {
        $id = $this->submitValid();
        $params = ['id' => (string) $id];

        self::assertSame(404, $this->admin('show', [], $params, $this->sessionB(), 'GET')['status']);
        self::assertSame(404, $this->admin('approve', $this->approvePost(), $params, $this->sessionB())['status']);
        self::assertSame(404, $this->admin('destroy', [], $params, $this->sessionB())['status']);
        self::assertSame(1, $this->submissionCount());
        $listB = $this->admin('index', [], [], $this->sessionB(), 'GET');
        self::assertStringNotContainsString('Jnr Joiner', $listB['body']);

        // Branch B's link management never touches branch A's link.
        $this->admin('disableLink', [], [], $this->sessionB());
        self::assertSame(200, $this->view($this->token)['status']);
        self::assertSame('pending', $this->onlySubmission()['status']);
    }

    public function testDeletingABranchRemovesItsJoinersAndItsLink(): void
    {
        $this->submitValid();
        $superAdminId = $this->insertUser('jnr-super@test.local', 'super_admin', null, self::SUPER_ADMIN_PASSWORD);
        $db = Database::instance();
        $branchName = (string) $db->value('SELECT name FROM branches WHERE id = :id', ['id' => $this->branchAId]);

        $status = ControllerActionRunner::run(
            BranchController::class,
            'destroy',
            ['_auth_user_id' => $superAdminId],
            ['confirm_name' => $branchName, 'confirm_password' => self::SUPER_ADMIN_PASSWORD],
            [],
            'POST',
            ['id' => (string) $this->branchAId]
        )['status'];

        self::assertSame(302, $status);
        self::assertSame(0, (int) $db->value(
            'SELECT COUNT(*) FROM branches WHERE id = :id',
            ['id' => $this->branchAId]
        ));
        self::assertSame(0, (int) $db->value(
            'SELECT COUNT(*) FROM joiner_submissions WHERE branch_id = :id',
            ['id' => $this->branchAId]
        ));
        self::assertSame(0, (int) $db->value(
            'SELECT COUNT(*) FROM employee_join_links WHERE branch_id = :id',
            ['id' => $this->branchAId]
        ));
    }
}
