<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\AuthController;
use App\Controllers\BranchController;
use App\Controllers\EmployeeController;
use App\Controllers\UserController;
use App\Core\Auth;
use App\Core\Database;
use App\Core\RateLimiter;
use App\Core\RememberMe;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\BranchFixture;
use Tests\Support\ControllerActionRunner;

/**
 * "Remember me on this device" end to end, against the real database.
 *
 * The service layer (issue, consume/rotate, revoke, prune) and the request
 * glue (restoreSession(), logout, password change) run in-process: cookie
 * writes are mirrored into $_COOKIE, so the cookie a request leaves behind is
 * assertable even though the CLI never sends Set-Cookie headers. The Secure
 * decision is unit-tested in tests/Unit/RememberMeTokenTest.php; HttpOnly,
 * SameSite=Lax and Path=/ are constants in RememberMe::cookieOptions(), and
 * the Set-Cookie line itself is not observable from the CLI.
 *
 * Controller actions that end in Http::redirect()/exit go through
 * ControllerActionRunner in a separate process.
 */
final class RememberMeTest extends TestCase
{
    private const PASSWORD = 'remember-me-password-123';
    private const EMAIL = 'rmb-user@test.local';

    private int $branchId;
    private int $userId;

    protected function setUp(): void
    {
        $_SESSION = [];
        $_COOKIE = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->cleanUp();

        $this->branchId = BranchFixture::create('RMB');
        $this->userId = $this->createUser(self::EMAIL, 'admin');
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        $_SESSION = [];
        $_COOKIE = [];
        unset($_SERVER['REQUEST_METHOD']);
    }

    private function cleanUp(): void
    {
        $db = Database::instance();
        // remember_tokens rows go with their users (ON DELETE CASCADE).
        $db->delete('users', 'email LIKE :e', ['e' => 'rmb-%@test.local']);
        $db->run(
            'DELETE a FROM audit_log a JOIN branches b ON b.id = a.branch_id WHERE b.name LIKE :n',
            ['n' => 'RMB%']
        );
        $db->delete('branches', 'name LIKE :n', ['n' => 'RMB%']);
        RateLimiter::clear('login:' . self::EMAIL);
        RateLimiter::clear('login-ip:0.0.0.0');
    }

    private function createUser(string $email, string $role, int $mustChangePassword = 0): int
    {
        return Database::instance()->insert('users', [
            'name' => 'Remember ' . $role,
            'email' => $email,
            'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
            'role' => $role,
            'branch_id' => $this->branchId,
            'status' => 'active',
            'must_change_password' => $mustChangePassword,
        ]);
    }

    private function tokenCount(int $userId): int
    {
        return (int) Database::instance()->value(
            'SELECT COUNT(*) FROM remember_tokens WHERE user_id = :id',
            ['id' => $userId]
        );
    }

    /** Tokens that can still sign in: not yet consumed by a rotation. */
    private function liveTokenCount(int $userId): int
    {
        return (int) Database::instance()->value(
            'SELECT COUNT(*) FROM remember_tokens WHERE user_id = :id AND consumed_at IS NULL',
            ['id' => $userId]
        );
    }

    /** Backdate a token's consumed_at, as if its rotation happened $secondsAgo. */
    private function markConsumed(string $cookie, int $secondsAgo): void
    {
        Database::instance()->update(
            'remember_tokens',
            ['consumed_at' => date('Y-m-d H:i:s', time() - $secondsAgo)],
            'selector = :s',
            ['s' => RememberMe::parse($cookie)['selector'] ?? '']
        );
    }

    private function securityLogSince(int $offset): string
    {
        $file = STORAGE_PATH . '/logs/security-' . date('Y-m-d') . '.log';
        clearstatcache();
        if (!is_file($file)) {
            return '';
        }

        return (string) file_get_contents($file, false, null, $offset);
    }

    private function securityLogSize(): int
    {
        $file = STORAGE_PATH . '/logs/security-' . date('Y-m-d') . '.log';
        clearstatcache();

        return is_file($file) ? (int) filesize($file) : 0;
    }

