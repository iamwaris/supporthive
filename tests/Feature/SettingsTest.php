<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Services\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Settings are read on nearly every page and decide how money is presented,
 * so the casting is worth pinning down.
 */
final class SettingsTest extends TestCase
{
    protected function setUp(): void
    {
        Settings::flush();
    }

    protected function tearDown(): void
    {
        Database::instance()->delete('settings', 'setting_key LIKE :k', ['k' => 'test\_%']);
        Settings::flush();
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
