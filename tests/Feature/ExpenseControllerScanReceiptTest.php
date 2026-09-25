<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\ExpenseController;
use App\Core\Crypto;
use App\Core\Database;
use PHPUnit\Framework\TestCase;
use Tests\Support\BranchFixture;
use Tests\Support\ControllerActionRunner;

/**
 * scanReceipt() ends in Http::abort()/Controller::json(), both `: never` and
 * both calling `exit`, so — same reasoning as AiSettingsControllerTest — it
 * is run through ControllerActionRunner rather than called in-process.
 *
 * Only the paths that short-circuit before any Anthropic network call are
 * covered here, matching how AnthropicClientTest already scopes itself away
 * from the real API call:
 *   - AI disabled for the branch -> 404, before any file handling runs at all.
 *   - AI enabled, no/invalid uploaded file -> 422, and the ordering proves
 *     the 404 gate really does run first (an AI-disabled branch never even
 *     gets to see the file-validation error).
 * The success path (a real receipt sent to a live Claude API and parsed back)
 * is NOT covered: AnthropicClient::forCurrentBranch()->createMessage() makes
 * a real HTTP call with no injectable transport seam, and adding one just for
 * this test would be exactly the kind of speculative abstraction CLAUDE.md
 * rules out.
 */
final class ExpenseControllerScanReceiptTest extends TestCase
{
    private int $branchId;
    private int $userId;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->cleanUp();

        $this->branchId = BranchFixture::create('ECSR');
        $this->userId = Database::instance()->insert('users', [
            'name' => 'Scan Receipt Tester',
            'email' => 'scan-receipt-tester@test.local',
            'password_hash' => password_hash('unused-in-this-test', PASSWORD_DEFAULT),
            'role' => 'admin',
            'branch_id' => $this->branchId,
            'status' => 'active',
        ]);
        $_SESSION['_auth_user_id'] = $this->userId;
        $_SESSION['_active_branch_id'] = $this->branchId;
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        $_SESSION = [];
    }

    private function cleanUp(): void
    {
        // rate_limits rows are left alone deliberately: each test run uses a
        // fresh, randomly-suffixed branch id (BranchFixture::create()), so a
        // leftover row here can never match another test's limiter key and
        // there is nothing to clean up before that branch exists.
        $db = Database::instance();
        $db->run(
            'DELETE s FROM settings s JOIN branches b ON b.id = s.branch_id WHERE b.name LIKE :n',
            ['n' => 'ECSR %']
        );
        $db->delete('users', 'email = :e', ['e' => 'scan-receipt-tester@test.local']);
        $db->delete('branches', 'name LIKE :n', ['n' => 'ECSR %']);
    }

    private function enableAiForBranch(): void
    {
        Database::instance()->insert('settings', [
            'branch_id' => $this->branchId,
            'setting_key' => 'ai_enabled',
            'setting_value' => '1',
            'value_type' => 'bool',
        ]);
        Database::instance()->insert('settings', [
            'branch_id' => $this->branchId,
            'setting_key' => 'ai_anthropic_api_key_encrypted',
            'setting_value' => Crypto::encrypt('unused-in-this-test'),
            'value_type' => 'string',
        ]);
    }

    /** @return array{status:int,body:string} */
    private function scanReceipt(array $files = []): array
    {
        return ControllerActionRunner::run(
            ExpenseController::class,
            'scanReceipt',
            ['_auth_user_id' => $this->userId, '_active_branch_id' => $this->branchId],
            [],
            $files
        );
    }

    public function testRespondsWith404WhenAiIsDisabledForTheBranch(): void
    {
        // No settings rows at all — the seed migration's default state, and
        // this test's setUp() does not call enableAiForBranch().
        $result = $this->scanReceipt();

        self::assertSame(404, $result['status'], 'a branch with AI off must not even reveal this endpoint exists');
    }

    public function testRespondsWith404EvenWithAWellFormedFileWhenAiIsDisabled(): void
    {
        // Proves the ordering: the 404 gate runs before file handling, so a
        // perfectly valid upload makes no difference when AI is off.
        $result = $this->scanReceipt([
            'receipt' => [
                'name' => 'receipt.jpg',
                'type' => 'image/jpeg',
                'tmp_name' => '/nonexistent/path/does-not-matter',
                'error' => UPLOAD_ERR_OK,
                'size' => 1024,
            ],
        ]);

        self::assertSame(404, $result['status']);
    }

    public function testRespondsWith422WhenNoFileIsUploadedAndAiIsEnabled(): void
    {
        $this->enableAiForBranch();

        $result = $this->scanReceipt();

        self::assertSame(422, $result['status']);
        $body = json_decode($result['body'], true);
        self::assertIsArray($body);
        self::assertArrayHasKey('error', $body);
    }

    public function testRespondsWith422WhenTheUploadFailed(): void
    {
        $this->enableAiForBranch();

        $result = $this->scanReceipt([
            'receipt' => [
                'name' => 'receipt.jpg',
                'type' => 'image/jpeg',
                'tmp_name' => '',
                'error' => UPLOAD_ERR_PARTIAL,
                'size' => 0,
            ],
        ]);

        self::assertSame(422, $result['status']);
    }

    public function testRespondsWith422ForAnUnwritableInvalidUploadPath(): void
    {
        $this->enableAiForBranch();

        // error=OK but tmp_name does not point at a real is_uploaded_file():
        // scanReceipt() must reject this rather than trust the client-supplied
        // path, since is_uploaded_file() is exactly the check that stops a
        // crafted $_FILES array from being trusted.
        $result = $this->scanReceipt([
            'receipt' => [
                'name' => 'receipt.jpg',
                'type' => 'image/jpeg',
                'tmp_name' => '/nonexistent/path/not-a-real-upload',
                'error' => UPLOAD_ERR_OK,
                'size' => 1024,
            ],
        ]);

        self::assertSame(422, $result['status']);
    }
}