    /** @return array<string,mixed>|null */
    private function tokenRow(string $cookie): ?array
    {
        $parts = RememberMe::parse($cookie);
        self::assertNotNull($parts);

        return Database::instance()->first(
            'SELECT * FROM remember_tokens WHERE selector = :s',
            ['s' => $parts['selector']]
        );
    }

    // --- Issuing -------------------------------------------------------------

    public function testIssueStoresOnlyTheHashOfTheValidator(): void
    {
        $cookie = RememberMe::issue($this->userId, 'Test Browser/1.0');
        $parts = RememberMe::parse($cookie);
        self::assertNotNull($parts);

        $row = $this->tokenRow($cookie);
        self::assertNotNull($row);
        self::assertSame(hash('sha256', $parts['validator']), $row['validator_hash']);
        foreach ($row as $column => $value) {
            self::assertStringNotContainsString(
                $parts['validator'],
                (string) $value,
                "the raw validator must never be stored (found in {$column})"
            );
        }
        self::assertSame('Test Browser/1.0', $row['user_agent']);

        $expiresIn = strtotime((string) $row['expires_at']) - time();
        self::assertGreaterThan(29 * 86400, $expiresIn);
        self::assertLessThanOrEqual(30 * 86400, $expiresIn);
    }

    public function testUserAgentIsTruncatedTo255Characters(): void
    {
        $row = $this->tokenRow(RememberMe::issue($this->userId, str_repeat('x', 600)));

        self::assertNotNull($row);
        self::assertSame(255, strlen((string) $row['user_agent']));
    }

