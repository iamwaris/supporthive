<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Services\AiAvailability;
use App\Services\Settings;
use PHPUnit\Framework\TestCase;
use Tests\Support\BranchFixture;

/**
 * The AI assistant must stay off unless BOTH the per-branch switch is on and
 * a key is actually stored — either alone left on is how a branch with no
 * key configured (the seed migration's default state) would end up trying to
 * call the Anthropic API with nothing to send.
 */
final class AiAvailabilityTest extends TestCase
{
    private int $branchId;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->cleanUp();

        $this->branchId = BranchFixture::create('AIA');
        $userId = Database::instance()->insert('users', [
            'name' => 'AI Availability Tester',
            'email' => 'ai-availability-tester@test.local',
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
            ['n' => 'AIA %']
        );
        $db->delete('users', 'email = :e', ['e' => 'ai-availability-tester@test.local']);
        $db->delete('branches', 'name LIKE :n', ['n' => 'AIA %']);
    }

    private function seed(?string $enabled, ?string $keyCiphertext): void
    {
        $db = Database::instance();
        if ($enabled !== null) {
            $db->insert('settings', [
                'branch_id' => $this->branchId,
                'setting_key' => 'ai_enabled',
                'setting_value' => $enabled,
                'value_type' => 'bool',
            ]);
        }
        if ($keyCiphertext !== null) {
            $db->insert('settings', [
                'branch_id' => $this->branchId,
                'setting_key' => 'ai_anthropic_api_key_encrypted',
                'setting_value' => $keyCiphertext,
                'value_type' => 'string',
            ]);
        }
        Settings::flush();
    }

    public function testUnavailableWhenNeitherEnabledNorKeyed(): void
    {
        // Mirrors the seed migration's default: ai_enabled='0', key NULL.
        $this->seed('0', null);

        self::assertFalse(AiAvailability::enabledForCurrentBranch());
    }

    public function testUnavailableWhenKeyedButNotEnabled(): void
    {
        $this->seed('0', 'ciphertext-stand-in');

        self::assertFalse(AiAvailability::enabledForCurrentBranch());
    }

    public function testUnavailableWhenEnabledButNoKeyStored(): void
    {
        $this->seed('1', null);

        self::assertFalse(AiAvailability::enabledForCurrentBranch());
    }

    public function testAvailableWhenEnabledAndKeyed(): void
    {
        $this->seed('1', 'ciphertext-stand-in');

        self::assertTrue(AiAvailability::enabledForCurrentBranch());
    }
}
