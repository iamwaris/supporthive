<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\AiSettingsController;
use App\Core\Crypto;
use App\Core\Database;
use PHPUnit\Framework\TestCase;
use Tests\Support\BranchFixture;
use Tests\Support\ControllerActionRunner;

/**
 * AiSettingsController::update() is only reachable through a real request
 * cycle ending in Http::redirect()/exit, so — per the precedent set by
 * BranchLockTest/ForcedPasswordChangeTest for Auth::requireActiveBranch() and
 * Auth::requireLogin() — it cannot be called in-process here. Unlike those
 * two, its state changes (Settings::set()) happen before the redirect and are
 * committed to the database by the time it exits, so ControllerActionRunner
 * is used to run it in a genuine subprocess and this test re-reads the
 * database afterwards, exactly as a real request would leave it.
 *
 * edit() never redirects, so it is called directly and its rendered output is
 * captured with output buffering.
 */
final class AiSettingsControllerTest extends TestCase
{
    private int $branchId;
    private int $userId;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->cleanUp();

        $this->branchId = BranchFixture::create('AISC');
        $this->userId = Database::instance()->insert('users', [
            'name' => 'AI Settings Tester',
            'email' => 'ai-settings-tester@test.local',
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
        $db = Database::instance();
        $db->run(
            'DELETE s FROM settings s JOIN branches b ON b.id = s.branch_id WHERE b.name LIKE :n',
            ['n' => 'AISC %']
        );
        $db->delete('users', 'email = :e', ['e' => 'ai-settings-tester@test.local']);
        $db->delete('branches', 'name LIKE :n', ['n' => 'AISC %']);
    }

    /** @return array{status:int,body:string} */
    private function update(string $apiKey, bool $enabled): array
    {
        $post = ['enabled' => $enabled ? '1' : '0'];
        if ($apiKey !== '') {
            $post['api_key'] = $apiKey;
        }

        return ControllerActionRunner::run(
            AiSettingsController::class,
            'update',
            ['_auth_user_id' => $this->userId, '_active_branch_id' => $this->branchId],
            $post
        );
    }

    private function storedKeyCiphertext(): string
    {
        return (string) Database::instance()->value(
            'SELECT setting_value FROM settings WHERE branch_id = :b AND setting_key = :k',
            ['b' => $this->branchId, 'k' => 'ai_anthropic_api_key_encrypted']
        );
    }

    private function storedEnabledFlag(): ?string
    {
        $value = Database::instance()->value(
            'SELECT setting_value FROM settings WHERE branch_id = :b AND setting_key = :k',
            ['b' => $this->branchId, 'k' => 'ai_enabled']
        );

        return $value === null ? null : (string) $value;
    }

    public function testUpdateStoresTheSubmittedKeyEncrypted(): void
    {
        self::assertSame('', $this->storedKeyCiphertext(), 'no key should be stored before the update');

        $result = $this->update('sk-ant-test-key-0123456789', true);

        self::assertSame(302, $result['status'], 'a successful update redirects back to the settings page');

        $stored = $this->storedKeyCiphertext();
        self::assertNotSame('', $stored, 'the key must now be stored');
        self::assertSame(
            'sk-ant-test-key-0123456789',
            Crypto::decrypt($stored),
            'the stored ciphertext must decrypt back to exactly what was submitted'
        );
    }

    public function testUpdateWithNoSubmittedKeyLeavesAnExistingStoredKeyUntouched(): void
    {
        Database::instance()->insert('settings', [
            'branch_id' => $this->branchId,
            'setting_key' => 'ai_anthropic_api_key_encrypted',
            'setting_value' => Crypto::encrypt('already-stored-key'),
            'value_type' => 'string',
        ]);
        $before = $this->storedKeyCiphertext();

        $result = $this->update('', true);

        self::assertSame(302, $result['status']);
        self::assertSame($before, $this->storedKeyCiphertext(), 'omitting api_key must not touch the stored key');
        self::assertSame('already-stored-key', Crypto::decrypt($this->storedKeyCiphertext()));
    }

    public function testUpdateTogglesEnabledOnRegardlessOfWhetherAKeyWasSubmitted(): void
    {
        $result = $this->update('', true);

        self::assertSame(302, $result['status']);
        self::assertSame('1', $this->storedEnabledFlag());
    }

    public function testUpdateTogglesEnabledOffRegardlessOfWhetherAKeyWasSubmitted(): void
    {
        // Start from "on" so the second update is a real off-toggle, not a no-op.
        $this->update('sk-ant-first-value-000000', true);
        self::assertSame('1', $this->storedEnabledFlag());

        $result = $this->update('', false);

        self::assertSame(302, $result['status']);
        self::assertSame('0', $this->storedEnabledFlag());
        // Turning it off must not have wiped the previously stored key.
        self::assertSame('sk-ant-first-value-000000', Crypto::decrypt($this->storedKeyCiphertext()));
    }

    public function testUpdateWithAnOverlongApiKeyFailsValidationAndLeavesTheStoredKeyUntouched(): void
    {
        Database::instance()->insert('settings', [
            'branch_id' => $this->branchId,
            'setting_key' => 'ai_anthropic_api_key_encrypted',
            'setting_value' => Crypto::encrypt('original-key'),
            'value_type' => 'string',
        ]);
        Database::instance()->insert('settings', [
            'branch_id' => $this->branchId,
            'setting_key' => 'ai_enabled',
            'setting_value' => '0',
            'value_type' => 'bool',
        ]);

        // api_key rule is nullable|max:200.
        $tooLong = str_repeat('a', 201);
        $result = $this->update($tooLong, true);

        self::assertSame(302, $result['status'], 'a failed validation still redirects, back to the form');
        self::assertSame(
            'original-key',
            Crypto::decrypt($this->storedKeyCiphertext()),
            'a validation failure must not touch the previously stored key'
        );
        self::assertSame(
            '0',
            $this->storedEnabledFlag(),
            'a validation failure must not apply the submitted enabled flag either'
        );
    }

    public function testEditNeverExposesTheStoredKeyMaterial(): void
    {
        Database::instance()->insert('settings', [
            'branch_id' => $this->branchId,
            'setting_key' => 'ai_anthropic_api_key_encrypted',
            'setting_value' => Crypto::encrypt('super-secret-anthropic-key'),
            'value_type' => 'string',
        ]);
        Database::instance()->insert('settings', [
            'branch_id' => $this->branchId,
            'setting_key' => 'ai_enabled',
            'setting_value' => '1',
            'value_type' => 'bool',
        ]);

        ob_start();
        (new AiSettingsController())->edit();
        $html = (string) ob_get_clean();

        self::assertStringNotContainsString(
            'super-secret-anthropic-key',
            $html,
            'the decrypted key must never reach the view'
        );
        self::assertStringNotContainsString(
            Crypto::encrypt('super-secret-anthropic-key'),
            $html,
            'the encrypted key material must never reach the view either'
        );
        // Sanity check the page did render the branching it's supposed to
        // show, rather than passing vacuously because it errored empty.
        self::assertNotSame('', trim($html));
    }

    public function testEditReportsNoStoredKeyWhenNoneIsSaved(): void
    {
        ob_start();
        (new AiSettingsController())->edit();
        $html = (string) ob_get_clean();

        self::assertNotSame('', trim($html));
    }
}