    public function testLoginWithRememberTickedCreatesAToken(): void
    {
        $result = ControllerActionRunner::run(AuthController::class, 'login', [], [
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
            'remember' => '1',
        ]);

        self::assertSame(302, $result['status']);
        self::assertSame($this->userId, (int) ($result['session']['_auth_user_id'] ?? 0));
        self::assertSame(1, $this->tokenCount($this->userId));
        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            (string) Database::instance()->value(
                'SELECT validator_hash FROM remember_tokens WHERE user_id = :id',
                ['id' => $this->userId]
            )
        );
    }

    public function testLoginWithRememberUntickedCreatesNothing(): void
    {
        $result = ControllerActionRunner::run(AuthController::class, 'login', [], [
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
        ]);

        self::assertSame(302, $result['status']);
        self::assertSame($this->userId, (int) ($result['session']['_auth_user_id'] ?? 0));
        self::assertSame(0, $this->tokenCount($this->userId));
    }

    public function testArrayShapedRememberFieldIsRejectedLikeAnyBadInput(): void
    {
        $result = ControllerActionRunner::run(AuthController::class, 'login', [], [
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
            'remember' => ['1'],
        ]);

        self::assertSame(302, $result['status']);
        self::assertArrayNotHasKey('_auth_user_id', $result['session']);
        self::assertSame(0, $this->tokenCount($this->userId));
    }

    // --- Auto-login and rotation ---------------------------------------------

    public function testValidCookieSignsInAndRotatesSoTheOldCookieIsSpent(): void
    {
        $original = RememberMe::issue($this->userId, 'Browser');
        $_COOKIE[RememberMe::COOKIE] = $original;

        RememberMe::restoreSession();

        self::assertSame($this->userId, Auth::id());
        self::assertSame($this->branchId, Auth::branchId(), 'the session must be set up exactly as a password login');

        $rotated = $_COOKIE[RememberMe::COOKIE] ?? null;
        self::assertIsString($rotated);
        self::assertNotSame($original, $rotated);
        $spent = $this->tokenRow($original);
        self::assertNotNull($spent, 'the presented token is kept, so a later replay is recognisable');
        self::assertNotNull($spent['consumed_at'], 'the presented token is marked consumed');
        $replacement = $this->tokenRow($rotated);
        self::assertNotNull($replacement, 'a replacement token is issued');
        self::assertNull($replacement['consumed_at']);
        self::assertNotSame(
            RememberMe::parse($original)['selector'] ?? null,
            RememberMe::parse($rotated)['selector'] ?? null,
            'rotation issues a new selector as well as a new validator'
        );
        self::assertSame(1, $this->liveTokenCount($this->userId));
    }

    public function testSequentialDoubleConsumeFailsTheSecondTimeWithoutRevoking(): void
    {
        $cookie = RememberMe::issue($this->userId, 'Browser');

        $first = RememberMe::consume($cookie, 'Browser');
        self::assertNotNull($first);
        self::assertSame($this->userId, $first['user_id']);

        // Immediately again: inside the grace window, e.g. a second tab.
        self::assertNull(RememberMe::consume($cookie, 'Browser'), 'a cookie signs in exactly once');
        self::assertNotNull(
            $this->tokenRow($first['cookie']),
            'a replay inside the grace window must not sign the owner out everywhere'
        );
        self::assertSame(1, $this->liveTokenCount($this->userId));
    }

    public function testReplayInsideTheGraceWindowOnlyFails(): void
    {
        $other = RememberMe::issue($this->userId, 'Phone');
        $replayed = RememberMe::issue($this->userId, 'Laptop');
        $this->markConsumed($replayed, RememberMe::REPLAY_GRACE_SECONDS - 20);
        $_COOKIE[RememberMe::COOKIE] = $replayed;
        $logOffset = $this->securityLogSize();

        RememberMe::restoreSession();

        self::assertFalse(Auth::check());
        self::assertArrayNotHasKey(RememberMe::COOKIE, $_COOKIE);
        self::assertNotNull($this->tokenRow($other), 'nothing is revoked');
        self::assertStringNotContainsString('reused after rotation', $this->securityLogSince($logOffset));
    }

    public function testReplayAfterRotationRevokesEveryTokenAndLogsIt(): void
    {
        // The thief used the cookie first; it rotated, and the thief now
        // holds a live token. The owner's browser then presents the old one.
        $stolen = RememberMe::issue($this->userId, 'Laptop');
        $thief = RememberMe::consume($stolen, 'Thief');
        self::assertNotNull($thief);
        $otherDevice = RememberMe::issue($this->userId, 'Phone');
        $this->markConsumed($stolen, RememberMe::REPLAY_GRACE_SECONDS + 60);

        $bystander = $this->createUser('rmb-bystander@test.local', 'partner');
        $bystanderCookie = RememberMe::issue($bystander, 'Browser');

        $_COOKIE[RememberMe::COOKIE] = $stolen;
        $logOffset = $this->securityLogSize();

        RememberMe::restoreSession();

        self::assertFalse(Auth::check());
        self::assertArrayNotHasKey(RememberMe::COOKIE, $_COOKIE);
        self::assertSame(0, $this->tokenCount($this->userId), 'every token, the thief\'s included, is revoked');
        self::assertNull($this->tokenRow($thief['cookie']));
        self::assertNull($this->tokenRow($otherDevice));
        self::assertNotNull($this->tokenRow($bystanderCookie), 'other users are untouched');

        $log = $this->securityLogSince($logOffset);
        self::assertStringContainsString('reused after rotation', $log);
        self::assertStringContainsString('"user_id":' . $this->userId, $log);
        self::assertStringContainsString('"revoked_count":3', $log);
        foreach ([$stolen, $thief['cookie']] as $cookie) {
            $parts = RememberMe::parse($cookie);
            self::assertStringNotContainsString($parts['validator'] ?? '', $log, 'the token is never logged');
            self::assertStringNotContainsString($parts['selector'] ?? '', $log, 'the token is never logged');
        }
    }

    public function testHeadRequestAlsoRestores(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'HEAD';
        $_COOKIE[RememberMe::COOKIE] = RememberMe::issue($this->userId, 'Browser');

        RememberMe::restoreSession();

        self::assertSame($this->userId, Auth::id());
    }

    public function testLogoutNeverDeletesAnotherUsersToken(): void
    {
        $other = $this->createUser('rmb-other@test.local', 'partner');
        $othersToken = RememberMe::issue($other, 'Browser');
        $_SESSION['_auth_user_id'] = $this->userId;
        $_COOKIE[RememberMe::COOKIE] = $othersToken;

        Auth::logout();

        self::assertArrayNotHasKey(RememberMe::COOKIE, $_COOKIE, 'the cookie is still cleared from this browser');
        self::assertNotNull($this->tokenRow($othersToken), 'but another user\'s token row is not deleted');
    }

    public function testAutoLoginWritesTheSameAuditRowAsAPasswordLogin(): void
    {
        $_COOKIE[RememberMe::COOKIE] = RememberMe::issue($this->userId, 'Browser');

        RememberMe::restoreSession();

        $count = Database::instance()->value(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'auth.login' AND entity_id = :id AND branch_id = :b",
            ['id' => $this->userId, 'b' => $this->branchId]
        );
        self::assertSame(1, (int) $count);
    }

    public function testAutoLoginDoesNothingWhenAlreadySignedIn(): void
    {
        $other = $this->createUser('rmb-other@test.local', 'partner');
        $_SESSION['_auth_user_id'] = $this->userId;
        $cookie = RememberMe::issue($other, 'Browser');
        $_COOKIE[RememberMe::COOKIE] = $cookie;

        RememberMe::restoreSession();

        self::assertSame($this->userId, Auth::id());
        self::assertNotNull($this->tokenRow($cookie), 'an unused cookie is left untouched');
    }

    public function testAutoLoginNeverRunsOnAPost(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $cookie = RememberMe::issue($this->userId, 'Browser');
        $_COOKIE[RememberMe::COOKIE] = $cookie;

        RememberMe::restoreSession();

        self::assertFalse(Auth::check());
        self::assertSame($cookie, $_COOKIE[RememberMe::COOKIE]);
        self::assertNotNull($this->tokenRow($cookie));
    }

    // --- Failures ------------------------------------------------------------

    public function testExpiredTokenDoesNotSignInAndClearsTheCookie(): void
    {
        $cookie = RememberMe::issue($this->userId, 'Browser');
        $parts = RememberMe::parse($cookie);
        Database::instance()->update(
            'remember_tokens',
            ['expires_at' => date('Y-m-d H:i:s', time() - 60)],
            'selector = :s',
            ['s' => $parts['selector'] ?? '']
        );
        $_COOKIE[RememberMe::COOKIE] = $cookie;

        RememberMe::restoreSession();

        self::assertFalse(Auth::check());
        self::assertArrayNotHasKey(RememberMe::COOKIE, $_COOKIE);
        self::assertNull($this->tokenRow($cookie));
    }

    public function testUnknownSelectorDoesNotSignInAndClearsTheCookie(): void
    {
        $_COOKIE[RememberMe::COOKIE] = bin2hex(random_bytes(12)) . ':' . bin2hex(random_bytes(32));

        RememberMe::restoreSession();

        self::assertFalse(Auth::check());
        self::assertArrayNotHasKey(RememberMe::COOKIE, $_COOKIE);
    }

    public function testMalformedOrArrayCookieIsClearedWithoutError(): void
    {
        $_COOKIE[RememberMe::COOKIE] = 'not-a-token';
        RememberMe::restoreSession();
        self::assertArrayNotHasKey(RememberMe::COOKIE, $_COOKIE);

        $_COOKIE[RememberMe::COOKIE] = ['a' => 'b'];
        RememberMe::restoreSession();
        self::assertArrayNotHasKey(RememberMe::COOKIE, $_COOKIE);
        self::assertFalse(Auth::check());
    }

    public function testInactiveUserIsNotSignedIn(): void
    {
        $cookie = RememberMe::issue($this->userId, 'Browser');
        Database::instance()->update('users', ['status' => 'suspended'], 'id = :id', ['id' => $this->userId]);
        $_COOKIE[RememberMe::COOKIE] = $cookie;

        RememberMe::restoreSession();

        self::assertFalse(Auth::check());
        self::assertArrayNotHasKey(RememberMe::COOKIE, $_COOKIE);
        self::assertNull($this->tokenRow($cookie));
    }

    public function testLockedBranchIsNotSignedIn(): void
    {
        $cookie = RememberMe::issue($this->userId, 'Browser');
        Database::instance()->update('branches', ['is_active' => 0], 'id = :id', ['id' => $this->branchId]);

        self::assertNull(RememberMe::consume($cookie, 'Browser'));
        self::assertNull($this->tokenRow($cookie));
    }

    public function testValidatorMismatchRevokesEveryTokenForThatUser(): void
    {
        $other = $this->createUser('rmb-bystander@test.local', 'partner');
        $bystanderCookie = RememberMe::issue($other, 'Browser');

        $stolen = RememberMe::issue($this->userId, 'Laptop');
        RememberMe::issue($this->userId, 'Phone');
        self::assertSame(2, $this->tokenCount($this->userId));

        $parts = RememberMe::parse($stolen);
        $_COOKIE[RememberMe::COOKIE] = ($parts['selector'] ?? '') . ':' . bin2hex(random_bytes(32));

        RememberMe::restoreSession();

        self::assertFalse(Auth::check());
        self::assertArrayNotHasKey(RememberMe::COOKIE, $_COOKIE);
        self::assertSame(0, $this->tokenCount($this->userId), 'every device of the targeted user is signed out');
        self::assertNotNull($this->tokenRow($bystanderCookie), 'other users are untouched');
    }

    // --- Revocation ----------------------------------------------------------

    public function testLogoutRemovesThisDevicesToken(): void
    {
        $otherDevice = RememberMe::issue($this->userId, 'Phone');
        $_COOKIE[RememberMe::COOKIE] = RememberMe::issue($this->userId, 'Laptop');
        RememberMe::restoreSession();
        $current = $_COOKIE[RememberMe::COOKIE];

        Auth::logout();

        self::assertFalse(Auth::check());
        self::assertArrayNotHasKey(RememberMe::COOKIE, $_COOKIE);
        self::assertNull($this->tokenRow($current));
        self::assertNotNull($this->tokenRow($otherDevice), 'logout only ends this device');
    }

    public function testPasswordChangeRevokesEveryOtherDevice(): void
    {
        $otherDevice = RememberMe::issue($this->userId, 'Phone');
        $_SESSION['_auth_user_id'] = $this->userId;

        Auth::updatePassword('a-brand-new-password-456');

        self::assertNull($this->tokenRow($otherDevice));
        self::assertSame(0, $this->tokenCount($this->userId), 'a device that was not remembered does not become so');
    }

    public function testPasswordChangeOnARememberedDeviceKeepsOnlyThatDeviceRemembered(): void
    {
        $otherDevice = RememberMe::issue($this->userId, 'Phone');
        $thisDevice = RememberMe::issue($this->userId, 'Laptop');
        $_COOKIE[RememberMe::COOKIE] = $thisDevice;
        $_SESSION['_auth_user_id'] = $this->userId;

        Auth::updatePassword('a-brand-new-password-456');

        self::assertNull($this->tokenRow($otherDevice));
        self::assertNull($this->tokenRow($thisDevice), 'the pre-change token is revoked too');
        $reissued = $_COOKIE[RememberMe::COOKIE] ?? null;
        self::assertIsString($reissued);
        self::assertNotNull($this->tokenRow($reissued));
        self::assertSame(1, $this->tokenCount($this->userId));
    }

    public function testAdminPasswordResetDeactivationAndRoleChangeRevokeAllTokens(): void
    {
        $admin = $this->createUser('rmb-admin@test.local', 'admin');
        $session = ['_auth_user_id' => $admin, '_active_branch_id' => $this->branchId];

        RememberMe::issue($this->userId, 'Laptop');
        RememberMe::issue($this->userId, 'Phone');
        ControllerActionRunner::run(UserController::class, 'resetPassword', $session, [], [], 'POST', [
            'id' => (string) $this->userId,
        ]);
        self::assertSame(0, $this->tokenCount($this->userId), 'admin password reset');

        RememberMe::issue($this->userId, 'Laptop');
        ControllerActionRunner::run(UserController::class, 'update', $session, [
            'name' => 'Remember admin',
            'role' => 'partner',
        ], [], 'POST', ['id' => (string) $this->userId]);
        self::assertSame(0, $this->tokenCount($this->userId), 'role change');

        RememberMe::issue($this->userId, 'Laptop');
        ControllerActionRunner::run(UserController::class, 'update', $session, [
            'name' => 'Renamed only',
            'role' => 'partner',
        ], [], 'POST', ['id' => (string) $this->userId]);
        self::assertSame(1, $this->tokenCount($this->userId), 'a rename alone keeps the device remembered');

        ControllerActionRunner::run(UserController::class, 'toggleStatus', $session, [], [], 'POST', [
            'id' => (string) $this->userId,
        ]);
        self::assertSame('suspended', Database::instance()->value(
            'SELECT status FROM users WHERE id = :id',
            ['id' => $this->userId]
        ));
        self::assertSame(0, $this->tokenCount($this->userId), 'deactivation');
    }

    public function testEmployeeDeactivationAndPasswordResetRevokeAllTokens(): void
    {
        $admin = $this->createUser('rmb-admin@test.local', 'admin');
        $session = ['_auth_user_id' => $admin, '_active_branch_id' => $this->branchId];
        $employee = $this->createUser('rmb-employee@test.local', 'employee');
        Database::instance()->insert('employee_profiles', [
            'user_id' => $employee,
            'branch_id' => $this->branchId,
            'designation' => 'Tester',
        ]);

        RememberMe::issue($employee, 'Phone');
        $reset = ControllerActionRunner::run(EmployeeController::class, 'resetPassword', $session, [], [], 'POST', [
            'id' => (string) $employee,
        ]);
        self::assertSame(302, $reset['status']);
        self::assertSame(0, $this->tokenCount($employee), 'employee password reset');

        RememberMe::issue($employee, 'Phone');
        ControllerActionRunner::run(EmployeeController::class, 'toggleStatus', $session, [], [], 'POST', [
            'id' => (string) $employee,
        ]);
        self::assertSame(0, $this->tokenCount($employee), 'employee deactivation');
    }

    public function testDeletingABranchLeavesNoTokensForItsUsers(): void
    {
        $superAdmin = Database::instance()->insert('users', [
            'name' => 'Remember super',
            'email' => 'rmb-super@test.local',
            'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
            'role' => 'super_admin',
            'branch_id' => null,
            'status' => 'active',
        ]);
        RememberMe::issue($this->userId, 'Laptop');
        $consumed = RememberMe::issue($this->userId, 'Phone');
        $this->markConsumed($consumed, 5);
        $branchName = (string) Database::instance()->value(
            'SELECT name FROM branches WHERE id = :id',
            ['id' => $this->branchId]
        );

        $result = ControllerActionRunner::run(
            BranchController::class,
            'destroy',
            ['_auth_user_id' => $superAdmin],
            ['confirm_name' => $branchName, 'confirm_password' => self::PASSWORD],
            [],
            'POST',
            ['id' => (string) $this->branchId]
        );

        self::assertSame(302, $result['status']);
        self::assertNull(Database::instance()->value('SELECT id FROM users WHERE id = :id', ['id' => $this->userId]));
        self::assertSame(0, $this->tokenCount($this->userId), 'tokens go with their user (ON DELETE CASCADE)');
    }

    public function testCreateUserScriptRerunRevokesTokens(): void
    {
        RememberMe::issue($this->userId, 'Laptop');
        RememberMe::issue($this->userId, 'Phone');

        $process = proc_open(
            [
                PHP_BINARY,
                dirname(__DIR__, 2) . '/scripts/create-user.php',
                'Remember admin',
                self::EMAIL,
                'admin',
                (string) $this->branchId,
            ],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
            ['LEDGERHIVE_TESTING' => '1'] + getenv()
        );
        if ($process === false) {
            throw new RuntimeException('Could not start create-user.php.');
        }
        fwrite($pipes[0], "a-reset-password-789\n");
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, $stdout . $stderr);
        self::assertStringContainsString('Updated existing user', $stdout);
        self::assertSame(0, $this->tokenCount($this->userId));
    }

    public function testPruneDeletesExpiredTokensConsumedOrNot(): void
    {
        $live = RememberMe::issue($this->userId, 'Live');
        $expired = RememberMe::issue($this->userId, 'Expired');
        $consumedExpired = RememberMe::issue($this->userId, 'Consumed, expired');
        $consumedLive = RememberMe::issue($this->userId, 'Consumed, not yet expired');
        foreach ([$expired, $consumedExpired] as $cookie) {
            Database::instance()->update(
                'remember_tokens',
                ['expires_at' => date('Y-m-d H:i:s', time() - 1)],
                'selector = :s',
                ['s' => RememberMe::parse($cookie)['selector'] ?? '']
            );
        }
        $this->markConsumed($consumedExpired, 3600);
        $this->markConsumed($consumedLive, 3600);

        self::assertGreaterThanOrEqual(2, RememberMe::prune());
        self::assertNotNull($this->tokenRow($live));
        self::assertNull($this->tokenRow($expired));
        self::assertNull($this->tokenRow($consumedExpired));
        self::assertNotNull(
            $this->tokenRow($consumedLive),
            'a consumed row is kept until it expires, so a late replay is still detected'
        );
    }

    // --- Forced password change ----------------------------------------------

    public function testForcedPasswordChangeIsStillEnforcedAfterAutoLogin(): void
    {
        $forced = $this->createUser('rmb-forced@test.local', 'admin', 1);
        $cookie = RememberMe::issue($forced, 'Browser');

        $outcome = $this->runRequest($cookie, '/dashboard');

        self::assertSame($forced, $outcome['user_id'], 'the cookie signs the user in');
        self::assertSame(302, $outcome['status'], 'requireLogin() redirects instead of letting the request through');
        self::assertFalse($outcome['passed']);
        self::assertSame(1, (int) Database::instance()->value(
            'SELECT must_change_password FROM users WHERE id = :id',
            ['id' => $forced]
        ));

        // Control: the same flow for a user without the flag reaches the page.
        $normal = $this->runRequest(RememberMe::issue($this->userId, 'Browser'), '/dashboard');
        self::assertSame($this->userId, $normal['user_id']);
        self::assertTrue($normal['passed']);
    }

    /**
     * A GET request in a fresh process: restore from the cookie, then the
     * `auth` middleware's own check. requireLogin() exits on redirect, so the
     * outcome is read back from a shutdown function.
     *
     * @return array{status:int, user_id:?int, passed:bool}
     */
    private function runRequest(string $cookie, string $path): array
    {
        $resultFile = tempnam(sys_get_temp_dir(), 'shrmb');
        $scriptFile = tempnam(sys_get_temp_dir(), 'shrmbs');
        if ($resultFile === false || $scriptFile === false) {
            throw new RuntimeException('Could not create temp files.');
        }

        $bootstrap = var_export(dirname(__DIR__) . '/bootstrap.php', true);
        $cookieLiteral = var_export($cookie, true);
        $pathLiteral = var_export($path, true);
        $resultLiteral = var_export($resultFile, true);
        file_put_contents($scriptFile, <<<PHP
            <?php
            declare(strict_types=1);
            require {$bootstrap};
            \$_SESSION = [];
            \$_SERVER['REQUEST_METHOD'] = 'GET';
            \$_SERVER['REQUEST_URI'] = {$pathLiteral};
            \$_COOKIE[\\App\\Core\\RememberMe::COOKIE] = {$cookieLiteral};
            \$passed = false;
            register_shutdown_function(static function () use (&\$passed): void {
                file_put_contents({$resultLiteral}, json_encode([
                    'status' => http_response_code(),
                    'user_id' => \\App\\Core\\Auth::id(),
                    'passed' => \$passed,
                ]));
            });
            \\App\\Core\\RememberMe::restoreSession();
            \\App\\Core\\Auth::requireLogin();
            \$passed = true;
            PHP);

        $process = proc_open([PHP_BINARY, $scriptFile], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if ($process === false) {
            throw new RuntimeException('Could not start the subprocess.');
        }
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        unlink($scriptFile);

        $json = (string) file_get_contents($resultFile);
        unlink($resultFile);
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Subprocess produced no result.\n" . $stderr);
        }

        return [
            'status' => (int) $decoded['status'],
            'user_id' => isset($decoded['user_id']) ? (int) $decoded['user_id'] : null,
            'passed' => (bool) $decoded['passed'],
        ];
    }
}
