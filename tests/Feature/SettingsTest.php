<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Services\Settings;
use PHPUnit\Framework\TestCase;
use Tests\Support\BranchFixture;

/**
 * Settings are read on nearly every page and decide how money is presented,
 * so the casting is worth pinning down.
 *
 * Settings is branch-scoped (multi-branch retrofit): each test gets its own
 * branch, seeded with the same defaults the app itself seeds for a new
 * install, rather than depending on whichever branch happens to be "Default"
 * in a given environment.
 */
final class SettingsTest extends TestCase
{
    private int $branchId;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->cleanUp();

        $this->branchId = BranchFixture::create('SET');
        $userId = Database::instance()->insert('users', [
            'name' => 'Settings Tester',
            'email' => 'settings-tester@test.local',
            'password_hash' => password_hash('unused-in-this-test', PASSWORD_DEFAULT),
            'role' => 'admin',
            'branch_id' => $this->branchId,
            'status' => 'active',
        ]);
        $_SESSION['_auth_user_id'] = $userId;
        $_SESSION['_active_branch_id'] = $this->branchId;

        $db = Database::instance();
        foreach (
            [
            ['company_name', 'LedgerHive', 'string'],
            ['currency_code', 'PKR', 'string'],
            ['currency_symbol', 'Rs', 'string'],
            ['fiscal_year_start', '7', 'int'],
            ['budget_alert_pct', '80', 'int'],
            ['approval_threshold', '0', 'decimal'],
            ] as [$key, $value, $type]
        ) {
            $db->insert('settings', [
                'branch_id' => $this->branchId,
                'setting_key' => $key,
                'setting_value' => $value,
                'value_type' => $type,
            ]);
        }

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
        $db->delete('settings', 'setting_key LIKE :k', ['k' => 'test\_%']);
        // Every settings row seeded for the fixture branch, not just the
        // 'test_' ones, must go before the branch itself can be deleted.
        $db->run(
            'DELETE s FROM settings s JOIN branches b ON b.id = s.branch_id WHERE b.name LIKE :n',
            ['n' => 'SET %']
        );
        $db->delete('users', 'email = :e', ['e' => 'settings-tester@test.local']);
        $db->delete('branches', 'name LIKE :n', ['n' => 'SET %']);
    }

    public function testSeededDefaultsExist(): void
    {
        self::assertSame('PKR', Settings::string('currency_code'));
        self::assertSame(7, Settings::int('fiscal_year_start'));
        self::assertSame(80, Settings::int('budget_alert_pct'));
    }

    public function testIntSettingsComeBackAsIntegers(): void
    {
        $value = Settings::get('fiscal_year_start');
        self::assertIsInt($value, 'an int setting must not leak as a string');
    }

    /**
     * Money must never round-trip through a float. A decimal setting stays a
     * string until something deliberately does arithmetic on it.
     */
    public function testDecimalSettingsStayStrings(): void
    {
        Settings::set('approval_threshold', '250000.50');
        Settings::flush();

        $value = Settings::get('approval_threshold');
        self::assertIsString($value);
        self::assertSame('250000.50', $value);
    }

    public function testMissingKeyReturnsDefault(): void
    {
        self::assertSame('fallback', Settings::string('test_absent_key', 'fallback'));
        self::assertSame(42, Settings::int('test_absent_key', 42));
    }

    public function testSetThenGetRoundTrips(): void
    {
        Settings::set('company_name', 'Hive Trading Co');
        Settings::flush();

        self::assertSame('Hive Trading Co', Settings::string('company_name'));

        // Leave the fixture as it was.
        Settings::set('company_name', 'LedgerHive');
    }
}
