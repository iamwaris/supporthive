<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Database;
use App\Services\AnthropicClient;
use App\Services\Settings;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\BranchFixture;

/**
 * AnthropicClient::createMessage() makes a real network call, so it is not
 * covered here. This only pins down forCurrentBranch()'s configuration gate
 * — AI must be both enabled and have a stored key before a client is built —
 * which is pure branch-scoped Settings logic and needs no network access.
 *
 * Mirrors the branch-per-test fixture pattern from SettingsTest.
 */
final class AnthropicClientTest extends TestCase
{
    private int $branchId;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->cleanUp();

        $this->branchId = BranchFixture::create('AIC');
        $userId = Database::instance()->insert('users', [
            'name' => 'Anthropic Client Tester',
            'email' => 'anthropic-client-tester@test.local',
            'password_hash' => password_hash('unused-in-this-test', PASSWORD_DEFAULT),
            'role' => 'admin',
            'branch_id' => $this->branchId,
            'status' => 'active',
        ]);
        $_SESSION['_auth_user_id'] = $userId;
        $_SESSION['_active_branch_id'] = $this->branchId;

        Settings::flush();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        $_SESSION = [];
        Settings::flush();
    }

    private function cleanUp(): void
    {
        $db = Database::instance();
        $db->run(
            'DELETE s FROM settings s JOIN branches b ON b.id = s.branch_id WHERE b.name LIKE :n',
            ['n' => 'AIC %']
        );
        $db->delete('users', 'email = :e', ['e' => 'anthropic-client-tester@test.local']);
        $db->delete('branches', 'name LIKE :n', ['n' => 'AIC %']);
    }

    public function testThrowsWhenNoKeyIsStoredAndAiIsDisabled(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AI is not configured for this branch.');

        AnthropicClient::forCurrentBranch();
    }

    public function testThrowsWhenKeyIsStoredButAiIsDisabled(): void
    {
        Settings::set('ai_anthropic_api_key_encrypted', 'ciphertext-does-not-matter-here');
        Settings::set('ai_enabled', '0');
        Settings::flush();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AI is not configured for this branch.');

        AnthropicClient::forCurrentBranch();
    }

    public function testThrowsWhenAiIsEnabledButNoKeyIsStored(): void
    {
        Settings::set('ai_enabled', '1');
        Settings::flush();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AI is not configured for this branch.');

        AnthropicClient::forCurrentBranch();
    }
}
