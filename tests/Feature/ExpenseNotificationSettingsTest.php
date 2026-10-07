<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\SettingsController;
use App\Core\Database;
use App\Services\ExpenseNotifier;
use App\Services\Settings;
use PHPUnit\Framework\TestCase;
use Tests\Support\BranchFixture;
use Tests\Support\ControllerActionRunner;

/**
 * Saving the expense-notification settings through SettingsController::update():
 * a valid list is stored normalised, an invalid one is refused without
 * touching what was saved before. Who may reach the action at all is the
 * route's can:administer middleware, covered by AccessTest.
 */
final class ExpenseNotificationSettingsTest extends TestCase
{
    private int $branchId;
    private int $userId;

    protected function setUp(): void
    {
        $this->cleanUp();

        $this->branchId = BranchFixture::create('ENS');
        $this->userId = Database::instance()->insert('users', [
            'name' => 'ENS Admin',
            'email' => 'ens-admin@test.local',
            'password_hash' => password_hash('unused-in-this-test', PASSWORD_DEFAULT),
            'role' => 'admin',
            'branch_id' => $this->branchId,
            'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
    }

    private function cleanUp(): void
    {
        $db = Database::instance();
        $branch = ['n' => 'ENS %'];
        $db->run('DELETE x FROM audit_log x JOIN branches b ON b.id = x.branch_id WHERE b.name LIKE :n', $branch);
        $db->run('DELETE x FROM settings x JOIN branches b ON b.id = x.branch_id WHERE b.name LIKE :n', $branch);
        $db->delete('users', 'email = :e', ['e' => 'ens-admin@test.local']);
        $db->delete('branches', 'name LIKE :n', $branch);
    }

    /**
     * @param array<string,string> $notification
     * @return array{status:int,body:string}
     */
    private function save(array $notification): array
    {
        return ControllerActionRunner::run(
            SettingsController::class,
            'update',
            ['_auth_user_id' => $this->userId, '_active_branch_id' => $this->branchId],
            [
                'company_name' => 'ENS Co',
                'currency_code' => 'PKR',
                'currency_symbol' => 'Rs',
                'fiscal_year_start' => '7',
                'budget_alert_pct' => '80',
            ] + $notification
        );
    }

    /** @return array{value:string,type:string}|null */
    private function stored(string $key): ?array
    {
        $row = Database::instance()->first(
            'SELECT setting_value, value_type FROM settings WHERE branch_id = :b AND setting_key = :k',
            ['b' => $this->branchId, 'k' => $key]
        );

        return $row === null
            ? null
            : ['value' => (string) $row['setting_value'], 'type' => (string) $row['value_type']];
    }

    public function testValidListIsStoredDeduplicatedOnePerLine(): void
    {
        $result = $this->save([
            ExpenseNotifier::SETTING_ENABLED => '1',
            ExpenseNotifier::SETTING_RECIPIENTS => "owner@example.com, Owner@example.com;\npartner@example.com",
        ]);

        self::assertSame(302, $result['status']);
        self::assertSame(
            ['value' => "owner@example.com\npartner@example.com", 'type' => 'string'],
            $this->stored(ExpenseNotifier::SETTING_RECIPIENTS)
        );
        self::assertSame(['value' => '1', 'type' => 'bool'], $this->stored(ExpenseNotifier::SETTING_ENABLED));
    }

    public function testUntickedBoxStoresOff(): void
    {
        $this->save([ExpenseNotifier::SETTING_RECIPIENTS => 'owner@example.com']);

        self::assertSame(['value' => '0', 'type' => 'bool'], $this->stored(ExpenseNotifier::SETTING_ENABLED));
    }

    public function testSettingsPageShowsTheSavedListAndToggle(): void
    {
        $this->save([
            ExpenseNotifier::SETTING_ENABLED => '1',
            ExpenseNotifier::SETTING_RECIPIENTS => 'owner@example.com',
        ]);

        // index() returns normally (no redirect/exit), so it renders in-process.
        $_SESSION = ['_auth_user_id' => $this->userId, '_active_branch_id' => $this->branchId];
        Settings::flush();
        ob_start();
        try {
            (new SettingsController())->index();
        } finally {
            $html = (string) ob_get_clean();
            $_SESSION = [];
            Settings::flush();
        }

        self::assertMatchesRegularExpression(
            '/<textarea id="expense_notify_recipients"[^>]*>owner@example\.com<\/textarea>/',
            $html
        );
        self::assertMatchesRegularExpression('/name="expense_notify_enabled"[^>]*\s+checked/', $html);
        self::assertStringContainsString('<label for="expense_notify_recipients"', $html);
    }

    public function testInvalidAddressIsRefusedAndNothingIsSaved(): void
    {
        $this->save([
            ExpenseNotifier::SETTING_ENABLED => '1',
            ExpenseNotifier::SETTING_RECIPIENTS => 'owner@example.com',
        ]);

        $result = $this->save([
            ExpenseNotifier::SETTING_ENABLED => '1',
            ExpenseNotifier::SETTING_RECIPIENTS => "owner@example.com\nnot-an-email",
        ]);

        self::assertSame(302, $result['status']);
        self::assertSame('owner@example.com', $this->stored(ExpenseNotifier::SETTING_RECIPIENTS)['value'] ?? null);
    }
}
